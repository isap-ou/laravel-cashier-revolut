<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Responses;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Isapp\CashierRevolut\Enums\RevolutOrderState;
use Isapp\CashierRevolut\Enums\RevolutOrderType;
use Isapp\CashierRevolut\Exceptions\RevolutApiException;
use Isapp\CashierSupport\Casts\CurrencyCast;
use Isapp\CashierSupport\DTO\Payment;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Money\Currency;
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
 *
 * @internal The shape of a Revolut response, which is Revolut's to change and not ours to freeze — a new API version may add, rename or drop fields within a minor release. Reached only through RevolutGateway. Not public surface: outside the backward-compatibility promise in README.
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
     * The order currency as a Money\Currency value object.
     *
     * A currency Revolut sent that is not a known ISO-4217 code is a fact about the
     * world (bad gateway data), not a programmer error — so it surfaces as a catchable
     * RevolutApiException, not the InvalidArgumentException CurrencyCast raises at the
     * support boundary.
     *
     * @throws RevolutApiException When the currency is not a known ISO-4217 code.
     */
    public function currencyEnum(): Currency
    {
        try {
            return CurrencyCast::fromCode($this->currency);
        } catch (InvalidArgumentException) {
            throw RevolutApiException::unsupportedCurrency($this->currency);
        }
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

    /**
     * This order seen as a subscription's outstanding setup payment.
     *
     * A subscription is created `pending` with a setup order the customer still has to pay in the
     * Checkout Widget; the widget consumes this order's `token`. So the neutral Payment carries
     * that token as its `clientSecret` and reports RequiresPaymentMethod — the customer has not
     * provided payment yet — rather than the raw `pending` state, which no requires*() predicate
     * reads. This mirrors charge()'s SCA branch, which likewise pairs the order `token` with a
     * requires_* status instead of the order state, and Cashier's incomplete-subscription payment,
     * which reports a requires_* status an app can branch on.
     */
    public function toSetupPayment(): Payment
    {
        return new Payment(
            id: $this->id,
            amount: $this->amount,
            currency: $this->currencyEnum(),
            status: PaymentStatus::RequiresPaymentMethod,
            clientSecret: $this->token,
            createdAt: $this->createdAt,
        );
    }
}
