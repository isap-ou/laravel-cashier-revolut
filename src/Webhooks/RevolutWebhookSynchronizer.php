<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Webhooks;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Isapp\CashierRevolut\Enums\RevolutWebhookEvent;
use Isapp\CashierRevolut\Http\Responses\OrderResponse;
use Isapp\CashierRevolut\Http\Responses\SubscriptionResponse;
use Isapp\CashierRevolut\Http\RevolutConnector;
use Isapp\CashierRevolut\Models\RevolutSubscription;
use Isapp\CashierRevolut\RevolutGateway;
use Isapp\CashierSupport\DTO\WebhookPayload;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Events\PaymentFailed;
use Isapp\CashierSupport\Events\PaymentSucceeded;
use Isapp\CashierSupport\Events\SubscriptionCanceled;
use Isapp\CashierSupport\Events\SubscriptionCreated;
use Isapp\CashierSupport\Events\SubscriptionUpdated;
use Isapp\CashierSupport\Facades\Cashier;
use Psr\Log\LoggerInterface;

/**
 * Applies a verified Revolut webhook to local state.
 *
 * Subscription events refetch the subscription from the API (the webhook body
 * only carries ids) and update the local record, so state changed on Revolut's
 * side (dashboard cancellation, overdue billing) is mirrored. Completed orders
 * are persisted as local invoice records — the store behind the gateway's
 * local InvoiceOperations. Typed support events are dispatched with the owning
 * billable when it can be resolved.
 *
 * All updates are idempotent (updateOrCreate / absolute status writes), so
 * Revolut's retries and duplicate deliveries are safe.
 */
class RevolutWebhookSynchronizer
{
    public function __construct(
        private readonly RevolutConnector $connector,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(WebhookPayload $payload): void
    {
        $raw = is_string($payload->data['event'] ?? null) ? $payload->data['event'] : '';
        $event = RevolutWebhookEvent::tryFrom($raw);

        if ($event === null || $payload->id === '') {
            return;
        }

        match ($event) {
            RevolutWebhookEvent::SubscriptionInitiated,
            RevolutWebhookEvent::SubscriptionFinished,
            RevolutWebhookEvent::SubscriptionCancelled,
            RevolutWebhookEvent::SubscriptionOverdue => $this->syncSubscription($event, $payload->id),
            RevolutWebhookEvent::OrderCompleted,
            RevolutWebhookEvent::OrderPaymentDeclined,
            RevolutWebhookEvent::OrderPaymentFailed,
            RevolutWebhookEvent::OrderFailed => $this->syncOrder($event, $payload->id),
        };
    }

    /**
     * Mirror the subscription's real state from the API onto the local record.
     */
    private function syncSubscription(RevolutWebhookEvent $event, string $subscriptionId): void
    {
        $response = SubscriptionResponse::from(
            $this->connector->request()->get("/subscriptions/{$subscriptionId}")->json() ?? [],
        );

        /** @var RevolutSubscription|null $record */
        $record = RevolutSubscription::query()
            ->where('provider', RevolutGateway::DRIVER)
            ->where('provider_id', $subscriptionId)
            ->first();

        if ($record === null) {
            $this->logger->info('Revolut webhook for unknown local subscription', [
                'subscription_id' => $subscriptionId,
                'event' => $event->value,
            ]);

            return;
        }

        $status = $response->status();

        $record->forceFill(array_filter([
            'status' => $status,
            'trial_ends_at' => $response->trialEndDate,
            'ends_at' => $status === SubscriptionStatus::Canceled
                ? ($record->ends_at ?? now())
                : null,
        ], static fn ($value): bool => $value !== null))->save();

        $owner = $record->owner()->first();

        if ($owner instanceof Model) {
            $subscription = $response->toSubscription((string) $record->getAttribute('name'));

            event(match ($event) {
                RevolutWebhookEvent::SubscriptionInitiated => new SubscriptionCreated($owner, $subscription),
                RevolutWebhookEvent::SubscriptionCancelled,
                RevolutWebhookEvent::SubscriptionFinished => new SubscriptionCanceled($owner, $subscription),
                default => new SubscriptionUpdated($owner, $subscription),
            });
        }
    }

    /**
     * Persist a completed order as a local invoice record and dispatch the
     * typed payment event.
     */
    private function syncOrder(RevolutWebhookEvent $event, string $orderId): void
    {
        $json = $this->connector->request()->get("/orders/{$orderId}")->json();
        $order = OrderResponse::from($json ?? []);
        $payment = $order->toPayment();

        $owner = $this->resolveOwner($order->customerId ?? $this->customerIdFromRaw($json));

        if ($owner === null) {
            $this->logger->info('Revolut order webhook without a resolvable billable owner', [
                'order_id' => $orderId,
                'event' => $event->value,
            ]);

            return;
        }

        if ($payment->status === PaymentStatus::Succeeded) {
            $model = Cashier::invoiceModel(RevolutGateway::DRIVER);

            $model::query()->updateOrCreate(
                ['provider' => RevolutGateway::DRIVER, 'provider_id' => $order->id],
                [
                    'owner_type' => $owner->getMorphClass(),
                    'owner_id' => $owner->getKey(),
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'status' => $payment->status,
                    'issued_at' => $payment->createdAt ?? now(),
                ],
            );

            event(new PaymentSucceeded($owner, $payment));

            return;
        }

        event(new PaymentFailed($owner, $payment));
    }

    /**
     * Resolve the billable model owning the given Revolut customer id via the
     * configured billable model class.
     */
    private function resolveOwner(?string $customerId): ?Model
    {
        $class = $this->config->get('cashier-revolut.billable_model');

        if ($customerId === null || ! is_string($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::query()->where('revolut_customer_id', $customerId)->first();
    }

    private function customerIdFromRaw(mixed $json): ?string
    {
        if (! is_array($json)) {
            return null;
        }

        $customer = $json['customer'] ?? null;
        $id = is_array($customer) ? ($customer['id'] ?? null) : ($json['customer_id'] ?? null);

        return is_string($id) ? $id : null;
    }
}
