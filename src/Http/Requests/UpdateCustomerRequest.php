<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Requests;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Request body for PATCH /api/customers/{id}.
 *
 * Same shape as CreateCustomerRequest, kept separate to name the operation: on PATCH a null
 * field is omitted by RevolutRequest::payload(), so an unmentioned field is left untouched at
 * the gateway rather than cleared.
 */
#[MapOutputName(SnakeCaseMapper::class)]
class UpdateCustomerRequest extends RevolutRequest
{
    public function __construct(
        public ?string $fullName = null,
        public ?string $email = null,
        public ?string $phone = null,
    ) {}
}
