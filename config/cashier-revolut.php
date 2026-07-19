<?php

declare(strict_types=1);

use Isapp\CashierRevolut\Enums\RevolutWebhookEvent;

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
    | The route lives in cashier-support and serves every driver, so its path and
    | middleware are configured there (cashier-support.webhook.*) and no longer
    | here. Register it with `php artisan cashier:webhook revolut`, which reads
    | the URL from that route.
    |
    | events is what that command subscribes the endpoint to — Revolut's whole
    | documented catalogue by default, which is deliberate. The driver applies
    | 8 of them to local state; the rest are delivered so they reach listeners
    | of Isapp\CashierSupport\Events\WebhookReceived, which carries the raw
    | body. That is the only route an app has to a dispute or a payout.
    |
    | Narrowing this list narrows what Revolut SENDS. An event removed here
    | stops reaching WebhookReceived too — it is not filtered on our side, it
    | never arrives. Removing anything the synchronizer applies (the ORDER_* and
    | SUBSCRIPTION_* cases it matches on) also stops local state being synced.
    |
    */
    'webhook' => [
        'signing_secret' => env('REVOLUT_WEBHOOK_SECRET'),
        'tolerance' => max(0, (int) env('REVOLUT_WEBHOOK_TOLERANCE', 300)),
        'sync_timeout' => max(1, (int) env('REVOLUT_WEBHOOK_SYNC_TIMEOUT', 5)),
        'events' => RevolutWebhookEvent::values()->all(),
    ],

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
