<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Isapp\CashierRevolut\Models\RevolutInvoice;
use Isapp\CashierRevolut\Models\RevolutSubscription;
use Isapp\CashierRevolut\Models\RevolutSubscriptionItem;
use Isapp\CashierRevolut\Tests\Fixtures\RevolutApi;
use Isapp\CashierRevolut\Tests\Fixtures\Team;
use Isapp\CashierRevolut\Tests\Fixtures\User;
use Isapp\CashierRevolut\Tests\TestCase;
use Isapp\CashierRevolut\Webhooks\RevolutWebhookSynchronizer;
use Isapp\CashierSupport\Enums\BillingReason;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Events\PaymentFailed;
use Isapp\CashierSupport\Events\PaymentSucceeded;
use Isapp\CashierSupport\Events\SubscriptionCanceled;
use Isapp\CashierSupport\Events\SubscriptionPastDue;
use Isapp\CashierSupport\Events\SubscriptionRenewed;
use Isapp\CashierSupport\Events\SubscriptionUpdated;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Facades\Cashier;

class WebhookSyncTest extends TestCase
{
    private function synchronizer(): RevolutWebhookSynchronizer
    {
        return $this->app->make(RevolutWebhookSynchronizer::class);
    }

    /**
     * The raw Revolut body, which is what handle() takes now.
     *
     * It used to build a DTO\WebhookPayload through parseWebhook(), and the round trip
     * was pure ceremony: the synchronizer dug the Revolut-native 'event' key back out of
     * the DTO's supposedly provider-agnostic $data (support#46) and never read the
     * agnostic field beside it.
     *
     * @return array<string, mixed>
     */
    private function payload(string $event): array
    {
        return RevolutApi::webhookEvent($event);
    }

    public function test_an_unmapped_event_is_not_handled_and_is_not_an_error(): void
    {
        // THE rule support#47 puts on a driver, tested where it lives — the class that
        // decides. It is the exact inversion of what this package did: parseWebhook()
        // threw UnexpectedWebhookEventException for PAYOUT_INITIATED and the driver's own
        // controller caught that ABOVE the WebhookReceived dispatch, so all 14 of the
        // events Revolut documents and this driver does not map reached no listener at
        // all. Throwing here would strand them again, one layer down.
        //
        // No Http::fake(): nothing may be refetched for an event we have no handler for.
        $this->assertFalse($this->synchronizer()->handle([
            'event' => 'PAYOUT_INITIATED',
            'id' => 'po_1',
        ]));
    }

    public function test_a_mapped_event_with_no_resource_id_is_not_handled_either(): void
    {
        // Mapped, but the body names nothing to apply it to — so nothing was applied, and
        // false is the honest answer. It shares the arm with an unmapped event because the
        // caller's question is the same one: did local state change?
        $this->assertFalse($this->synchronizer()->handle(['event' => 'ORDER_COMPLETED']));
    }

    public function test_an_applied_event_says_so(): void
    {
        // The true arm, asserted rather than assumed: it is what support dispatches
        // WebhookHandled on, and the other three tests here only pin false.
        RevolutApi::fake();

        $this->assertTrue($this->synchronizer()->handle($this->payload('ORDER_COMPLETED')));
    }

    public function test_a_dashboard_cancellation_updates_the_local_subscription(): void
    {
        Event::fake([SubscriptionCanceled::class]);
        RevolutApi::fake([
            '*/subscriptions/'.RevolutApi::SUBSCRIPTION_ID => Http::response(RevolutApi::subscription([
                'state' => 'cancelled',
            ])),
        ]);

        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

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

        Event::assertDispatched(SubscriptionCanceled::class, function (SubscriptionCanceled $event) use ($record, $user): bool {
            return $event->billable->is($user)
                && $event->subscription->status === SubscriptionStatus::Canceled
                // The event's DTO must carry the same grace period as the
                // record. A listener revoking access reads the DTO, not the row.
                && $event->subscription->endsAt?->toIso8601String() === $record->ends_at?->toIso8601String();
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

        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

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
        // A stored quantity could only have come from the phantom field the
        // driver used to send; Revolut never billed it. The sync replaces it
        // with the truth: this gateway has no per-subscription quantity.
        $this->assertNull($item->quantity);
        $this->assertTrue($user->subscribedToPrice('plan_var_new'));
    }

    public function test_writing_the_item_row_for_the_first_time_is_not_a_plan_change(): void
    {
        // Now that any sync may create the row, a subscription that never had
        // one gets it written on first sighting. That is a backfill, not a plan
        // change — announcing SubscriptionUpdated would be a lie.
        Event::fake([SubscriptionUpdated::class]);
        RevolutApi::fake([
            '*/orders/'.RevolutApi::ORDER_ID => Http::response(RevolutApi::order([
                'state' => 'completed',
                'customer' => ['id' => RevolutApi::CUSTOMER_ID],
                'subscription_data' => [
                    'subscription_id' => RevolutApi::SUBSCRIPTION_ID,
                    'billing_reason' => 'cycle_billing',
                ],
            ])),
            '*/subscriptions/'.RevolutApi::SUBSCRIPTION_ID => Http::response(RevolutApi::subscription([
                'state' => 'active',
                'plan_variation_id' => 'plan_var_1',
            ])),
        ]);

        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

        RevolutSubscription::query()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'type' => 'default',
            'provider' => 'revolut',
            'provider_id' => RevolutApi::SUBSCRIPTION_ID,
            'status' => SubscriptionStatus::Active,
        ]);

        $this->synchronizer()->handle($this->payload('ORDER_COMPLETED'));

        // The row is written — that is the point of the change...
        $this->assertSame('plan_var_1', RevolutSubscriptionItem::query()->firstOrFail()->price);
        // ...but no plan changed.
        Event::assertNotDispatched(SubscriptionUpdated::class);
    }

    public function test_a_paid_renewal_dispatches_the_subscription_renewed_event_with_its_invoice(): void
    {
        // The missing signal: a plain renewal produced only PaymentSucceeded and
        // an orphan invoice row. Nothing said "this subscription renewed", and
        // nothing tied the invoice to the cycle it settled.
        Event::fake([SubscriptionRenewed::class, SubscriptionUpdated::class]);
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
            '*/subscriptions/'.RevolutApi::SUBSCRIPTION_ID.'/cycles/cyc-0002' => Http::response([
                'id' => 'cyc-0002',
                'state' => 'active',
                'start_date' => '2026-07-01T00:00:00Z',
                'end_date' => '2026-08-01T00:00:00Z',
            ]),
            '*/subscriptions/'.RevolutApi::SUBSCRIPTION_ID => Http::response(RevolutApi::subscription([
                'state' => 'active',
                'plan_variation_id' => 'plan_var_1',
            ])),
        ]);

        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

        $subscription = RevolutSubscription::query()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'type' => 'default',
            'provider' => 'revolut',
            'provider_id' => RevolutApi::SUBSCRIPTION_ID,
            'status' => SubscriptionStatus::Active,
        ]);

        $this->synchronizer()->handle($this->payload('ORDER_COMPLETED'));

        // The invoice now says which subscription and which cycle it paid for.
        $invoice = RevolutInvoice::query()->firstOrFail();
        $this->assertSame($subscription->getKey(), $invoice->subscription_id);
        $this->assertSame(BillingReason::SubscriptionCycle, $invoice->billing_reason);
        $this->assertSame('2026-08-01T00:00:00+00:00', $invoice->period_end?->toIso8601String());
        $this->assertTrue($invoice->subscription()->first()?->is($subscription));

        // The subscription's paid-through date advanced with it.
        $this->assertSame(
            '2026-08-01T00:00:00+00:00',
            $subscription->fresh()?->currentPeriodEnd()?->toIso8601String(),
        );

        Event::assertDispatched(SubscriptionRenewed::class, function (SubscriptionRenewed $event) use ($user): bool {
            return $event->billable->is($user)
                && $event->invoice->billingReason === BillingReason::SubscriptionCycle
                && $event->subscription->currentPeriodEnd?->toIso8601String() === '2026-08-01T00:00:00+00:00';
        });

        // A renewal is not a plan change.
        Event::assertNotDispatched(SubscriptionUpdated::class);
    }

    public function test_a_redelivered_renewal_does_not_announce_the_renewal_twice(): void
    {
        // Revolut redelivers. The writes are absolute and may safely repeat, but
        // a second SubscriptionRenewed would have a listener extend entitlement
        // twice and send a second receipt for one payment.
        Event::fake([SubscriptionRenewed::class, PaymentSucceeded::class]);
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
            ])),
        ]);

        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

        RevolutSubscription::query()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'type' => 'default',
            'provider' => 'revolut',
            'provider_id' => RevolutApi::SUBSCRIPTION_ID,
            'status' => SubscriptionStatus::Active,
        ]);

        $this->synchronizer()->handle($this->payload('ORDER_COMPLETED'));
        $this->synchronizer()->handle($this->payload('ORDER_COMPLETED'));

        Event::assertDispatchedTimes(SubscriptionRenewed::class, 1);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        $this->assertSame(1, RevolutInvoice::query()->count());
    }

    public function test_an_overdue_subscription_dispatches_past_due_not_updated(): void
    {
        // The typed event is the whole signal now, and it is what an app listens to.
        // It used to be SubscriptionUpdated, and there used to be a DTO carrying an
        // agnostic name beside it — which nothing read. This is what survived.
        Event::fake([SubscriptionPastDue::class, SubscriptionUpdated::class]);
        RevolutApi::fake([
            '*/subscriptions/'.RevolutApi::SUBSCRIPTION_ID => Http::response(RevolutApi::subscription([
                'state' => 'overdue',
            ])),
        ]);

        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

        RevolutSubscription::query()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'type' => 'default',
            'provider' => 'revolut',
            'provider_id' => RevolutApi::SUBSCRIPTION_ID,
            'status' => SubscriptionStatus::Active,
        ]);

        $this->synchronizer()->handle($this->payload('SUBSCRIPTION_OVERDUE'));

        Event::assertDispatched(SubscriptionPastDue::class);
        Event::assertNotDispatched(SubscriptionUpdated::class);
    }

    public function test_a_missing_billing_cycle_never_costs_the_subscription_write(): void
    {
        // The cycle is enrichment; the status write is not. A cancelled
        // subscription's cycle may simply be gone — and handle() acknowledges
        // 404s, so letting that abort the sync would lose the cancellation for
        // good, with no redelivery to repair it.
        Event::fake([SubscriptionCanceled::class]);
        RevolutApi::fake([
            '*/subscriptions/'.RevolutApi::SUBSCRIPTION_ID.'/cycles/*' => Http::response(['message' => 'Gone.'], 404),
            '*/subscriptions/'.RevolutApi::SUBSCRIPTION_ID => Http::response(RevolutApi::subscription([
                'state' => 'cancelled',
            ])),
        ]);

        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

        RevolutSubscription::query()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'type' => 'default',
            'provider' => 'revolut',
            'provider_id' => RevolutApi::SUBSCRIPTION_ID,
            'status' => SubscriptionStatus::Active,
            'current_period_end' => '2026-08-01T00:00:00Z',
        ]);

        $this->synchronizer()->handle($this->payload('SUBSCRIPTION_CANCELLED'));

        $record = RevolutSubscription::query()->firstOrFail();
        $this->assertSame(SubscriptionStatus::Canceled, $record->status);
        Event::assertDispatched(SubscriptionCanceled::class);

        // And the period we already knew is not erased by a failed look-up.
        $this->assertSame('2026-08-01T00:00:00+00:00', $record->currentPeriodEnd()?->toIso8601String());
    }

    public function test_the_first_subscription_invoice_is_linked_but_is_not_a_renewal(): void
    {
        Event::fake([SubscriptionRenewed::class]);
        RevolutApi::fake([
            '*/orders/'.RevolutApi::ORDER_ID => Http::response(RevolutApi::order([
                'state' => 'completed',
                'customer' => ['id' => RevolutApi::CUSTOMER_ID],
                'subscription_data' => [
                    'subscription_id' => RevolutApi::SUBSCRIPTION_ID,
                    'billing_reason' => 'setup_intent',
                ],
            ])),
            '*/subscriptions/'.RevolutApi::SUBSCRIPTION_ID => Http::response(RevolutApi::subscription([
                'state' => 'active',
            ])),
        ]);

        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

        $subscription = RevolutSubscription::query()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'type' => 'default',
            'provider' => 'revolut',
            'provider_id' => RevolutApi::SUBSCRIPTION_ID,
            'status' => SubscriptionStatus::Active,
        ]);

        $this->synchronizer()->handle($this->payload('ORDER_COMPLETED'));

        // Linked — otherwise every billing history starts with a hole.
        $invoice = RevolutInvoice::query()->firstOrFail();
        $this->assertSame($subscription->getKey(), $invoice->subscription_id);
        $this->assertSame(BillingReason::SubscriptionCreate, $invoice->billing_reason);

        // ...but the setup order is not a renewal.
        Event::assertNotDispatched(SubscriptionRenewed::class);
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

        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

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

        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

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

        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

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

        // Invoices is now a deferred (unsupported) capability, so gateway-surface
        // invoice access refuses — the webhook synchronizer still persists the
        // local record above, which is what this test asserts.
        $this->expectException(UnsupportedOperationException::class);
        Cashier::provider()->invoices($user);
    }

    public function test_sync_is_idempotent_for_duplicate_deliveries(): void
    {
        RevolutApi::fake([
            '*/orders/'.RevolutApi::ORDER_ID => Http::response(RevolutApi::order([
                'state' => 'completed',
                'customer' => ['id' => RevolutApi::CUSTOMER_ID],
            ])),
        ]);

        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

        $payload = $this->payload('ORDER_COMPLETED');
        $this->synchronizer()->handle($payload);
        $this->synchronizer()->handle($payload);

        $this->assertDatabaseCount('cashier_invoices', 1);
    }

    public function test_an_order_webhook_resolves_a_billable_of_any_type(): void
    {
        // The old reverse lookup searched a single configured class, so an order
        // for a Team found nothing and its invoice was silently dropped.
        RevolutApi::fake([
            '*/orders/'.RevolutApi::ORDER_ID => Http::response(RevolutApi::order([
                'state' => 'completed',
                'customer' => ['id' => RevolutApi::CUSTOMER_ID],
            ])),
        ]);

        $team = Team::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

        $this->synchronizer()->handle($this->payload('ORDER_COMPLETED'));

        $invoice = RevolutInvoice::query()->firstOrFail();
        $this->assertSame($team->getMorphClass(), $invoice->owner_type);
        $this->assertTrue($invoice->owner()->first()?->is($team));
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

        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

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

        User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

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

        User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

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

        User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

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
        // And false, not true: the resource is gone, so nothing was applied. The old
        // controller dispatched WebhookHandled unconditionally after this call, so this
        // is a real change to what an app sees, and it was going unasserted — flipping
        // the arm back to `true` left the whole suite green.
        $this->assertFalse($this->synchronizer()->handle($this->payload('ORDER_COMPLETED')));

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

        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

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
