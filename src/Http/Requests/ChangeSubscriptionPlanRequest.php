<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Requests;

use Isapp\CashierRevolut\Enums\RevolutChangePlanReason;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Request body for POST /api/subscriptions/{subscription_id}/change-plan.
 *
 * @see https://developer.revolut.com/docs/api/merchant/operations/change-subscription-plan
 */
#[MapOutputName(SnakeCaseMapper::class)]
/**
 * Revolut request payload: ChangeSubscriptionPlanRequest.
 *
 * @internal The shape of a Revolut request body, which is Revolut's to change and not ours to freeze. Reached only through RevolutGateway. Not public surface: outside the backward-compatibility promise in README.
 */
class ChangeSubscriptionPlanRequest extends RevolutRequest
{
    /**
     * The only value Revolut accepts for `scheduled`: the change is applied at
     * the end of the current billing cycle.
     */
    public const SCHEDULED_AT_CYCLE_END = 'at_cycle_end';

    public function __construct(
        public string $planVariationId,
        public ?string $planVariationPhaseId = null,
        public string $scheduled = self::SCHEDULED_AT_CYCLE_END,
        public ?RevolutChangePlanReason $reason = null,
    ) {}
}
