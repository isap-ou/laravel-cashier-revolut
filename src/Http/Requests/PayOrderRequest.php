<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Requests;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Request body for POST /api/orders/{id}/payments — a merchant-initiated
 * charge against a saved payment method.
 *
 * @internal The shape of a Revolut request body, which is Revolut's to change and not ours to freeze. Reached only through RevolutGateway. Not public surface: outside the backward-compatibility promise in README.
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
