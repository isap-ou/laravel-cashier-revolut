<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Requests;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Request body for POST /api/subscriptions.
 */
#[MapOutputName(SnakeCaseMapper::class)]
class CreateSubscriptionRequest extends RevolutRequest
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public string $customerId,
        public string $planVariationId,
        public ?string $trialDuration = null,
        public int $quantity = 1,
        public ?array $metadata = null,
    ) {}
}
