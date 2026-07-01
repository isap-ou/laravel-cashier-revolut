<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Requests;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Request body for POST /api/orders/{id}/refund.
 */
#[MapOutputName(SnakeCaseMapper::class)]
class RefundOrderRequest extends RevolutRequest
{
    public function __construct(
        public ?string $currency = null,
        public ?int $amount = null,
    ) {}
}
