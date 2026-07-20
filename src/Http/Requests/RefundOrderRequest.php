<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Requests;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Request body for POST /api/orders/{id}/refund.
 */
#[MapOutputName(SnakeCaseMapper::class)]
/**
 * Revolut request payload: RefundOrderRequest.
 *
 * @internal The shape of a Revolut request body, which is Revolut's to change and not ours to freeze. Reached only through RevolutGateway. Not public surface: outside the backward-compatibility promise in README.
 */
class RefundOrderRequest extends RevolutRequest
{
    public function __construct(
        public ?string $currency = null,
        public ?int $amount = null,
    ) {}
}
