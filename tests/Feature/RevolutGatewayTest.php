<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Feature;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Isapp\CashierRevolut\Checkout\RevolutCheckoutSession;
use Isapp\CashierRevolut\Exceptions\RevolutApiException;
use Isapp\CashierRevolut\Models\RevolutSubscription;
use Isapp\CashierRevolut\Tests\Fixtures\User;
use Isapp\CashierRevolut\Tests\TestCase;
use Isapp\CashierSupport\Contracts\GatewayProvider;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\PaymentStatus;
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
            '*/subscriptions/sub_1' => Http::response(['id' => 'sub_1', 'state' => 'cancelled']),
            '*/subscriptions' => Http::response(['id' => 'sub_1', 'state' => 'active']),
        ]);
    }

    private function customer(): User
    {
        return User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'revolut_customer_id' => 'cus_1']);
    }

    public function test_it_declares_honest_capabilities(): void
    {
        $gateway = $this->gateway();

        $this->assertTrue($gateway->supports(Capability::Charges));
        $this->assertTrue($gateway->supports(Capability::Subscriptions));
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
        $this->assertSame('cus_1', $user->refresh()->revolut_customer_id);
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

    public function test_it_creates_and_cancels_a_subscription(): void
    {
        $this->fakeRevolut();
        $user = $this->customer();

        $subscription = $this->gateway()->newSubscription($user, 'default', 'plan_var_1')->trialDays(14)->create('pm_1');

        $this->assertSame('sub_1', $subscription->id);
        $this->assertDatabaseHas('cashier_subscriptions', ['provider' => 'revolut', 'provider_id' => 'sub_1', 'name' => 'default']);

        $canceled = $this->gateway()->cancelSubscription($user, 'default');

        $this->assertSame('sub_1', $canceled->id);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/subscriptions/sub_1/cancel'));
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

    public function test_it_throws_when_swapping(): void
    {
        $this->expectException(UnsupportedOperationException::class);
        $this->gateway()->swapSubscription($this->customer(), 'default', 'plan_var_2');
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

        $session = $this->gateway()->checkout($this->customer(), 'plan_var_1', ['amount' => 1500, 'currency' => 'EUR']);

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
