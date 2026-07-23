<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Isapp\CashierRevolut\Http\Responses\SubscriptionResponse;
use Isapp\CashierRevolut\Models\RevolutSubscription;
use Isapp\CashierRevolut\RevolutGateway;
use Isapp\CashierRevolut\Tests\Fixtures\RevolutApi;
use Isapp\CashierRevolut\Tests\Fixtures\User;
use Isapp\CashierRevolut\Tests\TestCase;
use Isapp\CashierSupport\DTO\Payment;
use Isapp\CashierSupport\Exceptions\CashierException;

/**
 * subscriptionLatestPayment() — the driver's implementation of the support contract that completes
 * a subscription created `pending`. Revolut leaves a setup order the customer pays in the Checkout
 * Widget; the order carries the widget `token`. The setup order's id is NOT on the retrieve
 * response (`GET /subscriptions/{id}` returns no `setup_order_*` in api-version 2026-04-20, verified
 * against sandbox — only `POST /subscriptions` does), so the id is read off the current billing
 * cycle (`GET /subscriptions/{id}/cycles/{cycle_id}` → `order_id`). This reads the subscription for
 * its `current_cycle_id`, the cycle for its `order_id`, then the order, and returns the neutral
 * DTO\Payment whose `clientSecret` is that token. Absence answers null (no subscription, a plan with
 * no setup order such as a pure trial, or a setup order already paid — gated on the order state).
 */
class SubscriptionLatestPaymentTest extends TestCase
{
    private function subscriptionRecord(User $user, string $providerId = 'sub_1'): void
    {
        RevolutSubscription::query()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'type' => 'default',
            'provider' => 'revolut',
            'provider_id' => $providerId,
            'status' => 'incomplete',
        ]);
    }

    public function test_subscription_response_maps_the_setup_order_id(): void
    {
        $response = SubscriptionResponse::from(RevolutApi::subscription());

        $this->assertSame('a50e8400-e29b-41d4-a716-446655440005', $response->setupOrderId);
    }

    public function test_it_returns_a_payment_carrying_the_widget_token(): void
    {
        Http::fake([
            // The setup order id is on the CYCLE, not the retrieve response (2026-04-20).
            '*/subscriptions/sub_1/cycles/cyc_1' => Http::response([
                'id' => 'cyc_1',
                'state' => 'pending',
                'order_id' => 'ord_setup',
            ]),
            '*/subscriptions/sub_1' => Http::response([
                'id' => 'sub_1',
                'state' => 'pending',
                'current_cycle_id' => 'cyc_1',
                // No setup_order_* fields — the real retrieve shape.
            ]),
            '*/orders/ord_setup' => Http::response(RevolutApi::order([
                'id' => 'ord_setup',
                'token' => 'tok_widget',
                'state' => 'pending',
                'amount' => 1500,
                'currency' => 'EUR',
            ])),
        ]);

        $user = User::asRevolutCustomer('cus_1');
        $this->subscriptionRecord($user);

        $payment = app(RevolutGateway::class)->subscriptionLatestPayment($user);

        $this->assertInstanceOf(Payment::class, $payment);
        $this->assertSame('tok_widget', $payment->clientSecret);
        $this->assertTrue($payment->requiresPaymentMethod());
        $this->assertSame(1500, $payment->amount);
        $this->assertSame('EUR', $payment->currency->getCode());
    }

    public function test_it_is_null_once_the_subscription_is_active(): void
    {
        // Paying the setup order flips the subscription to active — nothing outstanding. The state
        // gate short-circuits before any cycle read.
        Http::fake([
            '*/subscriptions/sub_1' => Http::response([
                'id' => 'sub_1',
                'state' => 'active',
                'current_cycle_id' => 'cyc_1',
            ]),
        ]);

        $user = User::asRevolutCustomer('cus_1');
        $this->subscriptionRecord($user);

        $this->assertNull(app(RevolutGateway::class)->subscriptionLatestPayment($user));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/cycles/'));
    }

    public function test_a_later_cycle_renewal_order_is_not_read_as_a_setup_payment(): void
    {
        // An overdue subscription's current cycle names a renewal order (auto-charged, no widget
        // token). Gating on the subscription state keeps it off the setup-payment path entirely.
        Http::fake([
            '*/subscriptions/sub_1/cycles/cyc_9' => Http::response([
                'id' => 'cyc_9',
                'order_id' => 'ord_renewal',
            ]),
            '*/subscriptions/sub_1' => Http::response([
                'id' => 'sub_1',
                'state' => 'overdue',
                'current_cycle_id' => 'cyc_9',
            ]),
        ]);

        $user = User::asRevolutCustomer('cus_1');
        $this->subscriptionRecord($user);

        $this->assertNull(app(RevolutGateway::class)->subscriptionLatestPayment($user));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/orders/'));
    }

    public function test_it_is_null_when_the_cycle_has_no_setup_order(): void
    {
        // A pure-trial cycle has no setup order to pay.
        Http::fake([
            '*/subscriptions/sub_1/cycles/cyc_1' => Http::response([
                'id' => 'cyc_1',
                'trial' => true,
                // No order_id.
            ]),
            '*/subscriptions/sub_1' => Http::response([
                'id' => 'sub_1',
                'state' => 'pending',
                'current_cycle_id' => 'cyc_1',
            ]),
        ]);

        $user = User::asRevolutCustomer('cus_1');
        $this->subscriptionRecord($user);

        $this->assertNull(app(RevolutGateway::class)->subscriptionLatestPayment($user));
    }

    public function test_a_failed_cycle_read_throws_rather_than_reporting_no_payment(): void
    {
        // Regression: the cycle read is load-bearing here, so a gateway failure must surface as a
        // CashierException — not be swallowed to null, which would read as "nothing to pay" and
        // leave a genuinely pending subscription forever incomplete.
        Http::fake([
            '*/subscriptions/sub_1/cycles/cyc_1' => Http::response(['message' => 'boom'], 422),
            '*/subscriptions/sub_1' => Http::response([
                'id' => 'sub_1',
                'state' => 'pending',
                'current_cycle_id' => 'cyc_1',
            ]),
        ]);

        $user = User::asRevolutCustomer('cus_1');
        $this->subscriptionRecord($user);

        $this->expectException(CashierException::class);
        app(RevolutGateway::class)->subscriptionLatestPayment($user);
    }

    public function test_it_is_null_without_a_local_subscription(): void
    {
        $this->assertNull(
            app(RevolutGateway::class)->subscriptionLatestPayment(User::asRevolutCustomer('cus_1')),
        );
    }
}
