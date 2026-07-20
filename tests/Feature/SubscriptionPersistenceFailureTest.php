<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Isapp\CashierRevolut\Exceptions\RevolutApiException;
use Isapp\CashierRevolut\RevolutGateway;
use Isapp\CashierRevolut\Tests\Fixtures\RevolutApi;
use Isapp\CashierRevolut\Tests\Fixtures\User;
use Isapp\CashierRevolut\Tests\TestCase;
use Isapp\CashierSupport\Exceptions\CashierException;

/**
 * The window between "Revolut has a subscription" and "we know about it".
 *
 * `create()` wrapped only the API call in its try/catch; the local write sat outside it and
 * outside any transaction. So a DB failure after a 201 left a subscription **live at Revolut,
 * billing the customer, with no local record** — and the app was handed a bare QueryException
 * saying "database error", which reads like nothing happened.
 *
 * There is no self-repair for it, and that is what made it a release blocker rather than a
 * nuisance: every subsequent SUBSCRIPTION_* webhook finds no local record, so the synchronizer
 * returns false, the controller answers 200, and Revolut never redelivers. The customer is
 * charged every cycle, forever, and nothing in the system says so.
 *
 * Support cannot roll the gateway back — POST /subscriptions has already happened and Revolut
 * has no undo. What it CAN do is make sure the app is told which subscription is orphaned, in
 * an exception it already catches, so the failure is actionable instead of silent.
 */
class SubscriptionPersistenceFailureTest extends TestCase
{
    public function test_a_failed_local_write_names_the_subscription_that_is_live_at_revolut(): void
    {
        RevolutApi::fake();

        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

        // The most faithful way to make the write fail for real, rather than mocking the model
        // and proving only that a mock was called: take the table away after the API call has
        // already been faked as successful.
        Schema::drop('cashier_subscriptions');

        try {
            app(RevolutGateway::class)
                ->newSubscription($user, 'default', 'plan_var_1')
                ->create();

            $this->fail('Expected the failed local write to surface.');
        } catch (RevolutApiException $e) {
            // Catchable as a CashierException — an app already catches that around billing.
            $this->assertInstanceOf(CashierException::class, $e);

            // ...and it must carry the id, because that is the only thing that makes the
            // orphan findable in the Revolut dashboard.
            $this->assertStringContainsString(RevolutApi::SUBSCRIPTION_ID, $e->getMessage());
            // ...and say plainly that money is now moving.
            $this->assertStringContainsString('billing', strtolower($e->getMessage()));

            // The cause is not swallowed: whoever debugs this needs the QueryException.
            $this->assertNotNull($e->getPrevious());
        }
    }

    public function test_a_successful_create_still_persists_exactly_one_record(): void
    {
        // The guard must not tax the ordinary path — and the local writes are now wrapped in a
        // transaction, so this also proves the transaction commits rather than merely not
        // throwing.
        RevolutApi::fake();

        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

        app(RevolutGateway::class)
            ->newSubscription($user, 'default', 'plan_var_1')
            ->create();

        $this->assertDatabaseCount('cashier_subscriptions', 1);
        $this->assertDatabaseHas('cashier_subscriptions', [
            'provider' => 'revolut',
            'provider_id' => RevolutApi::SUBSCRIPTION_ID,
        ]);
    }

    public function test_a_partial_local_write_is_rolled_back_rather_than_left_half_done(): void
    {
        // persist() writes the subscription row and then its item row. Without a transaction a
        // failure between them left a subscription with no item — which is not a crash, it is
        // worse: subscribedToPrice() answers false forever for a subscription that is billing.
        RevolutApi::fake();

        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

        Schema::drop('cashier_subscription_items');

        try {
            app(RevolutGateway::class)
                ->newSubscription($user, 'default', 'plan_var_1')
                ->create();

            $this->fail('Expected the failed item write to surface.');
        } catch (RevolutApiException) {
            // The subscription row must NOT survive on its own.
            $this->assertDatabaseCount('cashier_subscriptions', 0);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }
}
