<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Builders;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Isapp\CashierRevolut\Exceptions\RevolutApiException;
use Isapp\CashierRevolut\Http\Requests\CreateSubscriptionRequest;
use Isapp\CashierRevolut\Http\Responses\SubscriptionResponse;
use Isapp\CashierRevolut\Http\RevolutConnector;
use Isapp\CashierRevolut\Models\RevolutSubscription;
use Isapp\CashierRevolut\RevolutGateway;
use Isapp\CashierSupport\Contracts\SubscriptionBuilder;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\Exceptions\CustomerNotFoundException;
use Spatie\LaravelData\Exceptions\CannotCastDate;
use Spatie\LaravelData\Exceptions\CannotCreateData;

/**
 * Builds a Revolut subscription against the native Subscriptions API.
 *
 * The "price" is a Revolut plan variation id (plan → variation → phases).
 */
class RevolutSubscriptionBuilder implements SubscriptionBuilder
{
    private int $quantity = 1;

    private ?string $trialDuration = null;

    /**
     * @var array<string, mixed>
     */
    private array $metadata = [];

    public function __construct(
        private readonly RevolutConnector $connector,
        private readonly Model $billable,
        private readonly string $type,
        private readonly string $planVariationId,
    ) {}

    public function trialDays(int $days): static
    {
        $this->trialDuration = 'P'.max(0, $days).'D';

        return $this;
    }

    public function trialUntil(DateTimeInterface $date): static
    {
        $days = (int) ceil(max(0, $date->getTimestamp() - time()) / 86400);

        return $this->trialDays($days);
    }

    public function quantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function withMetadata(array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * {@inheritDoc}
     *
     * Note: $paymentMethod is not sent to Revolut. Per the Merchant API
     * (OpenAPI merchant-2025-12-04) a new subscription returns a setup order
     * that the customer pays via the Checkout Widget — the payment method is
     * chosen (and optionally saved) there, not at creation time.
     */
    public function create(?string $paymentMethod = null, array $options = []): Subscription
    {
        $request = new CreateSubscriptionRequest(
            customerId: $this->customerId(),
            planVariationId: $this->planVariationId,
            trialDuration: $this->trialDuration,
            quantity: $this->quantity,
            metadata: $this->metadata !== [] ? $this->metadata : null,
        );

        try {
            $subscription = SubscriptionResponse::from(
                $this->connector->request()->post('/subscriptions', array_merge($request->payload(), $options))->json() ?? [],
            )->toSubscription($this->type);
        } catch (ConnectionException $exception) {
            throw RevolutApiException::connectionFailed($exception);
        } catch (CannotCreateData|CannotCastDate $exception) {
            throw RevolutApiException::unexpectedPayload($exception);
        }

        $this->persist($subscription);

        return $subscription;
    }

    /**
     * {@inheritDoc}
     */
    public function add(array $options = []): Subscription
    {
        return $this->create(null, $options);
    }

    private function customerId(): string
    {
        $id = $this->billable->getAttribute('revolut_customer_id');

        if (! is_string($id) || $id === '') {
            throw CustomerNotFoundException::notCreated();
        }

        return $id;
    }

    private function persist(Subscription $subscription): void
    {
        RevolutSubscription::query()->updateOrCreate(
            ['provider' => RevolutGateway::DRIVER, 'provider_id' => $subscription->id],
            [
                'owner_type' => $this->billable->getMorphClass(),
                'owner_id' => $this->billable->getKey(),
                'name' => $this->type,
                'status' => $subscription->status,
                'trial_ends_at' => $subscription->trialEndsAt,
                'ends_at' => $subscription->endsAt,
            ],
        );
    }
}
