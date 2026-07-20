<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Responses;

use Isapp\CashierRevolut\Enums\RevolutPaymentMethodType;
use Isapp\CashierSupport\DTO\PaymentMethod;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * A saved payment method on a Revolut customer.
 */
#[MapInputName(SnakeCaseMapper::class)]
/**
 * Revolut response payload: PaymentMethodResponse.
 *
 * @internal The shape of a Revolut response, which is Revolut's to change and not ours to freeze — a new API version may add, rename or drop fields within a minor release. Reached only through RevolutGateway. Not public surface: outside the backward-compatibility promise in README.
 */
class PaymentMethodResponse extends Data
{
    public function __construct(
        public string $id,
        public ?string $type = null,
        public ?string $brand = null,
        public ?string $lastFour = null,
    ) {}

    public function toPaymentMethod(): PaymentMethod
    {
        return new PaymentMethod(
            id: $this->id,
            type: RevolutPaymentMethodType::fromRevolut($this->type),
            brand: $this->brand,
            last4: $this->lastFour,
        );
    }
}
