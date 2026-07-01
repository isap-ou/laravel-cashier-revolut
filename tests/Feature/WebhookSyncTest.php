<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Isapp\CashierRevolut\Models\RevolutSubscription;
use Isapp\CashierRevolut\Tests\Fixtures\RevolutApi;
use Isapp\CashierRevolut\Tests\Fixtures\User;
use Isapp\CashierRevolut\Tests\TestCase;
use Isapp\CashierRevolut\Webhooks\RevolutWebhookHandler;
use Isapp\CashierRevolut\Webhooks\RevolutWebhookSynchronizer;
use Isapp\CashierSupport\DTO\WebhookPayload;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
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

    private function payload(string $event, string $id): WebhookPayload
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
            'name' => 'default',
            'provider' => 'revolut',
            'provider_id' => RevolutApi::SUBSCRIPTION_ID,
            'status' => SubscriptionStatus::Active,
        ]);

        $this->synchronizer()->handle($this->payload('SUBSCRIPTION_CANCELLED', RevolutApi::SUBSCRIPTION_ID));

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

        $this->synchronizer()->handle($this->payload('ORDER_COMPLETED', RevolutApi::ORDER_ID));

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

        $payload = $this->payload('ORDER_COMPLETED', RevolutApi::ORDER_ID);
        $this->synchronizer()->handle($payload);
        $this->synchronizer()->handle($payload);

        $this->assertDatabaseCount('cashier_invoices', 1);
    }

    public function test_an_order_without_a_resolvable_owner_is_skipped(): void
    {
        RevolutApi::fake([
            '*/orders/'.RevolutApi::ORDER_ID => Http::response(RevolutApi::order(['state' => 'completed'])),
        ]);

        $this->synchronizer()->handle($this->payload('ORDER_COMPLETED', RevolutApi::ORDER_ID));

        $this->assertDatabaseCount('cashier_invoices', 0);
    }
}
