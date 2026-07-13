<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Responses;

use Carbon\CarbonImmutable;
use Isapp\CashierRevolut\Enums\RevolutOrderState;
use Isapp\CashierRevolut\Enums\RevolutOrderType;
use Isapp\CashierRevolut\Exceptions\RevolutApiException;
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
        public ?string $type = null,
        public ?string $token = null,
        public ?string $checkoutUrl = null,
        public ?string $state = null,
        public ?string $customerId = null,
        public ?SubscriptionDataResponse $subscriptionData = null,
        #[WithCast(DateTimeInterfaceCast::class, format: RevolutDateFormats::FORMATS)]
        public ?CarbonImmutable $createdAt = null,
    ) {}

    /**
     * The order type as an enum; null when the API sent an unknown value.
     */
    public function orderType(): ?RevolutOrderType
    {
        return $this->type !== null ? RevolutOrderType::tryFrom($this->type) : RevolutOrderType::Payment;
    }

    /**
     * The order state as an enum; null when absent or unknown.
     */
    public function orderState(): ?RevolutOrderState
    {
        return $this->state !== null ? RevolutOrderState::tryFrom($this->state) : null;
    }

    /**
     * Whether this order represents a customer payment (refunds and
     * chargebacks are orders too — they must never be booked as payments).
     */
    public function isPaymentOrder(): bool
    {
        return $this->orderType() === RevolutOrderType::Payment;
    }

    /**
     * The order currency as the support enum.
     *
     * @throws RevolutApiException When the currency is not supported.
     */
    public function currencyEnum(): Currency
    {
        return Currency::tryFrom(strtoupper($this->currency))
            ?? throw RevolutApiException::unsupportedCurrency($this->currency);
    }

    public function status(): PaymentStatus
    {
        return $this->orderState()?->toPaymentStatus() ?? PaymentStatus::Pending;
    }

    public function toPayment(): Payment
    {
        return new Payment(
            id: $this->id,
            amount: $this->amount,
            currency: $this->currencyEnum(),
            status: $this->status(),
            createdAt: $this->createdAt,
        );
    }
}
