<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\ServiceProvider;
use Isapp\CashierRevolut\Commands\WebhookCommand;
use Isapp\CashierRevolut\Http\RevolutConnector;
use Isapp\CashierRevolut\Models\RevolutInvoice;
use Isapp\CashierRevolut\Models\RevolutSubscription;
use Isapp\CashierRevolut\Models\RevolutSubscriptionItem;
use Isapp\CashierRevolut\Webhooks\RevolutWebhookHandler;
use Isapp\CashierSupport\Facades\Cashier;

class CashierRevolutServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cashier-revolut.php', 'cashier-revolut');

        // The framework resolves the Http client factory implicitly (the Http
        // facade caches its own copy). Share one container instance so that
        // injected factories and Http::fake() stubs are the same object.
        $this->app->singletonIf(Factory::class);

        $this->app->singleton(RevolutConnector::class);

        $this->app->singleton(RevolutWebhookHandler::class, static function (): RevolutWebhookHandler {
            $secret = config('cashier-revolut.webhook.signing_secret');

            return new RevolutWebhookHandler(
                signingSecret: is_string($secret) ? $secret : null,
                tolerance: (int) config('cashier-revolut.webhook.tolerance', 300),
            );
        });

        $this->app->singleton(RevolutGateway::class);
    }

    public function boot(): void
    {
        // Register this driver's model classes in the per-driver registry —
        // safe alongside other drivers and app-published config.
        Cashier::useModels(RevolutGateway::DRIVER, [
            'subscription' => RevolutSubscription::class,
            'subscription_item' => RevolutSubscriptionItem::class,
            'invoice' => RevolutInvoice::class,
        ]);

        $app = $this->app;

        Factory::macro('revolut', fn (): PendingRequest => $app->make(RevolutConnector::class)->request());

        Cashier::extend('revolut', static fn (Application $app): RevolutGateway => $app->make(RevolutGateway::class));

        $this->loadRoutesFrom(__DIR__.'/../routes/webhook.php');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'cashier-revolut');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/cashier-revolut.php' => config_path('cashier-revolut.php'),
            ], 'cashier-revolut-config');

            $this->publishes([
                __DIR__.'/../lang' => $this->app->langPath('vendor/cashier-revolut'),
            ], 'cashier-revolut-lang');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'cashier-revolut-migrations');

            $this->commands([
                WebhookCommand::class,
            ]);
        }
    }
}
