<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Responses;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Isapp\CashierRevolut\Enums\RevolutOrderState;
use Isapp\CashierRevolut\Exceptions\RevolutApiException;
use Isapp\CashierSupport\Casts\CurrencyCast;
use Isapp\CashierSupport\DTO\Refund;
use Money\Currency;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * A Revolut refund resource (an order of type "refund" per the OpenAPI spec).
 *
 * @internal The shape of a Revolut response, which is Revolut's to change and not ours to freeze — a new API version may add, rename or drop fields within a minor release. Reached only through RevolutGateway. Not public surface: outside the backward-compatibility promise in README.
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
                ? $this->currencyOrFail($this->currency)
                : throw RevolutApiException::unsupportedCurrency('(missing)'),
            createdAt: $this->createdAt,
        );
    }

    /**
     * A currency Revolut sent that is not a known ISO-4217 code is bad gateway data,
     * not a programmer error — surfaced as a catchable RevolutApiException rather than
     * the InvalidArgumentException CurrencyCast raises at the support boundary.
     *
     * @throws RevolutApiException When the currency is not a known ISO-4217 code.
     */
    private function currencyOrFail(string $code): Currency
    {
        try {
            return CurrencyCast::fromCode($code);
        } catch (InvalidArgumentException) {
            throw RevolutApiException::unsupportedCurrency($code);
        }
    }
}
