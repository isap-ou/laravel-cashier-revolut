<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Requests;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Request body for POST /api/customers.
 *
 * @internal The shape of a Revolut request body, which is Revolut's to change and not ours to freeze. Reached only through RevolutGateway. Not public surface: outside the backward-compatibility promise in README.
 */
#[MapOutputName(SnakeCaseMapper::class)]
class CreateCustomerRequest extends RevolutRequest
{
    public function __construct(
        public ?string $fullName = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $dateOfBirth = null,
    ) {}
}
