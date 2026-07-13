<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Responses;

use Carbon\CarbonImmutable;
use Isapp\CashierRevolut\Enums\RevolutOrderState;
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
        public ?string $state = null,
        #[WithCast(DateTimeInterfaceCast::class, format: RevolutDateFormats::FORMATS)]
        public ?CarbonImmutable $createdAt = null,
    ) {}

    /**
     * The refund order's state as an enum; null when absent or unknown.
     */
    public function orderState(): ?RevolutOrderState
    {
        return $this->state !== null ? RevolutOrderState::tryFrom($this->state) : null;
    }

    /**
     * Whether Revolut rejected the refund.
     *
     * POST /orders/{id}/refund answers 201 "Refund order successfully created"
     * — acceptance, not settlement. The refund is an order in its own right, so
     * a 2xx can still carry a failed or cancelled state.
     *
     * @see https://developer.revolut.com/docs/api/merchant/operations/refund-order
     */
    public function failed(): bool
    {
        return in_array($this->orderState(), [RevolutOrderState::Failed, RevolutOrderState::Cancelled], true);
    }

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
