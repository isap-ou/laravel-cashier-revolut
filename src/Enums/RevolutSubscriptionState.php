<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Enums;

use Isapp\CashierSupport\Enums\SubscriptionStatus;

/**
 * Subscription states per the Revolut OpenAPI specification
 * (merchant-2026-04-20).
 */
enum RevolutSubscriptionState: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Overdue = 'overdue';
    case Paused = 'paused';
    case Cancelled = 'cancelled';
    case Finished = 'finished';

    /**
     * The provider-agnostic subscription status this state maps to.
     */
    public function toSubscriptionStatus(): SubscriptionStatus
    {
        return match ($this) {
            self::Pending => SubscriptionStatus::Incomplete,
            self::Active => SubscriptionStatus::Active,
            self::Overdue => SubscriptionStatus::PastDue,
            self::Paused => SubscriptionStatus::Paused,
            self::Cancelled,
            self::Finished => SubscriptionStatus::Canceled,
        };
    }
}
