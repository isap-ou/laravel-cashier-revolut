<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Enums;

use Isapp\CashierRevolut\Enums\Concerns\HasRevolutLabel;
use Isapp\CashierSupport\Contracts\PaymentMethodType;

/**
 * Payment method types supported by Revolut, implementing the provider-agnostic
 * PaymentMethodType contract from cashier-support. Labels are translatable via
 * the cashier-revolut translation namespace.
 */
enum RevolutPaymentMethodType: string implements PaymentMethodType
{
    use HasRevolutLabel;

    case Card = 'card';
    case RevolutPay = 'revolut_pay';
    case ApplePay = 'apple_pay';
    case GooglePay = 'google_pay';
    case PayByBank = 'pay_by_bank';

    /**
     * Map a raw Revolut payment method type onto a case, defaulting to Card.
     *
     * Case-insensitive: the legacy payment-methods shape uses upper-case
     * values (e.g. REVOLUT_PAY).
     */
    public static function fromRevolut(?string $type): self
    {
        return $type !== null ? (self::tryFrom(strtolower($type)) ?? self::Card) : self::Card;
    }
}
