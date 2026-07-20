<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Responses;

use Isapp\CashierSupport\Enums\BillingReason;
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
 *
 * @internal The shape of a Revolut response, which is Revolut's to change and not ours to freeze — a new API version may add, rename or drop fields within a minor release. Reached only through RevolutGateway. Not public surface: outside the backward-compatibility promise in README.
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

    /**
     * The provider-agnostic reason this invoice was raised; null when Revolut
     * did not say, or said something this driver does not know.
     *
     * `setup_intent` is the order that starts a subscription; `final_settlement`
     * collects usage after it ended — still a charge the subscription drove, so
     * it maps to the cycle reason rather than to a manual one. Anything else is
     * left unknown rather than guessed: labelling an unrecognised reason as a
     * renewal would put a fiction into the billing history.
     */
    public function toBillingReason(): ?BillingReason
    {
        return match ($this->billingReason) {
            self::BILLING_REASON_CYCLE_BILLING, 'final_settlement' => BillingReason::SubscriptionCycle,
            'setup_intent' => BillingReason::SubscriptionCreate,
            default => null,
        };
    }
}
