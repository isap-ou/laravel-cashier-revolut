<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Concerns;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Isapp\CashierRevolut\Builders\RevolutSubscriptionBuilder;
use Isapp\CashierRevolut\Http\Responses\SubscriptionResponse;
use Isapp\CashierRevolut\Models\RevolutSubscription;
use Isapp\CashierRevolut\RevolutGateway;
use Isapp\CashierSupport\Contracts\SubscriptionBuilder;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\SubscriptionUpdateFailure;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;

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

        // Cancel returns 204 No Content per the OpenAPI spec — fetch the
        // subscription afterwards for its actual state.
        $subscription = $this->guardConnection(function () use ($id, $type): Subscription {
            $this->revolut()->post("/subscriptions/{$id}/cancel");

            return SubscriptionResponse::from(
                $this->revolut()->get("/subscriptions/{$id}")->json() ?? [],
            )->toSubscription($type);
        });

        $record->forceFill([
            'status' => $subscription->status,
            'ends_at' => $subscription->endsAt ?? now(),
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

    private function subscriptionRecord(Model $billable, string $type): RevolutSubscription
    {
        /** @var RevolutSubscription|null $record */
        $record = RevolutSubscription::query()
            ->where('provider', RevolutGateway::DRIVER)
            ->where('owner_type', $billable->getMorphClass())
            ->where('owner_id', $billable->getKey())
            ->where('name', $type)
            ->latest()
            ->first();

        if ($record === null) {
            throw new SubscriptionUpdateFailure("No [{$type}] subscription found for the billable entity.");
        }

        return $record;
    }
}
