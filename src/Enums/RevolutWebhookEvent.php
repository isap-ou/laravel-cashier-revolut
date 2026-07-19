<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Enums;

use IsapOu\EnumHelpers\Concerns\InteractWithCollection;

/**
 * The Revolut Merchant API webhook catalogue — all 22 documented event types, in the five
 * groups Revolut documents them in.
 *
 * **This enum is a fact about Revolut, not a statement about us.** It answers "what can arrive",
 * which is the question `RevolutGateway::registerWebhook()` asks when it subscribes an endpoint.
 * What we *apply* to local state is a different question, it is smaller, and it is answered in
 * exactly one place: the match in `Webhooks\RevolutWebhookSynchronizer::handle()`. Do not add an
 * `isMapped()` here to bring the two back together — conflating them is what this enum used to do,
 * and it cost us the escape hatch (below).
 *
 * It carried 8 cases until now: the events the synchronizer applies. Because registration read
 * its cases too, `php artisan cashier:webhook revolut` subscribed the endpoint to those 8 and
 * nothing else — so the other 14 were never DELIVERED, and `Events\WebhookReceived`, the hatch
 * that #24 and support#42/#47 exist to guarantee, could not fire for precisely the events it was
 * built for. Every `DISPUTE_*` was in that set. Widening the enum to the catalogue is what makes
 * the hatch reachable; `config('cashier-revolut.webhook.events')` is what lets an operator narrow
 * it again on purpose.
 *
 * It also used to carry `toWebhookEvent()`, mapping each case onto a provider-agnostic
 * `WebhookEvent`. Both are gone (support#47): the agnostic enum was the hatch's ceiling — an
 * 8-case closed vocabulary no gateway's catalogue is a subset of — and nothing ever read the
 * mapping's result. Agnostic meaning travels on the typed events the synchronizer dispatches
 * (PaymentSucceeded, SubscriptionCanceled, ...), which carry the billable and a real DTO.
 *
 * Verified against the `events` enum of `create-webhook` / `update-webhook` (identical in both)
 * and cross-checked against the embedded JSON of `/docs/api-reference/merchant/`. The table in
 * `.claude/rules/revolut-api.md` is the same 22 — count the rows before quoting a number, an
 * earlier draft said 18 because its author grepped for `ORDER_|SUBSCRIPTION_|PAYOUT` and never
 * saw the Dispute row.
 *
 * @see https://developer.revolut.com/docs/api/merchant/operations/create-webhook
 */
enum RevolutWebhookEvent: string
{
    use InteractWithCollection;

    // Order
    case OrderCompleted = 'ORDER_COMPLETED';
    case OrderAuthorised = 'ORDER_AUTHORISED';
    case OrderCancelled = 'ORDER_CANCELLED';
    case OrderFailed = 'ORDER_FAILED';
    case OrderIncrementalAuthorisationAuthorised = 'ORDER_INCREMENTAL_AUTHORISATION_AUTHORISED';
    case OrderIncrementalAuthorisationDeclined = 'ORDER_INCREMENTAL_AUTHORISATION_DECLINED';
    case OrderIncrementalAuthorisationFailed = 'ORDER_INCREMENTAL_AUTHORISATION_FAILED';

    // Payment
    case OrderPaymentAuthenticationChallenged = 'ORDER_PAYMENT_AUTHENTICATION_CHALLENGED';
    case OrderPaymentAuthenticated = 'ORDER_PAYMENT_AUTHENTICATED';
    case OrderPaymentDeclined = 'ORDER_PAYMENT_DECLINED';
    case OrderPaymentFailed = 'ORDER_PAYMENT_FAILED';

    // Subscription
    case SubscriptionInitiated = 'SUBSCRIPTION_INITIATED';
    case SubscriptionFinished = 'SUBSCRIPTION_FINISHED';
    case SubscriptionCancelled = 'SUBSCRIPTION_CANCELLED';
    case SubscriptionOverdue = 'SUBSCRIPTION_OVERDUE';

    // Payout — merchant settlement, not billing. Delivered so an app can act on it; never
    // applied here, because Cashier has no settlement concept and neither reference models one.
    case PayoutInitiated = 'PAYOUT_INITIATED';
    case PayoutCompleted = 'PAYOUT_COMPLETED';
    case PayoutFailed = 'PAYOUT_FAILED';

    // Dispute — DISPUTE_ACTION_REQUIRED is the one with a deadline attached.
    case DisputeActionRequired = 'DISPUTE_ACTION_REQUIRED';
    case DisputeUnderReview = 'DISPUTE_UNDER_REVIEW';
    case DisputeWon = 'DISPUTE_WON';
    case DisputeLost = 'DISPUTE_LOST';
}
