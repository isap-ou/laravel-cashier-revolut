<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Isapp\CashierRevolut\Builders\RevolutSubscriptionBuilder;
use Isapp\CashierRevolut\Http\Responses\CycleResponse;
use Isapp\CashierRevolut\Http\Responses\SubscriptionResponse;
use Isapp\CashierRevolut\Models\RevolutSubscription;
use Isapp\CashierRevolut\RevolutGateway;
use Isapp\CashierSupport\Contracts\SubscriptionBuilder;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\SubscriptionUpdateFailure;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Facades\Cashier;

/**
 * Subscription lifecycle via the native Revolut Subscriptions API.
 *
 * Revolut has no pause/resume endpoints and its update only covers
 * external_reference, so pause/resume/swap throw UnsupportedOperationException.
 */
trait ManagesRevolutSubscriptions
{
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
        [$subscription, $endsAt] = $this->guardConnection(function () use ($id, $type): array {
            $current = SubscriptionResponse::from(
                $this->revolut()->get("/subscriptions/{$id}")->json() ?? [],
            );

            $paidThrough = null;

            if ($current->currentCycleId !== null) {
                $paidThrough = CycleResponse::from(
                    $this->revolut()->get("/subscriptions/{$id}/cycles/{$current->currentCycleId}")->json() ?? [],
                )->endDate;
            }

            $endsAt = $paidThrough ?? CarbonImmutable::now();

            // The returned DTO must carry the same grace period the record
            // gets. It is the contract's declared return type: an app that
            // renders the cancellation from it would otherwise tell the
            // customer access ended now, while they have paid through the cycle.
            $subscription = $this->cancelAndRefetch($id, $type, $endsAt);

            return [$subscription, $endsAt];
        });

        $record->forceFill([
            'status' => $subscription->status,
            'ends_at' => $endsAt,
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
     */
    public function swapSubscription(Model $billable, string $type, string|array $prices, array $options = []): Subscription
    {
        throw UnsupportedOperationException::forCapability(Capability::SubscriptionSwap);
    }

    /**
     * Cancel (204 No Content) then refetch the subscription for its state.
     *
     * $endsAt is passed through because Revolut's subscription resource has no
     * end date to map from — it lives on the billing cycle, fetched before the
     * cancellation.
     */
    private function cancelAndRefetch(string $id, string $type, ?CarbonImmutable $endsAt = null): Subscription
    {
        $this->revolut()->post("/subscriptions/{$id}/cancel");

        return SubscriptionResponse::from(
            $this->revolut()->get("/subscriptions/{$id}")->json() ?? [],
        )->toSubscription($type, $endsAt);
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
