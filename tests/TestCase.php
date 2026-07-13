<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Isapp\CashierRevolut\CashierRevolutServiceProvider;
use Isapp\CashierSupport\CashierSupportServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\LaravelPdf\PdfServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataServiceProvider::class,
            PdfServiceProvider::class,
            CashierSupportServiceProvider::class,
            CashierRevolutServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cashier-support.default', 'revolut');
        $app['config']->set('cashier-revolut.secret_key', 'sk_test_secret');
        $app['config']->set('cashier-revolut.api_version', '2026-04-20');
        $app['config']->set('cashier-revolut.webhook.signing_secret', 'wsk_test_secret');
    }

    protected function defineDatabaseMigrations(): void
    {
        // No driver-named column: the customer identity lives in the support
        // package's cashier_customers table, loaded below.
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        // A second billable type, to prove an order webhook can resolve one.
        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(dirname(__DIR__).'/vendor/isapp/laravel-cashier-support/database/migrations');
    }
}
