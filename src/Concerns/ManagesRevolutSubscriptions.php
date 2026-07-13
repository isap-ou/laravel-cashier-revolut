<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Concerns;

use Carbon\CarbonImmutable;
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
use Isapp\CashierSupport\Enums\SwapTiming;
use Isapp\CashierSupport\Events\SubscriptionPriceChangeScheduled;
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
        return new RevolutSubscriptionBuilder(
            $this->connector,
            $billable,
            $type,
            $this->planVariationId($prices),
        );
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
        [$subscription, $endsAt, $cycle] = $this->guardConnection(function () use ($id, $type): array {
            $current = SubscriptionResponse::from(
                $this->revolut()->get("/subscriptions/{$id}")->json() ?? [],
            );

            $cycle = null;

            if ($current->currentCycleId !== null) {
                $cycle = CycleResponse::from(
                    $this->revolut()->get("/subscriptions/{$id}/cycles/{$current->currentCycleId}")->json() ?? [],
                );
            }

            $paidThrough = $cycle?->endDate;

            $endsAt = $paidThrough ?? CarbonImmutable::now();

            // The returned DTO must carry the same grace period the record
            // gets. It is the contract's declared return type: an app that
            // renders the cancellation from it would otherwise tell the
            // customer access ended now, while they have paid through the cycle.
            $subscription = $this->cancelAndRefetch($id, $type, $endsAt, $cycle);

            return [$subscription, $endsAt, $cycle];
        });

        $record->forceFill([
            'status' => $subscription->status,
            'ends_at' => $endsAt,
            // A cancelled subscription will not renew, so a change scheduled for
            // the next cycle can never land. Advertising it would promise the
            // customer a plan they will never be moved to.
            'next_price' => null,
            'next_price_starts_at' => null,
            // The cycle was fetched for the grace period anyway; recording it as
            // the period the customer paid for is free, and it is what ends_at
            // is derived from rather than a second, drifting copy.
            //
            // Only when it is known: a cancelled subscription's cycle may be
            // gone, and that means "we could not look", not "there is no
            // period" — nulling one a webhook already recorded would lose it.
            ...($cycle !== null ? [
                'current_period_start' => $cycle->startDate,
                'current_period_end' => $cycle->endDate,
            ] : []),
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
    public function swapSubscription(
        Model $billable,
        string $type,
        string|array $prices,
        SwapTiming $timing = SwapTiming::Immediate,
        array $options = [],
    ): Subscription {
        // Revolut only ever schedules the change for the end of the cycle. The
        // support gate already refuses Immediate for this driver; this second
        // check is what keeps a direct provider call (bypassing Billable) from
        // silently deferring a change the caller asked to apply now.
        if ($timing !== SwapTiming::AtPeriodEnd) {
            throw new UnsupportedOperationException(
                'Revolut schedules a plan change at the end of the billing cycle; an immediate swap is not supported.',
            );
        }

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
            // The change IS scheduled, and the only thing we cannot do is read
            // back which variation Revolut recorded. Announce it with what we
            // asked for rather than staying silent about a change that will bill
            // the customer at cycle end.
            $subscription = $this->localSubscription($record, $type, pendingPrice: $planVariationId);

            $this->persistPendingPrice($record, $planVariationId, $record->current_period_end);

            event(new SubscriptionPriceChangeScheduled($billable, $subscription));

            return $subscription;
        }

        $cycle = $this->currentCycle($id, $response->currentCycleId);
        $subscription = $response->toSubscription($type, $record->ends_at, $cycle);

        // What Revolut says it scheduled, or — only when it reported no scheduled
        // action AT ALL — what we asked for. The distinction matters: an action
        // that IS reported and is not a plan change (a cancellation) means there
        // is no pending plan change, and substituting the requested variation
        // there would invent one. An absent action right after a 204 means "not
        // reported yet", and the change is committed either way.
        $pendingPrice = $response->pendingPlanVariationId()
            ?? ($response->scheduledAction === null ? $planVariationId : null);

        $subscription->pendingPrice = $pendingPrice;
        $subscription->pendingPriceStartsAt = $pendingPrice !== null
            ? ($subscription->pendingPriceStartsAt ?? $record->current_period_end)
            : null;

        DB::transaction(function () use ($record, $response, $subscription, $cycle): void {
            // Only trust a state Revolut actually named. An absent or unknown
            // state maps to Incomplete, which would corrupt a healthy record.
            if ($response->subscriptionState() !== null) {
                $record->forceFill(['status' => $subscription->status])->save();
            }

            // Only dates Revolut actually named. A cycle payload without them is
            // "not reported", and nulling a recorded paid-through date would lose
            // the answer to "when am I next billed?".
            $period = array_filter([
                'current_period_start' => $cycle?->startDate,
                'current_period_end' => $cycle?->endDate,
            ], static fn (mixed $value): bool => $value !== null);

            if ($period !== []) {
                $record->forceFill($period)->save();
            }

            // Mirror whatever variation Revolut now reports — never the
            // requested one. The change is deferred, so until the cycle rolls
            // over Revolut still names the old variation, and so must we.
            $this->persistPlanVariation($record, $response->planVariationId);

            // Revolut's own scheduled_action is the truth — if it scheduled
            // something other than what was asked for, the customer must be shown
            // what they will actually be moved to.
            $this->persistPendingPrice($record, $subscription->pendingPrice, $subscription->pendingPriceStartsAt);
        });

        // Not SubscriptionUpdated: nothing the customer is billed on has changed
        // yet. That event belongs to the moment the change lands, on the paid
        // renewal — a listener provisioning entitlements here would grant the new
        // plan a whole cycle early.
        event(new SubscriptionPriceChangeScheduled($billable, $subscription));

        return $subscription;
    }

    /**
     * The identifier your own system stored on the subscription.
     *
     * Revolut's whole correlation surface is this one string — there is no metadata
     * map — so it must be readable, or the data would be write-only and the drop
     * this driver set out to stop would simply move to the read side.
     *
     * @throws SubscriptionUpdateFailure When there is no such local subscription.
     */
    public function subscriptionExternalReference(Model $billable, string $type = 'default'): ?string
    {
        $record = $this->subscriptionRecord($billable, $type);
        $id = $this->stringAttribute($record, 'provider_id');

        if ($id === null || $id === '') {
            throw new SubscriptionUpdateFailure("The [{$type}] subscription has no Revolut identifier.");
        }

        return $this->guardConnection(
            fn (): ?string => SubscriptionResponse::from(
                $this->revolut()->get("/subscriptions/{$id}")->json() ?? [],
            )->externalReference,
        );
    }

    /**
     * Cancel (204 No Content) then refetch the subscription for its state.
     *
     * $endsAt is passed through because Revolut's subscription resource has no
     * end date to map from — it lives on the billing cycle, fetched before the
     * cancellation.
     */
    private function cancelAndRefetch(string $id, string $type, ?CarbonImmutable $endsAt = null, ?CycleResponse $cycle = null): Subscription
    {
        $this->revolut()->post("/subscriptions/{$id}/cancel");

        return SubscriptionResponse::from(
            $this->revolut()->get("/subscriptions/{$id}")->json() ?? [],
        )->toSubscription($type, $endsAt, $cycle);
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
    private function localSubscription(RevolutSubscription $record, string $type, ?string $pendingPrice = null): Subscription
    {
        return new Subscription(
            id: (string) $record->getAttribute('provider_id'),
            type: $type,
            status: $record->status,
            trialEndsAt: $record->trial_ends_at,
            endsAt: $record->ends_at,
            currentPeriodStart: $record->current_period_start,
            currentPeriodEnd: $record->current_period_end,
            pendingPrice: $pendingPrice,
            pendingPriceStartsAt: $pendingPrice !== null ? $record->current_period_end : null,
        );
    }

    /**
     * Record the plan variation the subscription will move to at cycle end.
     *
     * A null variation clears the pending change — what a landed (or withdrawn)
     * one looks like, and never a reason to keep advertising a move that is no
     * longer coming.
     *
     * A null date, however, is "we could not read the cycle", not "there is no
     * date": it leaves whatever date is already recorded alone. Nulling it would
     * turn a transient 404 on the cycle endpoint into "you'll move to Pro at some
     * unknown time".
     */
    private function persistPendingPrice(RevolutSubscription $record, ?string $planVariationId, ?CarbonImmutable $startsAt): void
    {
        $record->forceFill([
            'next_price' => $planVariationId,
            ...($planVariationId === null
                ? ['next_price_starts_at' => null]
                : ($startsAt !== null ? ['next_price_starts_at' => $startsAt] : [])),
        ])->save();
    }

    /**
     * The subscription's active billing cycle, tolerating failure.
     *
     * The period is enrichment: the plan change is already committed by the 204,
     * so a failed read here must not be reported as a failed swap.
     */
    private function currentCycle(string $subscriptionId, ?string $cycleId): ?CycleResponse
    {
        if ($cycleId === null || $cycleId === '') {
            return null;
        }

        try {
            return $this->guardConnection(
                fn (): CycleResponse => CycleResponse::from(
                    $this->revolut()->get("/subscriptions/{$subscriptionId}/cycles/{$cycleId}")->json() ?? [],
                ),
            );
        } catch (CashierException) {
            return null;
        }
    }

    /**
     * The single plan variation id a subscription runs on.
     *
     * A missing price, or more prices than Revolut bills a subscription on, is a
     * programmer error — the caller wrote the call wrong, and no retry or fallback
     * fixes it. It is not a SubscriptionUpdateFailure: that says the *subscription*
     * could not be updated, and catching one around a swap must not silently
     * swallow a bug in the call itself. The reference draws the same line —
     * laravel/cashier: "Please provide at least one price when swapping."
     *
     * @param  string|array<int, string>  $prices
     *
     * @throws InvalidArgumentException When no price, or more than one, is given.
     */
    private function planVariationId(string|array $prices): string
    {
        if (is_array($prices) && count($prices) !== 1) {
            throw new InvalidArgumentException('Revolut subscriptions accept exactly one plan variation id.');
        }

        $planVariationId = is_array($prices) ? (string) reset($prices) : $prices;

        if ($planVariationId === '') {
            throw new InvalidArgumentException('A Revolut plan variation id is required.');
        }

        return $planVariationId;
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidArgumentException When the phase id is not a usable string.
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
            throw new InvalidArgumentException('A Revolut plan variation phase id must be a non-empty string.');
        }

        return $phaseId;
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidArgumentException When the reason is not one Revolut accepts.
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
            ?? throw new InvalidArgumentException('Unsupported Revolut plan change reason.');
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
