<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Enums;

/**
 * What Revolut has scheduled to happen at the end of the current billing cycle.
 *
 * The `scheduled_action` object is a discriminated union: a cancellation carries
 * no plan variation, and reading one out of it would invent a price change out of
 * a cancellation.
 *
 * @see https://developer.revolut.com/docs/api/merchant/operations/retrieve-subscription.md
 */
enum RevolutScheduledActionType: string
{
    case Cancel = 'cancel';
    case ChangePlanVariation = 'change_plan_variation';
}
