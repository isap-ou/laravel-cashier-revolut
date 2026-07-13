<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Responses;

use Carbon\CarbonImmutable;
use Isapp\CashierRevolut\Enums\RevolutSubscriptionState;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * A Revolut subscription resource (OpenAPI merchant-2025-12-04).
 *
 * The schema exposes trial_end_date and no cancellation timestamp; the
 * subscription's end is signalled by its state (cancelled/finished).
 */
#[MapInputName(SnakeCaseMapper::class)]
class SubscriptionResponse extends Data
{
    public function __construct(
        public string $id,
        public ?string $state = null,
        public ?string $currentCycleId = null,
        public ?string $planVariationId = null,
        #[WithCast(DateTimeInterfaceCast::class, format: RevolutDateFormats::FORMATS)]
        public ?CarbonImmutable $trialEndDate = null,
        #[WithCast(DateTimeInterfaceCast::class, format: RevolutDateFormats::FORMATS)]
        public ?CarbonImmutable $createdAt = null,
        public ?ScheduledActionResponse $scheduledAction = null,
    ) {}

    /**
     * The plan variation the subscription is scheduled to move to at the end of
     * the current cycle, if any.
     */
    public function pendingPlanVariationId(): ?string
    {
        return $this->scheduledAction?->pendingPlanVariationId();
    }

    /**
     * The subscription state as an enum; null when absent or unknown.
     */
    public function subscriptionState(): ?RevolutSubscriptionState
    {
        return $this->state !== null ? RevolutSubscriptionState::tryFrom($this->state) : null;
    }

    public function status(): SubscriptionStatus
    {
        return $this->subscriptionState()?->toSubscriptionStatus() ?? SubscriptionStatus::Incomplete;
    }

    /**
     * @param  CarbonImmutable|null  $endsAt  When access ends. Supplied by the
     *                                        caller: the Revolut subscription
     *                                        resource carries no such field —
     *                                        the paid-through date lives on the
     *                                        active billing cycle.
     * @param  CycleResponse|null  $cycle  The subscription's active billing
     *                                     cycle, fetched separately. Revolut only
     *                                     names its id here, not its dates.
     */
    public function toSubscription(string $type, ?CarbonImmutable $endsAt = null, ?CycleResponse $cycle = null): Subscription
    {
        return new Subscription(
            id: $this->id,
            type: $type,
            status: $this->status(),
            trialEndsAt: $this->trialEndDate,
            endsAt: $endsAt,
            createdAt: $this->createdAt,
            currentPeriodStart: $cycle?->startDate,
            currentPeriodEnd: $cycle?->endDate,
            pendingPrice: $this->pendingPlanVariationId(),
            // Revolut names no date on the scheduled action: it lands at the end
            // of the current cycle, which is the date we already hold.
            pendingPriceStartsAt: $this->pendingPlanVariationId() !== null ? $cycle?->endDate : null,
        );
    }
}
