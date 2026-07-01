<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Enums;

/**
 * Order types per the Revolut OpenAPI specification: a refund is itself an
 * order of type "refund" — only "payment" orders may be booked as payments.
 */
enum RevolutOrderType: string
{
    case Payment = 'payment';
    case Refund = 'refund';
    case Chargeback = 'chargeback';
}
