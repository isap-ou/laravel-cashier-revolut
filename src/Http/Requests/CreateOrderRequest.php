<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Requests;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Request body for POST /api/orders.
 *
 * @internal The shape of a Revolut request body, which is Revolut's to change and not ours to freeze. Reached only through RevolutGateway. Not public surface: outside the backward-compatibility promise in README.
 */
#[MapOutputName(SnakeCaseMapper::class)]
class CreateOrderRequest extends RevolutRequest
{
    /**
     * @param  array<string, string>|null  $metadata  Revolut accepts string values only.
     */
    public function __construct(
        public int $amount,
        public string $currency,
        public string $captureMode = 'automatic',
        public ?OrderCustomerRequest $customer = null,
        public ?string $redirectUrl = null,
        public ?string $description = null,
        public ?array $metadata = null,
        public ?MerchantOrderDataRequest $merchantOrderData = null,
    ) {}
}
