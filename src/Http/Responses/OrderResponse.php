<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Responses;

use Carbon\CarbonImmutable;
use Isapp\CashierSupport\DTO\Payment;
use Isapp\CashierSupport\Enums\Currency;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * A Revolut order resource (the unit a one-off payment maps to).
 *
 * Order states per OpenAPI merchant-2025-12-04:
 * pending | processing | authorised | completed | cancelled | failed.
 */
#[MapInputName(SnakeCaseMapper::class)]
class OrderResponse extends Data
{
    public function __construct(
        public string $id,
        public int $amount,
        public string $currency,
        public ?string $token = null,
        public ?string $checkoutUrl = null,
        public ?string $state = null,
        public ?string $customerId = null,
        #[WithCast(DateTimeInterfaceCast::class, format: RevolutDateFormats::FORMATS)]
        public ?CarbonImmutable $createdAt = null,
    ) {}

    public function status(): PaymentStatus
    {
        return match ($this->state) {
            'completed' => PaymentStatus::Succeeded,
            'failed' => PaymentStatus::Failed,
            'cancelled' => PaymentStatus::Canceled,
            'processing', 'authorised' => PaymentStatus::Processing,
            default => PaymentStatus::Pending,
        };
    }

    public function toPayment(): Payment
    {
        return new Payment(
            id: $this->id,
            amount: $this->amount,
            currency: Currency::tryFrom(strtoupper($this->currency)) ?? Currency::EUR,
            status: $this->status(),
            createdAt: $this->createdAt,
        );
    }
}
