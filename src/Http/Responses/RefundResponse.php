<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Responses;

use Carbon\CarbonImmutable;
use Isapp\CashierRevolut\Exceptions\RevolutApiException;
use Isapp\CashierSupport\DTO\Refund;
use Isapp\CashierSupport\Enums\Currency;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * A Revolut refund resource (an order of type "refund" per the OpenAPI spec).
 */
#[MapInputName(SnakeCaseMapper::class)]
class RefundResponse extends Data
{
    public function __construct(
        public string $id,
        public int $amount = 0,
        public ?string $currency = null,
        #[WithCast(DateTimeInterfaceCast::class, format: RevolutDateFormats::FORMATS)]
        public ?CarbonImmutable $createdAt = null,
    ) {}

    public function toRefund(string $paymentId): Refund
    {
        return new Refund(
            id: $this->id,
            paymentId: $paymentId,
            amount: $this->amount,
            currency: $this->currency !== null
                ? (Currency::tryFrom(strtoupper($this->currency)) ?? throw RevolutApiException::unsupportedCurrency($this->currency))
                : throw RevolutApiException::unsupportedCurrency('(missing)'),
            createdAt: $this->createdAt,
        );
    }
}
