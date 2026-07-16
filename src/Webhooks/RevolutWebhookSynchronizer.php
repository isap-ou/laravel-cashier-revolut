<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Webhooks;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\PendingRequest;
use Isapp\CashierRevolut\Concerns\PersistsRevolutPlanVariation;
use Isapp\CashierRevolut\Enums\RevolutWebhookEvent;
use Isapp\CashierRevolut\Exceptions\RevolutApiException;
use Isapp\CashierRevolut\Http\Responses\CycleResponse;
use Isapp\CashierRevolut\Http\Responses\OrderResponse;
use Isapp\CashierRevolut\Http\Responses\SubscriptionDataResponse;
use Isapp\CashierRevolut\Http\Responses\SubscriptionResponse;
use Isapp\CashierRevolut\Http\RevolutConnector;
use Isapp\CashierRevolut\RevolutGateway;
use Isapp\CashierSupport\DTO\Invoice;
use Isapp\CashierSupport\DTO\Payment;
use Isapp\CashierSupport\Enums\BillingReason;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Events\PaymentFailed;
use Isapp\CashierSupport\Events\PaymentSucceeded;
use Isapp\CashierSupport\Events\SubscriptionCanceled;
use Isapp\CashierSupport\Events\SubscriptionCreated;
use Isapp\CashierSupport\Events\SubscriptionPastDue;
use Isapp\CashierSupport\Events\SubscriptionRenewed;
use Isapp\CashierSupport\Events\SubscriptionUpdated;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Gateway\ManagesCustomerRecords;
use Isapp\CashierSupport\Models\Invoice as InvoiceRecord;
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
    use ManagesCustomerRecords;
    use PersistsRevolutPlanVariation;

    public function __construct(
        private readonly RevolutConnector $connector,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Apply a verified Revolut webhook body to local state.
     *
     * Takes the raw decoded body, which is all it ever really wanted: it used to take a
     * DTO\WebhookPayload and then dig `$payload->data['event']` — a Revolut-native key —
     * back out of a field the DTO called "provider-agnostic event data" (support#46). It
     * never once read the agnostic `$payload->event` beside it. The DTO is gone and this
     * reads the body directly, which is honest: a Revolut-native key in a Revolut body.
     *
     * @param  array<string, mixed>  $body
     * @return bool True when the event was applied; false when this driver does not map
     *              it, or maps it but the body names no resource to apply it to. Support's
     *              controller dispatches WebhookHandled only for true.
     *
     * @throws RevolutApiException When the refetch fails for any reason but a 404.
     */
    public function handle(array $body): bool
    {
        $raw = is_string($body['event'] ?? null) ? $body['event'] : '';
        $event = RevolutWebhookEvent::tryFrom($raw);
        $id = $this->resourceId($body);

        if ($event === null || $id === '') {
            // The rule that replaced the ordering: never throw here. Revolut documents 22
            // event types and this driver maps 8, so the other 14 — every DISPUTE_* among
            // them — take this line. They already reached a listener: support dispatched
            // WebhookReceived above this call, which is the whole of #24's fix.
            return false;
        }

        try {
            match ($event) {
                RevolutWebhookEvent::SubscriptionInitiated,
                RevolutWebhookEvent::SubscriptionFinished,
                RevolutWebhookEvent::SubscriptionCancelled,
                RevolutWebhookEvent::SubscriptionOverdue => $this->syncSubscription($event, $id),
                RevolutWebhookEvent::OrderCompleted,
                RevolutWebhookEvent::OrderPaymentDeclined,
                RevolutWebhookEvent::OrderPaymentFailed,
                RevolutWebhookEvent::OrderFailed => $this->syncOrder($event, $id),
            };
        } catch (RevolutApiException $exception) {
            if ($exception->statusCode !== 404) {
                throw $exception;
            }

            // A deterministic 404 would otherwise retry forever — acknowledge.
            $this->logger->warning('Revolut webhook references a missing resource', [
                'id' => $id,
                'event' => $event->value,
            ]);

            // And false, not true: the resource is gone, so nothing was applied. The old
            // controller dispatched WebhookHandled unconditionally after this call, so
            // this quietly changes that — deliberately. An app listening to WebhookHandled
            // is asking "did local state change?", and here it did not.
            return false;
        }

        return true;
    }

    /**
     * The resource this event is about.
     *
     * Revolut names it differently per event group and the body carries nothing else of
     * use — every sync refetches by this id.
     *
     * @param  array<string, mixed>  $body
     */
    private function resourceId(array $body): string
    {
        foreach (['order_id', 'subscription_id', 'id'] as $key) {
            if (is_string($body[$key] ?? null)) {
                return $body[$key];
            }
        }

        return '';
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

        $cycle = $this->cycle($subscriptionId, $response->currentCycleId);

        $record->forceFill([
            'status' => $status,
            ...($response->trialEndDate !== null ? ['trial_ends_at' => $response->trialEndDate] : []),
            // Mirror truth: an active subscription has no end. When it IS
            // ending, keep an earlier recorded end (grace period) over now().
            'ends_at' => $status === SubscriptionStatus::Canceled
                ? ($record->ends_at ?? now())
                : null,
            // The paid-through date. Distinct from ends_at, which only says when
            // access stops once the subscription is cancelled.
            ...($cycle !== null ? [
                'current_period_start' => $cycle->startDate,
                'current_period_end' => $cycle->endDate,
            ] : []),
        ])->save();

        // Keep the variation mirrored here too — a belt-and-braces resync for
        // state changed on Revolut's side. The renewal itself is what lands a
        // scheduled plan change, and that arrives as ORDER_COMPLETED (see
        // syncOrder), not as a SUBSCRIPTION_* event. Runs before the
        // unchanged-status short-circuit so it is not skipped.
        $this->persistPlanVariation($record, $response->planVariationId);

        // And the change Revolut has scheduled but not applied — including one
        // made in the Revolut dashboard, which the app never asked for and would
        // otherwise never learn about.
        $this->persistPendingPrice($record, $response->pendingPlanVariationId(), $cycle?->endDate);

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
            $cycle,
        );

        event(match ($event) {
            // A Revolut subscription is born `pending` and becomes active when the
            // customer pays its setup order. THAT is the birth worth announcing —
            // announcing the POST would grant access to a customer who may never
            // pay, and an abandoned setup produces no webhook to take it back.
            // (A subscription that was live at creation is announced by the builder;
            // its status never transitions, so it cannot be announced here.)
            RevolutWebhookEvent::SubscriptionInitiated => new SubscriptionCreated($owner, $subscription),
            RevolutWebhookEvent::SubscriptionCancelled,
            RevolutWebhookEvent::SubscriptionFinished => new SubscriptionCanceled($owner, $subscription),
            // A failed payment is past-due, not "something changed". Mapping it
            // onto SubscriptionUpdated was the second thing overloading that
            // event — dunning and suspension deserve a signal of their own.
            default => new SubscriptionPastDue($owner, $subscription),
        });
    }

    /**
     * Record the plan variation the subscription will move to at cycle end, or
     * clear it when Revolut no longer has one scheduled.
     *
     * A null date is "the cycle could not be read" — cycle() tolerates a 404 by
     * design — and not "there is no date". It leaves the recorded one alone, or a
     * transient failure would downgrade a dated pending change into an undated one.
     */
    private function persistPendingPrice(SubscriptionRecord $record, ?string $planVariationId, ?CarbonImmutable $startsAt): void
    {
        $record->forceFill([
            'next_price' => $planVariationId,
            ...($planVariationId === null
                ? ['next_price_starts_at' => null]
                : ($startsAt !== null ? ['next_price_starts_at' => $startsAt] : [])),
        ])->save();
    }

    /**
     * A subscription's billing cycle. Revolut names it by id on the subscription
     * and on the order, but never inlines its dates — they cost a second call.
     *
     * Tolerant on purpose. The period is enrichment; the status write it
     * accompanies is not. A cancelled subscription's cycle may simply be gone
     * (the spec does not guarantee it survives cancellation), and letting that
     * 404 escape would abort the whole sync — and handle() acknowledges 404s, so
     * Revolut would never redeliver and the cancellation would be lost for good.
     */
    private function cycle(string $subscriptionId, ?string $cycleId): ?CycleResponse
    {
        if ($cycleId === null || $cycleId === '') {
            return null;
        }

        try {
            return CycleResponse::from(
                $this->request()->get("/subscriptions/{$subscriptionId}/cycles/{$cycleId}")->json() ?? [],
            );
        } catch (Throwable $exception) {
            $this->logger->warning('Revolut billing cycle could not be fetched; the period stays as recorded', [
                'subscription_id' => $subscriptionId,
                'cycle_id' => $cycleId,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The support DTO for a local invoice record.
     *
     * Mirrors Gateway\ManagesLocalInvoices::toInvoiceDto(), which is private to
     * that trait. Lines are absent by design: they are not stored on the record,
     * and the gateway builds them on demand when an invoice is retrieved.
     */
    private function toInvoiceDto(InvoiceRecord $invoice): Invoice
    {
        $providerId = $invoice->getAttribute('provider_id');
        $number = $invoice->getAttribute('number');
        $subscriptionId = $invoice->getAttribute('subscription_id');

        return new Invoice(
            id: is_string($providerId) && $providerId !== '' ? $providerId : (string) $invoice->getKey(),
            amount: $invoice->amount,
            currency: $invoice->currency,
            status: $invoice->status,
            number: is_string($number) ? $number : null,
            issuedAt: $invoice->issued_at,
            subscriptionId: is_string($subscriptionId) ? $subscriptionId : null,
            periodStart: $invoice->period_start,
            periodEnd: $invoice->period_end,
            billingReason: $invoice->billing_reason,
        );
    }

    /**
     * Apply a paid renewal: advance the subscription's period, tie the invoice
     * to the cycle it settled, and announce it.
     *
     * This is also the moment a plan change scheduled at_cycle_end lands, so the
     * plan is re-mirrored here too.
     *
     * Best-effort by construction: the payment it follows is already booked, so
     * a failure here must never bubble up and cost the invoice or trigger a
     * webhook retry storm. A missed sync is repaired by the next renewal or by
     * any SUBSCRIPTION_* sync.
     *
     * Announced exactly once, keyed on persistent state — the invoice not yet
     * being linked to its subscription — rather than on "was this the first
     * delivery". A first delivery that got as far as booking the payment and
     * then failed here would otherwise lose the renewal for good: the redelivery
     * that should repair it would see a row that already exists and stay silent.
     */
    private function syncRenewal(Model $owner, SubscriptionDataResponse $data, InvoiceRecord $invoice): void
    {
        $model = Cashier::subscriptionModel(RevolutGateway::DRIVER);

        /** @var SubscriptionRecord|null $record */
        $record = $model::query()
            ->where('provider', RevolutGateway::DRIVER)
            ->where('provider_id', $data->subscriptionId)
            ->first();

        if ($record === null) {
            return;
        }

        // Not yet tied to its subscription ⇒ this renewal has not been announced.
        $announced = $invoice->getAttribute('subscription_id') !== null;

        try {
            $response = SubscriptionResponse::from(
                $this->request()->get("/subscriptions/{$data->subscriptionId}")->json() ?? [],
            );
        } catch (Throwable $exception) {
            $this->logger->warning('Revolut renewal sync failed after the payment was booked', [
                'subscription_id' => $data->subscriptionId,
                'exception' => $exception->getMessage(),
            ]);

            return;
        }

        $cycle = $this->cycle($data->subscriptionId, $data->activeCycleId);

        $previousPlan = $this->currentPlanVariation($record);

        // Only when the cycle is known: an unavailable cycle means "we could not
        // look", not "there is no period". Nulling a recorded paid-through date
        // would lose it.
        if ($cycle !== null) {
            $record->forceFill([
                'current_period_start' => $cycle->startDate,
                'current_period_end' => $cycle->endDate,
            ])->save();
        }

        $this->persistPlanVariation($record, $response->planVariationId);

        // Whatever Revolut still has scheduled — normally nothing, because the
        // renewal is exactly when a scheduled change lands. Writing what Revolut
        // reports (rather than blindly clearing) keeps a second, still-pending
        // change visible instead of silently dropping it.
        $this->persistPendingPrice($record, $response->pendingPlanVariationId(), $cycle?->endDate);

        $invoice->forceFill([
            'subscription_id' => $record->getKey(),
            ...($cycle !== null ? [
                'period_start' => $cycle->startDate,
                'period_end' => $cycle->endDate,
            ] : []),
        ])->save();

        $type = (string) $record->getAttribute('type');
        $subscription = $response->toSubscription($type, $record->ends_at, $cycle);

        // The setup order is linked like any other, but it is not a renewal —
        // SubscriptionCreated already announces it.
        if (! $announced && $data->isCycleBilling()) {
            event(new SubscriptionRenewed($owner, $subscription, $this->toInvoiceDto($invoice)));
        }

        // The deferred change has landed: this — not the swap call that merely
        // scheduled it — is when the customer is actually on the new plan.
        //
        // A null previous plan is not a change, it is a first sighting: the row
        // is simply being written for the first time (a subscription created
        // before the driver recorded item rows, or one created outside the app).
        // Firing here would announce a plan change that never happened.
        if ($previousPlan !== null && $response->planVariationId !== null && $response->planVariationId !== $previousPlan) {
            event(new SubscriptionUpdated($owner, $subscription));
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
                    // No subscription context ⇒ a one-off charge. With one, the
                    // reason is whatever Revolut named — or null if it named
                    // something this driver does not recognise. Never guessed.
                    'billing_reason' => $order->subscriptionData === null
                        ? BillingReason::Manual
                        : $order->subscriptionData->toBillingReason(),
                ],
            );

            // Dispatch exactly once — redeliveries update the same record.
            if ($invoice->wasRecentlyCreated) {
                event(new PaymentSucceeded($owner, $payment));
            }

            // A completed cycle-billing order IS the renewal. Revolut fires no
            // webhook for a renewal, and the SUBSCRIPTION_* events do not fire on
            // one — this is the only place it can be seen. It is also when a plan
            // change scheduled at cycle end takes effect.
            //
            // Every subscription order is linked, not only a renewal: the very
            // first invoice (the setup order) belongs to its subscription too,
            // and leaving it unlinked would put a hole at the start of every
            // billing history.
            //
            // Strictly after the payment is booked: everything below costs extra
            // API calls, and money must never be lost because one of them failed.
            if ($event === RevolutWebhookEvent::OrderCompleted && $order->subscriptionData !== null) {
                $this->syncRenewal($owner, $order->subscriptionData, $invoice);
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
        return $this->resolveOwnerByCustomerId($customerId);
    }

    protected function driverName(): string
    {
        return RevolutGateway::DRIVER;
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
