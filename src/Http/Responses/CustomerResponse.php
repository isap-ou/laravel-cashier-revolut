<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Responses;

use Isapp\CashierSupport\DTO\Customer;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * A Revolut customer resource.
 *
 * @internal The shape of a Revolut response, which is Revolut's to change and not ours to freeze — a new API version may add, rename or drop fields within a minor release. Reached only through RevolutGateway. Not public surface: outside the backward-compatibility promise in README.
 */
#[MapInputName(SnakeCaseMapper::class)]
class CustomerResponse extends Data
{
    public function __construct(
        public string $id,
        public ?string $fullName = null,
        public ?string $email = null,
    ) {}

    public function toCustomer(): Customer
    {
        return new Customer(
            id: $this->id,
            name: $this->fullName,
            email: $this->email,
        );
    }
}
