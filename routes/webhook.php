<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Isapp\CashierRevolut\Webhooks\RevolutWebhookController;

Route::post(config('cashier-revolut.webhook.path', 'webhook/revolut'), RevolutWebhookController::class)
    ->middleware(config('cashier-revolut.webhook.middleware', ['throttle:60,1']))
    ->name('cashier-revolut.webhook');
