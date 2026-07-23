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

/**
 * subscriptionLatestPayment() — the driver's implementation of the support contract that completes
 * a subscription created `pending`. Revolut leaves a setup order the customer pays in the Checkout
 * Widget; the subscription resource names the order id, the order carries the widget `token`. This
 * reads the subscription, then the order, and returns the neutral DTO\Payment whose `clientSecret`
 * is that token — replacing the earlier raw-OrderResponse workaround, which leaked an @internal
 * type across the public gateway boundary. Absence answers null (no subscription / setup order paid).
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
            '*/subscriptions/sub_1' => Http::response([
                'id' => 'sub_1',
                'state' => 'pending',
                'setup_order_id' => 'ord_setup',
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

    public function test_it_is_null_once_the_setup_order_is_gone(): void
    {
        Http::fake([
            '*/subscriptions/sub_1' => Http::response([
                'id' => 'sub_1',
                'state' => 'active',
                // No setup_order_id: the subscription has already been paid.
            ]),
        ]);

        $user = User::asRevolutCustomer('cus_1');
        $this->subscriptionRecord($user);

        $this->assertNull(app(RevolutGateway::class)->subscriptionLatestPayment($user));
    }

    public function test_it_is_null_without_a_local_subscription(): void
    {
        $this->assertNull(
            app(RevolutGateway::class)->subscriptionLatestPayment(User::asRevolutCustomer('cus_1')),
        );
    }
}
