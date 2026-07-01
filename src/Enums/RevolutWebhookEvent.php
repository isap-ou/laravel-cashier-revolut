<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Enums;

use Isapp\CashierSupport\Enums\WebhookEvent;

/**
 * Webhook event types sent by the Revolut Merchant API that this driver
 * subscribes to, with their mapping onto the provider-agnostic WebhookEvent.
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

    /**
     * The provider-agnostic event this Revolut event translates to.
     */
    public function toWebhookEvent(): WebhookEvent
    {
        return match ($this) {
            self::OrderCompleted => WebhookEvent::PaymentSucceeded,
            self::OrderPaymentDeclined,
            self::OrderPaymentFailed,
            self::OrderFailed => WebhookEvent::PaymentFailed,
            self::SubscriptionInitiated => WebhookEvent::SubscriptionCreated,
            // Finished is terminal (the subscription ended) — closer to
            // canceled than to a mere update in the agnostic vocabulary.
            self::SubscriptionCancelled,
            self::SubscriptionFinished => WebhookEvent::SubscriptionCanceled,
            self::SubscriptionOverdue => WebhookEvent::SubscriptionUpdated,
        };
    }
}
