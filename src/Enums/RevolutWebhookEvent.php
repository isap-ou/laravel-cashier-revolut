<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Enums;

/**
 * The Revolut Merchant API webhook events this driver subscribes to.
 *
 * Eight of the 22 Revolut documents (see .claude/rules/revolut-api.md for the verified
 * catalogue and the 14 we do not map). The other 14 are not lost: support's controller
 * dispatches WebhookReceived with the raw body above anything that reads this enum, so an
 * app can act on a DISPUTE_ACTION_REQUIRED we never mapped. That ordering being support's
 * rather than ours is #24.
 *
 * It used to carry toWebhookEvent(), mapping each case onto a provider-agnostic
 * WebhookEvent. Both are gone (support#47): the agnostic enum was the escape hatch's
 * ceiling — an 8-case closed vocabulary that no gateway's catalogue is a subset of — and
 * nothing ever read the mapping's result. Agnostic meaning travels on the typed events
 * this driver's synchronizer dispatches (PaymentSucceeded, SubscriptionCanceled, ...),
 * which carry the billable and a real DTO rather than a name.
 */
enum RevolutWebhookEvent: string
{
    case OrderCompleted = 'ORDER_COMPLETED';
    case OrderPaymentDeclined = 'ORDER_PAYMENT_DECLINED';
    case OrderPaymentFailed = 'ORDER_PAYMENT_FAILED';
    case OrderFailed = 'ORDER_FAILED';
    case SubscriptionInitiated = 'SUBSCRIPTION_INITIATED';
    case SubscriptionFinished = 'SUBSCRIPTION_FINISHED';
    case SubscriptionCancelled = 'SUBSCRIPTION_CANCELLED';
    case SubscriptionOverdue = 'SUBSCRIPTION_OVERDUE';
}
