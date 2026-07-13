<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Webhooks;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\PendingRequest;
use Isapp\CashierRevolut\Concerns\PersistsRevolutPlanVariation;
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
use Throwable;

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
    use PersistsRevolutPlanVariation;

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

        // Keep the variation mirrored here too — a belt-and-braces resync for
        // state changed on Revolut's side. The renewal itself is what lands a
        // scheduled plan change, and that arrives as ORDER_COMPLETED (see
        // syncOrder), not as a SUBSCRIPTION_* event. Runs before the
        // unchanged-status short-circuit so it is not skipped.
        $this->persistPlanVariation($record, $response->planVariationId);

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
     * Re-mirror the plan variation a subscription is billed on, after a new
     * cycle has been paid for — the moment a plan change scheduled
     * at_cycle_end actually lands.
     *
     * Best-effort by construction: the payment it follows is already booked,
     * so a failure here must never bubble up and cost the invoice or a webhook
     * retry storm. A missed resync is repaired by the next renewal or by any
     * SUBSCRIPTION_* sync.
     */
    private function syncSubscriptionPlan(Model $owner, string $subscriptionId): void
    {
        $model = Cashier::subscriptionModel(RevolutGateway::DRIVER);

        /** @var SubscriptionRecord|null $record */
        $record = $model::query()
            ->where('provider', RevolutGateway::DRIVER)
            ->where('provider_id', $subscriptionId)
            ->first();

        if ($record === null) {
            return;
        }

        try {
            $response = SubscriptionResponse::from(
                $this->request()->get("/subscriptions/{$subscriptionId}")->json() ?? [],
            );
        } catch (Throwable $exception) {
            $this->logger->warning('Revolut plan resync after a renewal failed', [
                'subscription_id' => $subscriptionId,
                'exception' => $exception->getMessage(),
            ]);

            return;
        }

        $previousPlan = $this->currentPlanVariation($record);

        $this->persistPlanVariation($record, $response->planVariationId);

        // The deferred change has landed: this — not the swap call that merely
        // scheduled it — is when the customer is actually on the new plan.
        //
        // A null previous plan is not a change, it is a first sighting: the row
        // is simply being written for the first time (a subscription created
        // before the driver recorded item rows, or one created outside the app).
        // Firing here would announce a plan change that never happened.
        if ($previousPlan !== null && $response->planVariationId !== null && $response->planVariationId !== $previousPlan) {
            event(new SubscriptionUpdated(
                $owner,
                $response->toSubscription((string) $record->getAttribute('type')),
            ));
        }
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

            // A completed cycle-billing order IS the renewal signal, and the
            // renewal is when a scheduled plan change takes effect (Revolut
            // fires no webhook for the change itself, and the SUBSCRIPTION_*
            // events do not fire on a normal renewal). Strictly after the
            // payment is booked: the plan resync costs an extra API call, and
            // money must never be lost because that call failed.
            if ($event === RevolutWebhookEvent::OrderCompleted && $order->subscriptionData?->isCycleBilling() === true) {
                $this->syncSubscriptionPlan($owner, $order->subscriptionData->subscriptionId);
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
