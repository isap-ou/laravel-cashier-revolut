<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Responses;

/**
 * Date formats produced by the Revolut Merchant API.
 *
 * The OpenAPI examples use RFC 3339 with microseconds
 * (e.g. "2023-09-29T14:58:36.079398Z"), which the default spatie/laravel-data
 * DATE_ATOM cast cannot parse; date-only values appear for trial ends.
 */
final class RevolutDateFormats
{
    public const FORMATS = [
        'Y-m-d\TH:i:s.up',
        'Y-m-d\TH:i:sp',
        \DATE_ATOM,
        'Y-m-d',
    ];
}
