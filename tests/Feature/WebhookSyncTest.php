<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Isapp\CashierRevolut\Models\RevolutSubscription;
use Isapp\CashierRevolut\Models\RevolutSubscriptionItem;
use Isapp\CashierRevolut\Tests\Fixtures\RevolutApi;
use Isapp\CashierRevolut\Tests\Fixtures\User;
use Isapp\CashierRevolut\Tests\TestCase;
use Isapp\CashierRevolut\Webhooks\RevolutWebhookHandler;
use Isapp\CashierRevolut\Webhooks\RevolutWebhookSynchronizer;
use Isapp\CashierSupport\DTO\WebhookPayload;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Events\PaymentFailed;
use Isapp\CashierSupport\Events\PaymentSucceeded;
use Isapp\CashierSupport\Events\SubscriptionCanceled;
use Isapp\CashierSupport\Facades\Cashier;

class WebhookSyncTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cashier-revolut.billable_model', User::class);
    }

    private function synchronizer(): RevolutWebhookSynchronizer
    {
        return $this->app->make(RevolutWebhookSynchronizer::class);
    }

    private function payload(string $event): WebhookPayload
    {
        return $this->app->make(RevolutWebhookHandler::class)
            ->parseWebhook(json_encode(RevolutApi::webhookEvent($event)) ?: '{}', []);
    }

    public function test_a_dashboard_cancellation_updates_the_local_subscription(): void
    {
        Event::fake([SubscriptionCanceled::class]);
        RevolutApi::fake([
            '*/subscriptions/'.RevolutApi::SUBSCRIPTION_ID => Http::response(RevolutApi::subscription([
                'state' => 'cancelled',
            ])),
        ]);

        $user = User::query()->create(['name' => 'Ada', 'revolut_customer_id' => RevolutApi::CUSTOMER_ID]);

        RevolutSubscription::query()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'type' => 'default',
            'provider' => 'revolut',
            'provider_id' => RevolutApi::SUBSCRIPTION_ID,
            'status' => SubscriptionStatus::Active,
        ]);

        $this->synchronizer()->handle($this->payload('SUBSCRIPTION_CANCELLED'));

        $record = RevolutSubscription::query()->firstOrFail();
        $this->assertSame(SubscriptionStatus::Canceled, $record->status);
        $this->assertNotNull($record->ends_at);

        Event::assertDispatched(SubscriptionCanceled::class, function (SubscriptionCanceled $event) use ($user): bool {
            return $event->billable->is($user)
                && $event->subscription->status === SubscriptionStatus::Canceled;
        });

        // The billable's query-side now reflects the cancellation.
        $this->assertFalse($user->subscribed('default'));
    }

    public function test_a_subscription_sync_catches_up_with_a_landed_plan_change(): void
    {
        // A plan change is scheduled for cycle end and Revolut has no webhook
        // for it, so the local item keeps naming the old variation until a
        // later subscription sync sees Revolut report the new one.
        RevolutApi::fake([
            '*/subscriptions/'.RevolutApi::SUBSCRIPTION_ID => Http::response(RevolutApi::subscription([
                'state' => 'active',
                'plan_variation_id' => 'plan_var_new',
            ])),
        ]);

        $user = User::query()->create(['name' => 'Ada', 'revolut_customer_id' => RevolutApi::CUSTOMER_ID]);

        $subscription = RevolutSubscription::query()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'type' => 'default',
            'provider' => 'revolut',
            'provider_id' => RevolutApi::SUBSCRIPTION_ID,
            'status' => SubscriptionStatus::Active,
        ]);

        RevolutSubscriptionItem::query()->create([
            'subscription_id' => $subscription->getKey(),
            'provider' => 'revolut',
            'price' => 'plan_var_old',
            'quantity' => 3,
        ]);

        // Status is unchanged (active → active): the plan must still sync, so
        // this also pins the sync ahead of the unchanged-status short-circuit.
        $this->synchronizer()->handle($this->payload('SUBSCRIPTION_INITIATED'));

        $item = RevolutSubscriptionItem::query()->firstOrFail();
        $this->assertSame('plan_var_new', $item->price);
        // The variation changed, the quantity did not.
        $this->assertSame(3, $item->quantity);
        $this->assertTrue($user->subscribedToPrice('plan_var_new'));
    }

    public function test_a_paid_renewal_cycle_catches_the_local_plan_up_with_a_landed_swap(): void
    {
        // The renewal signal is ORDER_COMPLETED carrying subscription_data with
        // billing_reason=cycle_billing — Revolut fires no webhook for the plan
        // change itself, and the SUBSCRIPTION_* events never fire on a normal
        // renewal. This is the only moment the deferred swap actually lands.
        RevolutApi::fake([
            '*/orders/'.RevolutApi::ORDER_ID => Http::response(RevolutApi::order([
                'state' => 'completed',
                'customer' => ['id' => RevolutApi::CUSTOMER_ID],
                'subscription_data' => [
                    'subscription_id' => RevolutApi::SUBSCRIPTION_ID,
                    'billing_reason' => 'cycle_billing',
                    'active_cycle_id' => 'cyc-0002',
                ],
            ])),
            '*/subscriptions/'.RevolutApi::SUBSCRIPTION_ID => Http::response(RevolutApi::subscription([
                'state' => 'active',
                'plan_variation_id' => 'plan_var_new',
            ])),
        ]);

        $user = User::query()->create(['name' => 'Ada', 'revolut_customer_id' => RevolutApi::CUSTOMER_ID]);

        $subscription = RevolutSubscription::query()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'type' => 'default',
            'provider' => 'revolut',
            'provider_id' => RevolutApi::SUBSCRIPTION_ID,
            'status' => SubscriptionStatus::Active,
        ]);

        RevolutSubscriptionItem::query()->create([
            'subscription_id' => $subscription->getKey(),
            'provider' => 'revolut',
            'price' => 'plan_var_old',
            'quantity' => 1,
        ]);

        $this->synchronizer()->handle($this->payload('ORDER_COMPLETED'));

        $this->assertSame('plan_var_new', RevolutSubscriptionItem::query()->firstOrFail()->price);
        $this->assertSame(1, RevolutSubscriptionItem::query()->count());
        $this->assertTrue($user->subscribedToPrice('plan_var_new'));
    }

    public function test_a_failing_plan_resync_never_costs_the_renewal_payment(): void
    {
        // The plan resync is an extra API call bolted onto the payment path.
        // If it explodes, the money must still be booked — a 404 here would
        // otherwise be swallowed as "deterministic" and the payment lost.
        Event::fake([PaymentSucceeded::class]);
        RevolutApi::fake([
            '*/orders/'.RevolutApi::ORDER_ID => Http::response(RevolutApi::order([
                'state' => 'completed',
                'customer' => ['id' => RevolutApi::CUSTOMER_ID],
                'subscription_data' => [
                    'subscription_id' => RevolutApi::SUBSCRIPTION_ID,
                    'billing_reason' => 'cycle_billing',
                ],
            ])),
            '*/subscriptions/'.RevolutApi::SUBSCRIPTION_ID => Http::response(['message' => 'Gone.'], 404),
        ]);

        $user = User::query()->create(['name' => 'Ada', 'revolut_customer_id' => RevolutApi::CUSTOMER_ID]);

        RevolutSubscription::query()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'type' => 'default',
            'provider' => 'revolut',
            'provider_id' => RevolutApi::SUBSCRIPTION_ID,
            'status' => SubscriptionStatus::Active,
        ]);

        $this->synchronizer()->handle($this->payload('ORDER_COMPLETED'));

        $this->assertDatabaseHas('cashier_invoices', [
            'provider_id' => RevolutApi::ORDER_ID,
            'status' => PaymentStatus::Succeeded->value,
        ]);
        Event::assertDispatched(PaymentSucceeded::class);
    }

    public function test_a_completed_order_persists_a_local_invoice_and_dispatches_payment_succeeded(): void
    {
        Event::fake([PaymentSucceeded::class]);
        RevolutApi::fake([
            '*/orders/'.RevolutApi::ORDER_ID => Http::response(RevolutApi::order([
                'state' => 'completed',
                'customer' => ['id' => RevolutApi::CUSTOMER_ID],
            ])),
        ]);

        $user = User::query()->create(['name' => 'Ada', 'revolut_customer_id' => RevolutApi::CUSTOMER_ID]);

        $this->synchronizer()->handle($this->payload('ORDER_COMPLETED'));

        $this->assertDatabaseHas('cashier_invoices', [
            'provider' => 'revolut',
            'provider_id' => RevolutApi::ORDER_ID,
            'owner_id' => $user->getKey(),
            'amount' => 500,
        ]);

        Event::assertDispatched(PaymentSucceeded::class, function (PaymentSucceeded $event) use ($user): bool {
            return $event->billable->is($user)
                && $event->payment->status === PaymentStatus::Succeeded
                && $event->payment->amount === 500;
        });

        // The gateway's local InvoiceOperations now serve the record.
        $invoices = Cashier::provider()->invoices($user);
        $this->assertCount(1, $invoices);
        $this->assertSame(RevolutApi::ORDER_ID, $invoices[0]->id);
        $this->assertSame(500, $invoices[0]->amount);

        $found = Cashier::provider()->findInvoice($user, RevolutApi::ORDER_ID);
        $this->assertNotNull($found);
    }

    public function test_sync_is_idempotent_for_duplicate_deliveries(): void
    {
        RevolutApi::fake([
            '*/orders/'.RevolutApi::ORDER_ID => Http::response(RevolutApi::order([
                'state' => 'completed',
                'customer' => ['id' => RevolutApi::CUSTOMER_ID],
            ])),
        ]);

        $user = User::query()->create(['name' => 'Ada', 'revolut_customer_id' => RevolutApi::CUSTOMER_ID]);

        $payload = $this->payload('ORDER_COMPLETED');
        $this->synchronizer()->handle($payload);
        $this->synchronizer()->handle($payload);

        $this->assertDatabaseCount('cashier_invoices', 1);
    }

    public function test_an_order_without_a_resolvable_owner_is_skipped(): void
    {
        RevolutApi::fake([
            '*/orders/'.RevolutApi::ORDER_ID => Http::response(RevolutApi::order(['state' => 'completed'])),
        ]);

        $this->synchronizer()->handle($this->payload('ORDER_COMPLETED'));

        $this->assertDatabaseCount('cashier_invoices', 0);
    }

    public function test_a_stale_ends_at_is_cleared_when_the_subscription_is_active_again(): void
    {
        RevolutApi::fake([
            '*/subscriptions/'.RevolutApi::SUBSCRIPTION_ID => Http::response(RevolutApi::subscription([
                'state' => 'active',
            ])),
        ]);

        $user = User::query()->create(['name' => 'Ada', 'revolut_customer_id' => RevolutApi::CUSTOMER_ID]);

        RevolutSubscription::query()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'type' => 'default',
            'provider' => 'revolut',
            'provider_id' => RevolutApi::SUBSCRIPTION_ID,
            'status' => SubscriptionStatus::Canceled,
            'ends_at' => now()->subDay(),
        ]);

        $this->synchronizer()->handle($this->payload('SUBSCRIPTION_OVERDUE'));

        $record = RevolutSubscription::query()->firstOrFail();
        $this->assertSame(SubscriptionStatus::Active, $record->status);
        // Mirror truth: an active subscription has no end — the stale ends_at
        // must be cleared, or subscribed() stays false while Revolut bills.
        $this->assertNull($record->ends_at);
        $this->assertTrue($user->subscribed('default'));
    }

    public function test_a_refund_type_order_is_never_booked_as_a_payment(): void
    {
        Event::fake([PaymentSucceeded::class]);
        RevolutApi::fake([
            '*/orders/'.RevolutApi::ORDER_ID => Http::response(RevolutApi::order([
                'type' => 'refund',
                'state' => 'completed',
                'customer' => ['id' => RevolutApi::CUSTOMER_ID],
            ])),
        ]);

        User::query()->create(['name' => 'Ada', 'revolut_customer_id' => RevolutApi::CUSTOMER_ID]);

        $this->synchronizer()->handle($this->payload('ORDER_COMPLETED'));

        $this->assertDatabaseCount('cashier_invoices', 0);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    public function test_a_redelivery_does_not_dispatch_payment_succeeded_twice(): void
    {
        Event::fake([PaymentSucceeded::class]);
        RevolutApi::fake([
            '*/orders/'.RevolutApi::ORDER_ID => Http::response(RevolutApi::order([
                'state' => 'completed',
                'customer' => ['id' => RevolutApi::CUSTOMER_ID],
            ])),
        ]);

        User::query()->create(['name' => 'Ada', 'revolut_customer_id' => RevolutApi::CUSTOMER_ID]);

        $payload = $this->payload('ORDER_COMPLETED');
        $this->synchronizer()->handle($payload);
        $this->synchronizer()->handle($payload);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    public function test_a_declined_payment_dispatches_an_explicit_failed_payment(): void
    {
        Event::fake([PaymentFailed::class, PaymentSucceeded::class]);
        RevolutApi::fake([
            // After a declined attempt the order state typically stays pending.
            '*/orders/'.RevolutApi::ORDER_ID => Http::response(RevolutApi::order([
                'state' => 'pending',
                'customer' => ['id' => RevolutApi::CUSTOMER_ID],
            ])),
        ]);

        User::query()->create(['name' => 'Ada', 'revolut_customer_id' => RevolutApi::CUSTOMER_ID]);

        $this->synchronizer()->handle($this->payload('ORDER_PAYMENT_DECLINED'));

        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertDispatched(PaymentFailed::class, function (PaymentFailed $event): bool {
            return $event->payment->status === PaymentStatus::Failed;
        });
    }

    public function test_a_missing_resource_is_acknowledged_instead_of_retrying_forever(): void
    {
        RevolutApi::fake([
            '*/orders/'.RevolutApi::ORDER_ID => Http::response(RevolutApi::error(404, 'Order not found'), 404),
        ]);

        // Must not throw — a deterministic 404 would loop deliveries forever.
        $this->synchronizer()->handle($this->payload('ORDER_COMPLETED'));

        $this->assertDatabaseCount('cashier_invoices', 0);
    }

    public function test_duplicate_subscription_delivery_does_not_redispatch_events(): void
    {
        Event::fake([SubscriptionCanceled::class]);
        RevolutApi::fake([
            '*/subscriptions/'.RevolutApi::SUBSCRIPTION_ID => Http::response(RevolutApi::subscription([
                'state' => 'cancelled',
            ])),
        ]);

        $user = User::query()->create(['name' => 'Ada', 'revolut_customer_id' => RevolutApi::CUSTOMER_ID]);

        RevolutSubscription::query()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'type' => 'default',
            'provider' => 'revolut',
            'provider_id' => RevolutApi::SUBSCRIPTION_ID,
            'status' => SubscriptionStatus::Active,
        ]);

        $payload = $this->payload('SUBSCRIPTION_CANCELLED');
        $this->synchronizer()->handle($payload);
        $this->synchronizer()->handle($payload);

        Event::assertDispatchedTimes(SubscriptionCanceled::class, 1);
    }
}
