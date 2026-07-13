<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Enums;

/**
 * Reasons accepted by "Change a subscription plan" per the Revolut OpenAPI
 * specification (merchant-2026-04-20).
 *
 * The reason is informational — it does not affect how Revolut processes the
 * scheduled plan change.
 *
 * @see https://developer.revolut.com/docs/api/merchant/operations/change-subscription-plan
 */
enum RevolutChangePlanReason: string
{
    case CustomerRequest = 'customer_request';
    case MerchantRequest = 'merchant_request';
}
