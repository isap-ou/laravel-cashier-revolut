<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Responses;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * The subscription context carried by an order that a subscription created
 * (`subscription_data`, present only on such orders).
 *
 * `billing_reason: cycle_billing` is the renewal signal: the order collects
 * payment for a new billing cycle. That is the moment a scheduled plan change
 * takes effect — Revolut has no dedicated webhook for it.
 *
 * @see https://developer.revolut.com/docs/api/merchant/operations/retrieve-order
 */
#[MapInputName(SnakeCaseMapper::class)]
class SubscriptionDataResponse extends Data
{
    public const BILLING_REASON_CYCLE_BILLING = 'cycle_billing';

    public function __construct(
        public string $subscriptionId,
        public ?string $activeCycleId = null,
        public ?string $settledCycleId = null,
        public ?string $billingReason = null,
    ) {}

    /**
     * Whether this order pays for an ongoing subscription cycle.
     */
    public function isCycleBilling(): bool
    {
        return $this->billingReason === self::BILLING_REASON_CYCLE_BILLING;
    }
}
