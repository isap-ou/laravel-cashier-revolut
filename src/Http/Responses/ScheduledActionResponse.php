<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Responses;

use Isapp\CashierRevolut\Enums\RevolutScheduledActionType;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * A change Revolut has scheduled for the end of the current billing cycle.
 *
 * This is what makes a deferred swap observable: the pending plan variation is
 * the gateway's own fact, reported back on the subscription, rather than our
 * memory of what we asked for. `plan_variation_id` is present only on a plan
 * change — a scheduled cancellation carries none.
 *
 * @see https://developer.revolut.com/docs/api/merchant/operations/retrieve-subscription.md
 *
 * @internal The shape of a Revolut response, which is Revolut's to change and not ours to freeze — a new API version may add, rename or drop fields within a minor release. Reached only through RevolutGateway. Not public surface: outside the backward-compatibility promise in README.
 */
#[MapInputName(SnakeCaseMapper::class)]
class ScheduledActionResponse extends Data
{
    public function __construct(
        public ?string $type = null,
        public ?string $reason = null,
        public ?string $planVariationId = null,
    ) {}

    /**
     * The action type as an enum; null when absent or unknown.
     */
    public function actionType(): ?RevolutScheduledActionType
    {
        return $this->type !== null ? RevolutScheduledActionType::tryFrom($this->type) : null;
    }

    /**
     * The plan variation the subscription will move to, or null when the
     * scheduled action is not a plan change.
     */
    public function pendingPlanVariationId(): ?string
    {
        return $this->actionType() === RevolutScheduledActionType::ChangePlanVariation
            ? $this->planVariationId
            : null;
    }
}
