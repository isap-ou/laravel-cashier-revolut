<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Enums;

use Isapp\CashierSupport\Enums\PaymentStatus;

/**
 * Order states per the Revolut OpenAPI specification (merchant-2026-04-20).
 */
enum RevolutOrderState: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Authorised = 'authorised';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    /**
     * The provider-agnostic payment status this order state maps to.
     */
    public function toPaymentStatus(): PaymentStatus
    {
        return match ($this) {
            self::Pending => PaymentStatus::Pending,
            self::Processing,
            self::Authorised => PaymentStatus::Processing,
            self::Completed => PaymentStatus::Succeeded,
            self::Cancelled => PaymentStatus::Canceled,
            self::Failed => PaymentStatus::Failed,
        };
    }
}
