<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Feature;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Isapp\CashierRevolut\Checkout\RevolutCheckoutSession;
use Isapp\CashierRevolut\Enums\RevolutChangePlanReason;
use Isapp\CashierRevolut\Exceptions\RevolutApiException;
use Isapp\CashierRevolut\Models\RevolutSubscription;
use Isapp\CashierRevolut\Models\RevolutSubscriptionItem;
use Isapp\CashierRevolut\Tests\Fixtures\RevolutApi;
use Isapp\CashierRevolut\Tests\Fixtures\User;
use Isapp\CashierRevolut\Tests\TestCase;
use Isapp\CashierSupport\Contracts\GatewayProvider;
use Isapp\CashierSupport\DTO\CheckoutRequest;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\Currency;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Enums\SwapTiming;
use Isapp\CashierSupport\Events\RefundProcessed;
use Isapp\CashierSupport\Events\SubscriptionPriceChangeScheduled;
use Isapp\CashierSupport\Exceptions\PaymentFailedException;
use Isapp\CashierSupport\Exceptions\SubscriptionUpdateFailure;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Facades\Cashier;

class RevolutGatewayTest extends TestCase
{
    private function gateway(): GatewayProvider
    {
        return Cashier::provider();
    }

    private function fakeRevolut(): void
    {
        Http::fake([
            '*/orders/*/payments' => Http::response(['id' => 'pay_1', 'state' => 'authorised']),
            '*/orders/ord_1' => Http::response(['id' => 'ord_1', 'amount' => 1500, 'currency' => 'EUR', 'state' => 'completed']),
            '*/orders' => Http::response(['id' => 'ord_1', 'amount' => 1500, 'currency' => 'EUR', 'token' => 'tok_1', 'checkout_url' => 'https://pay.revolut.com/x', 'state' => 'pending']),
            '*/customers/cus_1/payment-methods/pm_1' => Http::response([], 204),
            '*/customers/cus_1/payment-methods' => Http::response(['payment_methods' => [
                ['id' => 'pm_1', 'type' => 'card', 'brand' => 'visa', 'last_four' => '4242'],
            ]]),
            '*/customers/cus_1' => Http::response(['id' => 'cus_1', 'full_name' => 'Ada', 'email' => 'ada@example.com']),
            '*/customers' => Http::response(['id' => 'cus_1', 'full_name' => 'Ada', 'email' => 'ada@example.com']),
            '*/subscriptions/*/cancel' => Http::response(null, 204),
            '*/subscriptions/sub_1/cycles/cyc_1' => Http::response([
                'id' => 'cyc_1', 'state' => 'active', 'end_date' => '2099-08-01T00:00:00Z',
            ]),
            '*/subscriptions/sub_1' => Http::response([
                'id' => 'sub_1', 'state' => 'cancelled', 'current_cycle_id' => 'cyc_1',
            ]),
            '*/subscriptions' => Http::response(['id' => 'sub_1', 'state' => 'active']),
        ]);
    }

    private function customer(): User
    {
        return User::asRevolutCustomer('cus_1');
    }

    public function test_it_declares_honest_capabilities(): void
    {
        $gateway = $this->gateway();

        $this->assertTrue($gateway->supports(Capability::Charges));
        $this->assertTrue($gateway->supports(Capability::Subscriptions));
        $this->assertTrue($gateway->supports(Capability::SubscriptionSwapAtPeriodEnd));
        $this->assertFalse($gateway->supports(Capability::SubscriptionSwapImmediate));
        $this->assertTrue($gateway->supports(Capability::CheckoutAmount));
        $this->assertFalse($gateway->supports(Capability::CheckoutPrices));
        $this->assertTrue($gateway->supports(Capability::PaymentMethodsList));
        $this->assertFalse($gateway->supports(Capability::SubscriptionPause));
        $this->assertFalse($gateway->supports(Capability::PaymentMethodsAdd));
    }

    public function test_it_creates_a_customer_and_persists_the_id(): void
    {
        $this->fakeRevolut();
        $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com']);

        $customer = $this->gateway()->createCustomer($user);

        $this->assertSame('cus_1', $customer->id);
        $this->assertSame('cus_1', $user->customerId());
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/customers') && $request->method() === 'POST');
    }

    public function test_it_charges_via_an_order(): void
    {
        $this->fakeRevolut();

        $payment = $this->gateway()->charge($this->customer(), 1500, 'pm_1', ['currency' => 'EUR']);

        $this->assertSame('ord_1', $payment->id);
        $this->assertSame(1500, $payment->amount);
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/orders/ord_1/payments'));
    }

    public function test_it_refunds_an_order(): void
    {
        Http::fake(['*/orders/ord_1/refund' => Http::response(['id' => 're_1', 'amount' => 500, 'currency' => 'EUR'])]);

        $refund = $this->gateway()->refund($this->customer(), 'ord_1', ['amount' => 500, 'currency' => 'EUR']);

        $this->assertSame('re_1', $refund->id);
        $this->assertSame('ord_1', $refund->paymentId);
        $this->assertSame(500, $refund->amount);
    }

    public function test_a_refund_dispatches_the_refund_processed_event(): void
    {
        // Refunds is a declared capability, so its lifecycle event must fire.
        // It can only fire from here: Revolut's webhook catalogue has no refund
        // event at all, so there is no other path to it.
        Event::fake([RefundProcessed::class]);
        Http::fake(['*/orders/ord_1/refund' => Http::response(['id' => 're_1', 'amount' => 500, 'currency' => 'EUR'])]);
        $user = $this->customer();

        $this->gateway()->refund($user, 'ord_1', ['amount' => 500, 'currency' => 'EUR']);

        Event::assertDispatched(RefundProcessed::class, function (RefundProcessed $event) use ($user): bool {
            return $event->billable->is($user)
                && $event->refund->id === 're_1'
                && $event->refund->paymentId === 'ord_1'
                && $event->refund->amount === 500;
        });
    }

    public function test_a_failed_refund_dispatches_nothing(): void
    {
        Event::fake([RefundProcessed::class]);
        Http::fake(['*/orders/ord_1/refund' => Http::response(['message' => 'Order not found.'], 404)]);

        try {
            $this->gateway()->refund($this->customer(), 'ord_1', ['amount' => 500, 'currency' => 'EUR']);
            $this->fail('Expected RevolutApiException.');
        } catch (RevolutApiException) {
            // expected
        }

        Event::assertNotDispatched(RefundProcessed::class);
    }

    public function test_a_refund_rejected_in_the_body_throws_and_dispatches_nothing(): void
    {
        // The endpoint answers 201 "Refund order successfully created" — the
        // refund is an order in its own right, so a 2xx can still carry a
        // failed state. Dispatching on the bare 2xx would tell listeners money
        // moved when it did not.
        Event::fake([RefundProcessed::class]);
        Http::fake(['*/orders/ord_1/refund' => Http::response([
            'id' => 're_1', 'type' => 'refund', 'state' => 'failed', 'amount' => 500, 'currency' => 'EUR',
        ], 201)]);

        $this->expectException(PaymentFailedException::class);

        try {
            $this->gateway()->refund($this->customer(), 'ord_1', ['amount' => 500, 'currency' => 'EUR']);
        } finally {
            Event::assertNotDispatched(RefundProcessed::class);
        }
    }

    public function test_a_full_refund_dispatches_the_documented_payload(): void
    {
        // No amount and no currency: Revolut refunds the whole order in its own
        // currency. Uses the documented fixture, which comes back `processing`.
        Event::fake([RefundProcessed::class]);
        Http::fake(['*/orders/*/refund' => Http::response(RevolutApi::refund(), 201)]);
        $user = $this->customer();

        $refund = $this->gateway()->refund($user, RevolutApi::ORDER_ID);

        $this->assertSame(100, $refund->amount);
        Event::assertDispatched(RefundProcessed::class, function (RefundProcessed $event) use ($user): bool {
            return $event->billable->is($user)
                && $event->refund->paymentId === RevolutApi::ORDER_ID
                && $event->refund->amount === 100;
        });
    }

    public function test_it_creates_and_cancels_a_subscription(): void
    {
        $this->fakeRevolut();
        $user = $this->customer();

        $subscription = $this->gateway()->newSubscription($user, 'default', 'plan_var_1')->trialDays(14)->create('pm_1');

        $this->assertSame('sub_1', $subscription->id);
        $this->assertDatabaseHas('cashier_subscriptions', ['provider' => 'revolut', 'provider_id' => 'sub_1', 'type' => 'default']);

        $canceled = $this->gateway()->cancelSubscription($user, 'default');

        $this->assertSame('sub_1', $canceled->id);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/subscriptions/sub_1/cancel'));

        // The customer paid through the active cycle's end — the local record
        // keeps a real grace period (not ends_at = now()), so subscribed()
        // stays true until the cycle ends.
        $record = RevolutSubscription::query()->firstOrFail();
        $this->assertSame('2099-08-01T00:00:00+00:00', $record->ends_at?->toIso8601String());
        $this->assertTrue($user->subscribed('default'));

        // ...and so does the RETURNED DTO. It is the contract's declared return
        // type — an app that renders the cancellation from it must not be told
        // access ended immediately while the customer has paid through the cycle.
        $this->assertSame('2099-08-01T00:00:00+00:00', $canceled->endsAt?->toIso8601String());
    }

    public function test_cancelling_without_an_active_cycle_ends_access_now_in_both_places(): void
    {
        // No current_cycle_id — there is no paid-through date to honour, so
        // access ends now. The record and the returned DTO must agree; they are
        // the same value by construction.
        Http::fake([
            '*/subscriptions/*/cancel' => Http::response(null, 204),
            '*/subscriptions/sub_1' => Http::response(['id' => 'sub_1', 'state' => 'cancelled']),
            '*/subscriptions' => Http::response(['id' => 'sub_1', 'state' => 'active']),
        ]);
        $user = $this->customer();
        $this->gateway()->newSubscription($user, 'default', 'plan_var_1')->create('pm_1');

        $canceled = $this->gateway()->cancelSubscription($user, 'default');

        $record = RevolutSubscription::query()->firstOrFail();
        $this->assertNotNull($canceled->endsAt);
        $this->assertSame(
            $record->ends_at?->toIso8601String(),
            $canceled->endsAt?->toIso8601String(),
        );
        $this->assertFalse($user->subscribed('default'));
    }

    public function test_it_throws_for_unsupported_subscription_operations(): void
    {
        $user = $this->customer();

        $this->expectException(UnsupportedOperationException::class);
        $this->gateway()->pauseSubscription($user, 'default');
    }

    public function test_cancel_now_is_honestly_unsupported(): void
    {
        // Revolut cancellation only stops future cycles — no immediate cancel.
        $this->expectException(UnsupportedOperationException::class);
        $this->gateway()->cancelSubscriptionNow($this->customer(), 'default');
    }

    /**
     * Fake a subscription that Revolut still reports on plan_var_1 — which is
     * what it does until a scheduled change actually lands at cycle end.
     */
    private function fakeSwappableSubscription(string $reportedPlanVariation = 'plan_var_1'): void
    {
        Http::fake([
            '*/subscriptions/*/change-plan' => Http::response(null, 204),
            '*/subscriptions/sub_1' => Http::response([
                'id' => 'sub_1', 'state' => 'active', 'plan_variation_id' => $reportedPlanVariation,
            ]),
            '*/subscriptions' => Http::response([
                'id' => 'sub_1', 'state' => 'active', 'plan_variation_id' => 'plan_var_1',
            ]),
        ]);
    }

    public function test_it_schedules_a_plan_change_at_cycle_end(): void
    {
        Event::fake([SubscriptionPriceChangeScheduled::class]);
        $this->fakeSwappableSubscription();
        $user = $this->customer();
        $this->gateway()->newSubscription($user, 'default', 'plan_var_1')->create('pm_1');

        $subscription = $this->gateway()->swapSubscription($user, 'default', 'plan_var_2', SwapTiming::AtPeriodEnd, [
            'reason' => RevolutChangePlanReason::CustomerRequest,
        ]);

        $this->assertSame('sub_1', $subscription->id);

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/subscriptions/sub_1/change-plan')) {
                return false;
            }

            return $request->method() === 'POST'
                && $request->data() === [
                    'plan_variation_id' => 'plan_var_2',
                    'scheduled' => 'at_cycle_end',
                    'reason' => 'customer_request',
                ];
        });

        // Scheduling is not an update: nothing the customer is billed on has
        // changed yet, and the landing (a paid renewal) is what announces it.
        Event::assertDispatched(SubscriptionPriceChangeScheduled::class);
    }

    public function test_swapping_records_the_plan_revolut_reports_not_the_one_requested(): void
    {
        // The change only takes effect at the end of the current cycle, so the
        // customer is still on — and still paying for — plan_var_1. Writing
        // plan_var_2 locally right away would lie until the cycle rolls over.
        $this->fakeSwappableSubscription(reportedPlanVariation: 'plan_var_1');
        $user = $this->customer();
        $this->gateway()->newSubscription($user, 'default', 'plan_var_1')->create('pm_1');

        $this->gateway()->swapSubscription($user, 'default', 'plan_var_2', SwapTiming::AtPeriodEnd);

        $this->assertDatabaseHas('cashier_subscription_items', ['price' => 'plan_var_1']);
        $this->assertDatabaseMissing('cashier_subscription_items', ['price' => 'plan_var_2']);
        $this->assertTrue($user->subscribedToPrice('plan_var_1'));
        $this->assertFalse($user->subscribedToPrice('plan_var_2'));
    }

    public function test_a_failed_refetch_does_not_report_a_scheduled_swap_as_failed(): void
    {
        // The 204 on change-plan IS the commit: Revolut has scheduled the move.
        // If the follow-up read then fails, throwing would tell the customer the
        // upgrade did not happen — and Revolut would bill them for it anyway.
        Event::fake([SubscriptionPriceChangeScheduled::class]);
        Http::fake([
            '*/subscriptions/*/change-plan' => Http::response(null, 204),
            '*/subscriptions/sub_1' => Http::response(['message' => 'Not found.'], 404),
            '*/subscriptions' => Http::response([
                'id' => 'sub_1', 'state' => 'active', 'plan_variation_id' => 'plan_var_1',
            ]),
        ]);
        $user = $this->customer();
        $this->gateway()->newSubscription($user, 'default', 'plan_var_1')->create('pm_1');

        $subscription = $this->gateway()->swapSubscription($user, 'default', 'plan_var_2', SwapTiming::AtPeriodEnd);

        $this->assertSame('sub_1', $subscription->id);
        // Scheduling is not an update: nothing the customer is billed on has
        // changed yet, and the landing (a paid renewal) is what announces it.
        Event::assertDispatched(SubscriptionPriceChangeScheduled::class);

        // The local record stands: still the plan the customer is paying for.
        $this->assertDatabaseHas('cashier_subscription_items', ['price' => 'plan_var_1']);
    }

    public function test_a_sync_writes_the_item_row_with_an_unknown_quantity(): void
    {
        // Revolut has no per-subscription quantity, so `null` is the truthful
        // value — and now a writable one. Refusing to write the row at all (the
        // old behaviour, forced by a NOT NULL column) left subscribedToPrice()
        // false forever for any subscription the builder did not create.
        $this->fakeSwappableSubscription();
        $user = $this->customer();
        $this->gateway()->newSubscription($user, 'default', 'plan_var_1')->create('pm_1');
        RevolutSubscriptionItem::query()->delete();

        $this->gateway()->swapSubscription($user, 'default', 'plan_var_2', SwapTiming::AtPeriodEnd);

        $item = RevolutSubscriptionItem::query()->firstOrFail();
        $this->assertSame('plan_var_1', $item->price);
        $this->assertNull($item->quantity);
        $this->assertTrue($user->subscribedToPrice('plan_var_1'));
    }

    public function test_setting_a_quantity_is_honestly_unsupported(): void
    {
        // Revolut's quantity lives on the plan variation's items, fixed when the
        // plan is created — a subscription has none. The driver used to send a
        // `quantity` field the API does not document, so an app asking for five
        // seats was billed whatever the plan said.
        $this->fakeRevolut();

        $this->expectException(UnsupportedOperationException::class);
        $this->customer()->newSubscription('default', 'plan_var_1')->quantity(5);
    }

    public function test_creating_a_subscription_sends_no_quantity(): void
    {
        $this->fakeRevolut();

        $this->gateway()->newSubscription($this->customer(), 'default', 'plan_var_1')->create('pm_1');

        Http::assertSent(function ($request): bool {
            if (! str_ends_with($request->url(), '/api/subscriptions') || $request->method() !== 'POST') {
                return false;
            }

            return ! array_key_exists('quantity', $request->data());
        });
    }

    public function test_the_builder_itself_rejects_a_quantity(): void
    {
        // Reached directly through the gateway, bypassing the support-level
        // capability gate — the driver must refuse on its own, not rely on it.
        $this->fakeRevolut();

        $this->expectException(UnsupportedOperationException::class);
        $this->gateway()->newSubscription($this->customer(), 'default', 'plan_var_1')->quantity(5);
    }

    public function test_a_quantity_cannot_sneak_in_through_create_options(): void
    {
        // $options is a passthrough to the API, so it is also a back door for
        // the phantom field. Without this guard the throw above is decoration.
        $this->fakeRevolut();

        $this->expectException(UnsupportedOperationException::class);
        $this->gateway()->newSubscription($this->customer(), 'default', 'plan_var_1')
            ->create('pm_1', ['quantity' => 5]);
    }

    public function test_swapping_to_an_empty_plan_variation_fails(): void
    {
        $this->fakeSwappableSubscription();
        $user = $this->customer();
        $this->gateway()->newSubscription($user, 'default', 'plan_var_1')->create('pm_1');

        $this->expectException(SubscriptionUpdateFailure::class);
        $this->gateway()->swapSubscription($user, 'default', '', SwapTiming::AtPeriodEnd);
    }

    public function test_swapping_without_a_local_subscription_fails(): void
    {
        $this->fakeSwappableSubscription();

        $this->expectException(SubscriptionUpdateFailure::class);
        $this->gateway()->swapSubscription($this->customer(), 'default', 'plan_var_2', SwapTiming::AtPeriodEnd);
    }

    public function test_it_lists_payment_methods_but_cannot_add_one(): void
    {
        $this->fakeRevolut();
        $user = $this->customer();

        $methods = $this->gateway()->paymentMethods($user);

        $this->assertCount(1, $methods);
        $this->assertSame('pm_1', $methods[0]->id);

        $this->expectException(UnsupportedOperationException::class);
        $this->gateway()->addPaymentMethod($user, 'pm_x');
    }

    public function test_it_creates_a_checkout_session(): void
    {
        $this->fakeRevolut();

        $session = $this->gateway()->checkout(
            $this->customer(),
            CheckoutRequest::forAmount(1500, Currency::EUR),
        );

        $this->assertInstanceOf(RevolutCheckoutSession::class, $session);
        $this->assertSame('ord_1', $session->id());
        $this->assertSame('tok_1', $session->token());
        $this->assertSame('https://pay.revolut.com/x', $session->url());
    }

    public function test_cancel_without_a_local_subscription_fails(): void
    {
        $this->fakeRevolut();

        $this->expectException(SubscriptionUpdateFailure::class);
        $this->gateway()->cancelSubscription($this->customer(), 'default');
    }

    public function test_api_errors_are_raised_as_revolut_api_exceptions(): void
    {
        Http::fake(['*/customers' => Http::response(['message' => 'Invalid email.'], 422)]);

        try {
            $this->gateway()->createCustomer(User::query()->create(['email' => 'bad']));
            $this->fail('Expected RevolutApiException.');
        } catch (RevolutApiException $e) {
            $this->assertSame(422, $e->statusCode);
            $this->assertSame('Invalid email.', $e->getMessage());
        }
    }

    public function test_the_http_macro_returns_the_configured_request(): void
    {
        $this->fakeRevolut();

        /** @var PendingRequest $pending */
        $pending = Http::revolut();

        $response = $pending->get('/customers/cus_1');

        $this->assertSame('cus_1', $response->json('id'));
        Http::assertSent(fn ($request) => $request->hasHeader('Revolut-Api-Version')
            && $request->hasHeader('Idempotency-Key')
            && str_contains($request->url(), 'merchant.revolut.com/api/customers/cus_1'));
    }

    public function test_local_subscription_relations_resolve_to_revolut_models(): void
    {
        $this->fakeRevolut();
        $this->gateway()->newSubscription($this->customer(), 'default', 'plan_var_1')->create('pm_1');

        $subscription = RevolutSubscription::query()->firstOrFail();

        $this->assertInstanceOf(RevolutSubscription::class, $subscription);
        $this->assertSame('revolut', $subscription->getAttribute('provider'));
    }
}
