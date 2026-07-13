<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Requests;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Request body for POST /api/subscriptions.
 *
 * No quantity: Revolut has no per-subscription quantity. It lives on the plan
 * variation's items (a flat item is a fixed amount multiplied by its quantity),
 * fixed when the plan is created. The endpoint does not document the field, and
 * sending it told an app it had bought five seats while Revolut billed whatever
 * the plan said.
 *
 * @see https://developer.revolut.com/docs/api/merchant/operations/create-subscription
 */
#[MapOutputName(SnakeCaseMapper::class)]
class CreateSubscriptionRequest extends RevolutRequest
{
    public function __construct(
        public string $customerId,
        public string $planVariationId,
        public ?string $trialDuration = null,
        public ?string $externalReference = null,
    ) {}
}
