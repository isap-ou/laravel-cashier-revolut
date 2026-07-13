<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Isapp\CashierRevolut\Builders\RevolutSubscriptionBuilder;
use Isapp\CashierRevolut\Enums\RevolutChangePlanReason;
use Isapp\CashierRevolut\Http\Requests\ChangeSubscriptionPlanRequest;
use Isapp\CashierRevolut\Http\Responses\CycleResponse;
use Isapp\CashierRevolut\Http\Responses\SubscriptionResponse;
use Isapp\CashierRevolut\Models\RevolutSubscription;
use Isapp\CashierRevolut\RevolutGateway;
use Isapp\CashierSupport\Contracts\SubscriptionBuilder;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Events\SubscriptionUpdated;
use Isapp\CashierSupport\Exceptions\CashierException;
use Isapp\CashierSupport\Exceptions\SubscriptionUpdateFailure;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Facades\Cashier;

/**
 * Subscription lifecycle via the native Revolut Subscriptions API.
 *
 * Revolut has no pause/resume endpoints, so those throw
 * UnsupportedOperationException. Swapping a plan is supported, but not through
 * the update endpoint (which only covers external_reference) — it is a
 * separate command, POST /subscriptions/{id}/change-plan.
 */
trait ManagesRevolutSubscriptions
{
    use PersistsRevolutPlanVariation;

    /**
     * {@inheritDoc}
     */
    public function newSubscription(Model $billable, string $type, string|array $prices): SubscriptionBuilder
    {
        if (is_array($prices) && count($prices) !== 1) {
            throw new InvalidArgumentException('Revolut subscriptions accept exactly one plan variation id.');
        }

        $planVariationId = is_array($prices) ? (string) reset($prices) : $prices;

        if ($planVariationId === '') {
            throw new InvalidArgumentException('A Revolut plan variation id is required.');
        }

        return new RevolutSubscriptionBuilder($this->connector, $billable, $type, $planVariationId);
    }

    /**
     * {@inheritDoc}
     */
    public function cancelSubscription(Model $billable, string $type = 'default'): Subscription
    {
        $record = $this->subscriptionRecord($billable, $type);
        $id = $this->stringAttribute($record, 'provider_id');

        if ($id === null || $id === '') {
            throw new SubscriptionUpdateFailure("The [{$type}] subscription has no Revolut identifier.");
        }

        // Revolut's cancel takes effect immediately for future billing and
        // returns 204 — but the customer paid through the ACTIVE cycle's
        // end_date. Grab it before cancelling (the spec does not guarantee the
        // cycle survives cancellation) so the local record keeps a real
        // paid-through grace period instead of ending access instantly.
        [$subscription, $paidThrough] = $this->guardConnection(function () use ($id, $type): array {
            $current = SubscriptionResponse::from(
                $this->revolut()->get("/subscriptions/{$id}")->json() ?? [],
            );

            $paidThrough = null;

            if ($current->currentCycleId !== null) {
                $paidThrough = CycleResponse::from(
                    $this->revolut()->get("/subscriptions/{$id}/cycles/{$current->currentCycleId}")->json() ?? [],
                )->endDate;
            }

            $subscription = $this->cancelAndRefetch($id, $type);

            return [$subscription, $paidThrough];
        });

        $record->forceFill([
            'status' => $subscription->status,
            'ends_at' => $paidThrough ?? now(),
        ])->save();

        return $subscription;
    }

    /**
     * {@inheritDoc}
     *
     * Revolut cancellation only stops future billing cycles — there is no
     * immediate-cancel endpoint, so pretending otherwise would silently change
     * billing semantics. Use cancelSubscription() instead.
     */
    public function cancelSubscriptionNow(Model $billable, string $type = 'default'): Subscription
    {
        throw UnsupportedOperationException::forCapability(Capability::SubscriptionCancelNow);
    }

    /**
     * {@inheritDoc}
     */
    public function resumeSubscription(Model $billable, string $type = 'default'): Subscription
    {
        throw UnsupportedOperationException::forCapability(Capability::SubscriptionResume);
    }

    /**
     * {@inheritDoc}
     */
    public function pauseSubscription(Model $billable, string $type = 'default'): Subscription
    {
        throw UnsupportedOperationException::forCapability(Capability::SubscriptionPause);
    }

    /**
     * {@inheritDoc}
     *
     * The change is scheduled, not immediate: Revolut applies it at the end of
     * the current billing cycle. The customer stays on the old variation — and
     * keeps paying its price — until that cycle completes, and nothing is
     * prorated, so an upgrade does not grant access right away. A trial phase
     * on the target variation is skipped: trials only apply when a
     * subscription is first created.
     *
     * $options accepts:
     * - plan_variation_phase_id: which phase of the target variation to start
     *   from (defaults to the first)
     * - reason: a RevolutChangePlanReason (or its string value) — recorded by
     *   Revolut but does not affect processing
     *
     * @see https://developer.revolut.com/docs/api/merchant/operations/change-subscription-plan
     */
    public function swapSubscription(Model $billable, string $type, string|array $prices, array $options = []): Subscription
    {
        $planVariationId = $this->planVariationId($prices);

        $record = $this->subscriptionRecord($billable, $type);
        $id = $this->stringAttribute($record, 'provider_id');

        if ($id === null || $id === '') {
            throw new SubscriptionUpdateFailure("The [{$type}] subscription has no Revolut identifier.");
        }

        $request = new ChangeSubscriptionPlanRequest(
            planVariationId: $planVariationId,
            planVariationPhaseId: $this->phaseId($options),
            reason: $this->changeReason($options),
        );

        // The command is the commit point: on its 204 Revolut has scheduled the
        // change. Anything after this must not be able to report the swap as
        // failed.
        $this->guardConnection(
            fn () => $this->revolut()->post("/subscriptions/{$id}/change-plan", $request->payload()),
        );

        $response = $this->refetchSubscription($id);

        if ($response === null) {
            // The refetch failed, but the change IS scheduled. Reporting a
            // failure here would tell the customer their upgrade did not go
            // through, and then bill them for it at cycle end. The refetch
            // carries no local state we do not already hold — the plan change
            // is deferred, so it would still name the current variation — so
            // the local record is already correct and simply stands.
            $subscription = $this->localSubscription($record, $type);

            event(new SubscriptionUpdated($billable, $subscription));

            return $subscription;
        }

        $subscription = $response->toSubscription($type);

        DB::transaction(function () use ($record, $response, $subscription): void {
            // Only trust a state Revolut actually named. An absent or unknown
            // state maps to Incomplete, which would corrupt a healthy record.
            if ($response->subscriptionState() !== null) {
                $record->forceFill(['status' => $subscription->status])->save();
            }

            // Mirror whatever variation Revolut now reports — never the
            // requested one. The change is deferred, so until the cycle rolls
            // over Revolut still names the old variation, and so must we.
            $this->persistPlanVariation($record, $response->planVariationId);
        });

        event(new SubscriptionUpdated($billable, $subscription));

        return $subscription;
    }

    /**
     * Cancel (204 No Content) then refetch the subscription for its state.
     */
    private function cancelAndRefetch(string $id, string $type): Subscription
    {
        $this->revolut()->post("/subscriptions/{$id}/cancel");

        return SubscriptionResponse::from(
            $this->revolut()->get("/subscriptions/{$id}")->json() ?? [],
        )->toSubscription($type);
    }

    /**
     * Refetch a subscription, tolerating failure.
     *
     * Used after a command whose 204 already committed the change at Revolut,
     * where a failed read must not be reported as a failed write.
     */
    private function refetchSubscription(string $id): ?SubscriptionResponse
    {
        try {
            return $this->guardConnection(
                fn (): SubscriptionResponse => SubscriptionResponse::from(
                    $this->revolut()->get("/subscriptions/{$id}")->json() ?? [],
                ),
            );
        } catch (CashierException) {
            return null;
        }
    }

    /**
     * The support DTO for what the local record already knows.
     */
    private function localSubscription(RevolutSubscription $record, string $type): Subscription
    {
        return new Subscription(
            id: (string) $record->getAttribute('provider_id'),
            type: $type,
            status: $record->status,
            trialEndsAt: $record->trial_ends_at,
            endsAt: $record->ends_at,
        );
    }

    /**
     * The single plan variation id a swap targets.
     *
     * Both failures surface as SubscriptionUpdateFailure — the only checked
     * exception the swap contract declares, so a caller guarding a swap with
     * catch (SubscriptionUpdateFailure) never gets an unrelated throwable.
     *
     * @param  string|array<int, string>  $prices
     */
    private function planVariationId(string|array $prices): string
    {
        if (is_array($prices) && count($prices) !== 1) {
            throw new SubscriptionUpdateFailure('Revolut subscriptions accept exactly one plan variation id.');
        }

        $planVariationId = is_array($prices) ? (string) reset($prices) : $prices;

        if ($planVariationId === '') {
            throw SubscriptionUpdateFailure::invalidPrice($planVariationId);
        }

        return $planVariationId;
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws SubscriptionUpdateFailure When the phase id is not a usable string.
     */
    private function phaseId(array $options): ?string
    {
        $phaseId = $options['plan_variation_phase_id'] ?? null;

        if ($phaseId === null) {
            return null;
        }

        // Fail loudly rather than drop it: a silently omitted phase moves the
        // customer onto the variation's first phase, at a price they did not
        // ask for.
        if (! is_string($phaseId) || $phaseId === '') {
            throw new SubscriptionUpdateFailure('A Revolut plan variation phase id must be a non-empty string.');
        }

        return $phaseId;
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws SubscriptionUpdateFailure When the reason is not one Revolut accepts.
     */
    private function changeReason(array $options): ?RevolutChangePlanReason
    {
        $reason = $options['reason'] ?? null;

        if ($reason === null) {
            return null;
        }

        if ($reason instanceof RevolutChangePlanReason) {
            return $reason;
        }

        // Fail loudly: silently dropping a typo'd reason would ship a request
        // that Revolut accepts but that records nothing.
        return (is_string($reason) ? RevolutChangePlanReason::tryFrom($reason) : null)
            ?? throw new SubscriptionUpdateFailure('Unsupported Revolut plan change reason.');
    }

    private function subscriptionRecord(Model $billable, string $type): RevolutSubscription
    {
        $model = Cashier::subscriptionModel(RevolutGateway::DRIVER);

        /** @var RevolutSubscription|null $record */
        $record = $model::query()
            ->where('provider', RevolutGateway::DRIVER)
            ->where('owner_type', $billable->getMorphClass())
            ->where('owner_id', $billable->getKey())
            ->where('type', $type)
            ->latest()
            ->first();

        if ($record === null) {
            throw new SubscriptionUpdateFailure("No [{$type}] subscription found for the billable entity.");
        }

        return $record;
    }
}
