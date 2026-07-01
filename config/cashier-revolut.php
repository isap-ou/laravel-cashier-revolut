<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | When sandbox is true the driver targets the Revolut sandbox host. Sandbox
    | and production are entirely separate and use different API keys.
    |
    */
    'sandbox' => (bool) env('REVOLUT_SANDBOX', false),

    /*
    |--------------------------------------------------------------------------
    | API credentials
    |--------------------------------------------------------------------------
    |
    | The Merchant API secret key is used for all server-side calls. The public
    | key is only used client-side by the Checkout Widget.
    |
    */
    'secret_key' => env('REVOLUT_SECRET_KEY'),
    'public_key' => env('REVOLUT_PUBLIC_KEY'),

    /*
    |--------------------------------------------------------------------------
    | API version
    |--------------------------------------------------------------------------
    |
    | Sent as the required Revolut-Api-Version header on every request.
    |
    */
    'api_version' => env('REVOLUT_API_VERSION', '2026-04-20'),

    /*
    |--------------------------------------------------------------------------
    | Webhook
    |--------------------------------------------------------------------------
    |
    | signing_secret is the value returned when the webhook was created
    | (wsk_...). tolerance is the maximum age (seconds) of a webhook request
    | before it is rejected as a possible replay.
    |
    */
    'webhook' => [
        'signing_secret' => env('REVOLUT_WEBHOOK_SECRET'),
        'path' => env('REVOLUT_WEBHOOK_PATH', 'webhook/revolut'),
        'tolerance' => max(0, (int) env('REVOLUT_WEBHOOK_TOLERANCE', 300)),
        'middleware' => ['throttle:60,1'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Billable model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model carrying the revolut_customer_id column. Used by the
    | webhook synchronizer to resolve which entity an order event belongs to
    | (subscription events resolve their owner from the local record instead).
    | When null, order webhooks are acknowledged but no invoice is recorded.
    |
    */
    'billable_model' => env('CASHIER_BILLABLE_MODEL'),

    /*
    |--------------------------------------------------------------------------
    | HTTP client
    |--------------------------------------------------------------------------
    */
    'http' => [
        'timeout' => (int) env('REVOLUT_HTTP_TIMEOUT', 30),
        'retries' => (int) env('REVOLUT_HTTP_RETRIES', 2),
    ],
];
