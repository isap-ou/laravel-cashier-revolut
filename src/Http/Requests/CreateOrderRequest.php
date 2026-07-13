<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Requests;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Request body for POST /api/orders.
 */
#[MapOutputName(SnakeCaseMapper::class)]
class CreateOrderRequest extends RevolutRequest
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public int $amount,
        public string $currency,
        public string $captureMode = 'automatic',
        public ?string $customerId = null,
        public ?string $redirectUrl = null,
        public ?string $description = null,
        public ?array $metadata = null,
    ) {}
}
