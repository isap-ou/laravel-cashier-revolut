<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Isapp\CashierRevolut\CashierRevolutServiceProvider;
use Isapp\CashierRevolut\Models\RevolutSubscription;
use Isapp\CashierRevolut\RevolutGateway;
use Isapp\CashierRevolut\Tests\Fixtures\RevolutApi;
use Isapp\CashierRevolut\Tests\Fixtures\User;
use Isapp\CashierSupport\CashierSupportServiceProvider;
use Isapp\CashierSupport\Contracts\GatewayProvider;
use Isapp\CashierSupport\Testing\GatewayConformanceTestCase as BaseConformanceTestCase;
use Spatie\LaravelData\LaravelDataServiceProvider;

/**
 * Holds the Revolut driver to the provider-agnostic contract, via the suite support ships.
 *
 * The base drives the RAW gateway directly (not the guarded provider): every operation must
 * return its declared type or throw a catchable UnsupportedOperationException, and every method
 * behind a declared capability must actually work. Because the raw gateway makes real HTTP calls
 * for the operations Revolut supports, this subclass fakes the whole Merchant API surface with
 * the documented fixtures and hands the suite a billable that is already a Revolut customer with
 * a local subscription — so charge/refund/customer/subscription/payment-method operations resolve
 * instead of throwing on a missing customer or subscription record.
 */
final class GatewayConformanceTest extends BaseConformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A GET by subscription id is not in RevolutApi::fake()'s default map (it only covers
        // the /cancel, /change-plan and /cycles sub-resources), and cancelSubscription(), the
        // at-period-end swap and subscriptionLatestPayment() all refetch it. setup_order_id is
        // nulled: an active subscription's setup order is already paid, so subscriptionLatestPayment()
        // returns null rather than fetching a setup order the fake has no /orders/{id} route for.
        RevolutApi::fake([
            '*/subscriptions/'.RevolutApi::SUBSCRIPTION_ID => Http::response(RevolutApi::subscription([
                'state' => 'active',
                'setup_order_id' => null,
            ])),
        ]);
    }

    protected function gateway(): GatewayProvider
    {
        return $this->app->make(RevolutGateway::class);
    }

    protected function billable(): Model
    {
        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

        RevolutSubscription::query()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'type' => 'default',
            'provider' => 'revolut',
            'provider_id' => RevolutApi::SUBSCRIPTION_ID,
            'status' => 'active',
        ]);

        return $user;
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataServiceProvider::class,
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
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/vendor/isapp/laravel-cashier-support/database/migrations');
    }
}
