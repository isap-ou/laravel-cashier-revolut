<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Requests;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Request body for POST /api/orders/{id}/payments — a merchant-initiated
 * charge against a saved payment method.
 */
#[MapOutputName(SnakeCaseMapper::class)]
class PayOrderRequest extends RevolutRequest
{
    /**
     * @var array<string, mixed>
     */
    public array $savedPaymentMethod;

    public function __construct(string $savedPaymentMethodId, ?string $type = null)
    {
        $this->savedPaymentMethod = array_filter([
            'id' => $savedPaymentMethodId,
            'type' => $type,
        ], static fn (?string $value): bool => $value !== null);
    }
}
