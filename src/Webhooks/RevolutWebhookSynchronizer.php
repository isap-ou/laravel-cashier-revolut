<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Webhooks;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\PendingRequest;
use Isapp\CashierRevolut\Enums\RevolutWebhookEvent;
use Isapp\CashierRevolut\Exceptions\RevolutApiException;
use Isapp\CashierRevolut\Http\Responses\OrderResponse;
use Isapp\CashierRevolut\Http\Responses\SubscriptionResponse;
use Isapp\CashierRevolut\Http\RevolutConnector;
use Isapp\CashierRevolut\RevolutGateway;
use Isapp\CashierSupport\DTO\Payment;
use Isapp\CashierSupport\DTO\WebhookPayload;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Events\PaymentFailed;
use Isapp\CashierSupport\Events\PaymentSucceeded;
use Isapp\CashierSupport\Events\SubscriptionCanceled;
use Isapp\CashierSupport\Events\SubscriptionCreated;
use Isapp\CashierSupport\Events\SubscriptionUpdated;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Models\Subscription as SubscriptionRecord;
use Psr\Log\LoggerInterface;

/**
 * Applies a verified Revolut webhook to local state.
 *
 * Subscription events refetch the subscription from the API (the webhook body
 * only carries ids) and update the local record, so state changed on Revolut's
 * side (dashboard cancellation, overdue billing) is mirrored. Completed
 * payment orders are persisted as local invoice records — the store behind the
 * gateway's local InvoiceOperations.
 *
 * Database writes are idempotent under retries and duplicate deliveries
 * (unique (provider, provider_id) index + absolute status writes), and typed
 * events are only dispatched on an actual transition — a redelivery does not
 * double-fire PaymentSucceeded/SubscriptionCanceled listeners. Deterministic
 * 404s from the refetch are acknowledged (logged, no retry storm); transient
 * failures bubble up as a 5xx so Revolut redelivers.
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

        try {
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
        } catch (RevolutApiException $exception) {
            if ($exception->statusCode !== 404) {
                throw $exception;
            }

            // A deterministic 404 would otherwise retry forever — acknowledge.
            $this->logger->warning('Revolut webhook references a missing resource', [
                'id' => $payload->id,
                'event' => $event->value,
            ]);
        }
    }

    /**
     * A connector request with a webhook-appropriate timeout: the sender's
     * delivery window is short, and a slow refetch invites concurrent
     * redeliveries.
     */
    private function request(): PendingRequest
    {
        return $this->connector->request()
            ->timeout(max(1, (int) $this->config->get('cashier-revolut.webhook.sync_timeout', 5)));
    }

    /**
     * Mirror the subscription's real state from the API onto the local record.
     */
    private function syncSubscription(RevolutWebhookEvent $event, string $subscriptionId): void
    {
        $model = Cashier::subscriptionModel(RevolutGateway::DRIVER);

        /** @var SubscriptionRecord|null $record */
        $record = $model::query()
            ->where('provider', RevolutGateway::DRIVER)
            ->where('provider_id', $subscriptionId)
            ->first();

        if ($record === null) {
            // No local record (e.g. created outside this app) — skip before
            // paying for an API round-trip.
            $this->logger->info('Revolut webhook for unknown local subscription', [
                'subscription_id' => $subscriptionId,
                'event' => $event->value,
            ]);

            return;
        }

        $response = SubscriptionResponse::from(
            $this->request()->get("/subscriptions/{$subscriptionId}")->json() ?? [],
        );

        $status = $response->status();
        $previousStatus = $record->status;

        $record->forceFill([
            'status' => $status,
            ...($response->trialEndDate !== null ? ['trial_ends_at' => $response->trialEndDate] : []),
            // Mirror truth: an active subscription has no end. When it IS
            // ending, keep an earlier recorded end (grace period) over now().
            'ends_at' => $status === SubscriptionStatus::Canceled
                ? ($record->ends_at ?? now())
                : null,
        ])->save();

        $owner = $record->owner()->first();

        if (! $owner instanceof Model || $previousStatus === $status) {
            return;
        }

        // Carry the grace period just written to the record: the Revolut
        // payload has no end date, and a listener revoking access reads the
        // DTO, not the row.
        $subscription = $response->toSubscription(
            (string) $record->getAttribute('type'),
            $record->ends_at,
        );

        event(match ($event) {
            RevolutWebhookEvent::SubscriptionInitiated => new SubscriptionCreated($owner, $subscription),
            RevolutWebhookEvent::SubscriptionCancelled,
            RevolutWebhookEvent::SubscriptionFinished => new SubscriptionCanceled($owner, $subscription),
            default => new SubscriptionUpdated($owner, $subscription),
        });
    }

    /**
     * Persist a completed payment order as a local invoice record and
     * dispatch the typed payment event exactly once per order.
     */
    private function syncOrder(RevolutWebhookEvent $event, string $orderId): void
    {
        $json = $this->request()->get("/orders/{$orderId}")->json();
        $order = OrderResponse::from($json ?? []);

        // Refunds and chargebacks are orders too — never book them as payments.
        if (! $order->isPaymentOrder()) {
            return;
        }

        $owner = $this->resolveOwner($order->customerId ?? $this->customerIdFromRaw($json));

        if ($owner === null) {
            $this->logger->info('Revolut order webhook without a resolvable billable owner', [
                'order_id' => $orderId,
                'event' => $event->value,
            ]);

            return;
        }

        $payment = $order->toPayment();

        if ($payment->status === PaymentStatus::Succeeded) {
            $model = Cashier::invoiceModel(RevolutGateway::DRIVER);

            $invoice = $model::query()->updateOrCreate(
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

            // Dispatch exactly once — redeliveries update the same record.
            if ($invoice->wasRecentlyCreated) {
                event(new PaymentSucceeded($owner, $payment));
            }

            return;
        }

        // A failure event whose refetch shows the order completed was handled
        // above; here the attempt genuinely failed. The order state after a
        // declined attempt often remains "pending", which would be a
        // contradictory payload — dispatch an explicit Failed payment.
        event(new PaymentFailed($owner, new Payment(
            id: $payment->id,
            amount: $payment->amount,
            currency: $payment->currency,
            status: PaymentStatus::Failed,
            createdAt: $payment->createdAt,
        )));
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
