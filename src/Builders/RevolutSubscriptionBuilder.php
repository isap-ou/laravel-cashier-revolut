<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Builders;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Isapp\CashierRevolut\Concerns\PersistsRevolutPlanVariation;
use Isapp\CashierRevolut\Exceptions\RevolutApiException;
use Isapp\CashierRevolut\Http\Requests\CreateSubscriptionRequest;
use Isapp\CashierRevolut\Http\Responses\SubscriptionResponse;
use Isapp\CashierRevolut\Http\RevolutConnector;
use Isapp\CashierRevolut\RevolutGateway;
use Isapp\CashierSupport\Contracts\SubscriptionBuilder;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Gateway\ManagesCustomerRecords;
use Spatie\LaravelData\Exceptions\CannotCastDate;
use Spatie\LaravelData\Exceptions\CannotCreateData;
use TypeError;

/**
 * Builds a Revolut subscription against the native Subscriptions API.
 *
 * The "price" is a Revolut plan variation id (plan → variation → phases).
 */
class RevolutSubscriptionBuilder implements SubscriptionBuilder
{
    use ManagesCustomerRecords;
    use PersistsRevolutPlanVariation;

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

    /**
     * {@inheritDoc}
     *
     * Revolut has no per-subscription quantity: it lives on the plan variation's
     * items, fixed when the plan is created. Sell seats by creating a plan
     * variation that prices them.
     */
    public function quantity(int $quantity): static
    {
        throw UnsupportedOperationException::forCapability(Capability::SubscriptionQuantity);
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
        // $options is a passthrough to the API, so it is also a back door: it
        // would happily carry the very field quantity() refuses. Close it, or
        // the throw above is decoration.
        if (array_key_exists('quantity', $options)) {
            throw UnsupportedOperationException::forCapability(Capability::SubscriptionQuantity);
        }

        $request = new CreateSubscriptionRequest(
            customerId: $this->customerId(),
            planVariationId: $this->planVariationId,
            trialDuration: $this->trialDuration,
            metadata: $this->metadata !== [] ? $this->metadata : null,
        );

        try {
            $response = SubscriptionResponse::from(
                $this->connector->request()->post('/subscriptions', array_merge($request->payload(), $options))->json() ?? [],
            );
        } catch (ConnectionException $exception) {
            throw RevolutApiException::connectionFailed($exception);
        } catch (CannotCreateData|CannotCastDate|TypeError $exception) {
            throw RevolutApiException::unexpectedPayload($exception);
        }

        $subscription = $response->toSubscription($this->type);

        $this->persist($subscription, $response->planVariationId ?? $this->planVariationId);

        return $subscription;
    }

    /**
     * {@inheritDoc}
     */
    public function add(array $options = []): Subscription
    {
        return $this->create(null, $options);
    }

    protected function driverName(): string
    {
        return RevolutGateway::DRIVER;
    }

    private function customerId(): string
    {
        return $this->customerIdFor($this->billable);
    }

    private function persist(Subscription $subscription, ?string $planVariationId): void
    {
        $model = Cashier::subscriptionModel(RevolutGateway::DRIVER);

        $record = $model::query()->updateOrCreate(
            ['provider' => RevolutGateway::DRIVER, 'provider_id' => $subscription->id],
            [
                'owner_type' => $this->billable->getMorphClass(),
                'owner_id' => $this->billable->getKey(),
                'type' => $this->type,
                'status' => $subscription->status,
                'trial_ends_at' => $subscription->trialEndsAt,
                'ends_at' => $subscription->endsAt,
            ],
        );

        $this->persistPlanVariation($record, $planVariationId);
    }
}
