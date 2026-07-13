<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Builders;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use InvalidArgumentException;
use Isapp\CashierRevolut\Concerns\PersistsRevolutPlanVariation;
use Isapp\CashierRevolut\Exceptions\RevolutApiException;
use Isapp\CashierRevolut\Http\Requests\CreateSubscriptionRequest;
use Isapp\CashierRevolut\Http\Responses\SubscriptionResponse;
use Isapp\CashierRevolut\Http\RevolutConnector;
use Isapp\CashierRevolut\RevolutGateway;
use Isapp\CashierSupport\Contracts\SubscriptionBuilder;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Events\SubscriptionCreated;
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

    private ?string $externalReference = null;

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

    /**
     * {@inheritDoc}
     *
     * Revolut stores no metadata map on a subscription: the create body accepts
     * five fields and metadata is not one of them, and no subscription endpoint
     * returns it. The field used to be sent anyway — Revolut ignored it, and the
     * app's correlation data was silently dropped.
     *
     * What Revolut does offer is a single `external_reference` string, "to store
     * your own system's identifier for easy tracking and correlation". That is not
     * a metadata map, so it is not pretended to be one: use externalReference().
     *
     * @see https://developer.revolut.com/docs/api/merchant/operations/create-subscription.md
     */
    public function withMetadata(array $metadata): static
    {
        throw UnsupportedOperationException::forCapability(Capability::SubscriptionMetadata);
    }

    /**
     * The only body fields $options may carry.
     *
     * A denylist of the two fields the builder refuses (quantity, metadata) was
     * the wrong shape: $options is merged OVER the typed body, so it could also
     * overwrite customer_id or plan_variation_id — creating the subscription
     * against a different customer or plan than the local row records — and any
     * other undocumented key still travelled to the API to be ignored, which is
     * the very silent drop this change exists to end.
     *
     * So: only fields POST /api/subscriptions documents, and only the ones the
     * builder does not own itself.
     *
     * @see https://developer.revolut.com/docs/api/merchant/operations/create-subscription.md
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws UnsupportedOperationException When it carries a field Revolut has no concept of.
     * @throws InvalidArgumentException When it carries a field the create body does not define.
     */
    private function guardedOptions(array $options): array
    {
        if (array_key_exists('quantity', $options)) {
            throw UnsupportedOperationException::forCapability(Capability::SubscriptionQuantity);
        }

        if (array_key_exists('metadata', $options)) {
            throw UnsupportedOperationException::forCapability(Capability::SubscriptionMetadata);
        }

        $allowed = ['setup_order_redirect_url', 'external_reference', 'trial_duration'];
        $unknown = array_diff(array_keys($options), $allowed);

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'A Revolut subscription accepts no ['.implode(', ', $unknown).'] on create; beyond what the '
                .'builder sets, the body takes only ['.implode(', ', $allowed).'].',
            );
        }

        return $options;
    }

    /**
     * Store your own identifier on the Revolut subscription.
     *
     * The whole of Revolut's correlation surface: one string, writable on create,
     * returned on read, and the only field a subscription update accepts.
     */
    public function externalReference(string $reference): static
    {
        $this->externalReference = $reference;

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
        $options = $this->guardedOptions($options);

        $request = new CreateSubscriptionRequest(
            customerId: $this->customerId(),
            planVariationId: $this->planVariationId,
            trialDuration: $this->trialDuration,
            externalReference: $this->externalReference,
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

        // Announce it only if it is already live. Revolut creates a subscription
        // `pending`, with a setup order the customer still has to pay in the
        // Checkout Widget — announcing THAT would hand a listener a subscription
        // nobody has paid for, and if the customer closes the widget, Revolut sends
        // no webhook and nothing ever revokes the access. When the setup payment
        // lands, SUBSCRIPTION_INITIATED announces it instead.
        //
        // A subscription that comes back live (a trial, a plan with no setup order)
        // has no such webhook coming: the status never changes, so the synchronizer's
        // transition guard would swallow it and it would be announced nowhere.
        // isActive(), not "not pending": overdue, paused and cancelled are all
        // "not pending" too, and none of them is a subscription to announce as
        // freshly created.
        if ($subscription->status->isActive()) {
            event(new SubscriptionCreated($this->billable, $subscription));
        }

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
