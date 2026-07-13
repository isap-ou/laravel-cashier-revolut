<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Feature;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Isapp\CashierRevolut\Checkout\RevolutCheckoutSession;
use Isapp\CashierRevolut\Tests\Fixtures\User;
use Isapp\CashierRevolut\Tests\TestCase;
use Isapp\CashierSupport\DTO\CheckoutRequest;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\CheckoutMode;
use Isapp\CashierSupport\Enums\Currency;
use Isapp\CashierSupport\Enums\SwapTiming;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Facades\Cashier;

/**
 * What Revolut can honour, stated in the capabilities an app can ask about.
 *
 * Revolut's change-plan is scheduled at_cycle_end and nothing else, and its
 * checkout takes an amount, not a catalogue of price ids. Both used to hide
 * behind capabilities that merely said "swap" and "checkout" — so an app could
 * only learn the truth by branching on the driver name, or by catching a bare
 * InvalidArgumentException thrown from inside the driver.
 */
class GranularCapabilitiesTest extends TestCase
{
    private function fakeRevolut(): void
    {
        Http::fake([
            '*/orders' => Http::response([
                'id' => 'ord_1',
                'amount' => 1500,
                'currency' => 'EUR',
                'token' => 'tok_1',
                'checkout_url' => 'https://pay.revolut.com/x',
                'state' => 'pending',
            ]),
            '*/subscriptions/sub_1/change-plan' => Http::response(null, 204),
            '*/subscriptions/sub_1' => Http::response([
                'id' => 'sub_1',
                'state' => 'active',
                'plan_variation_id' => 'plan_var_2',
            ]),
        ]);
    }

    public function test_revolut_declares_only_the_timing_and_the_shape_it_can_honour(): void
    {
        $this->assertTrue(Cashier::supports(Capability::SubscriptionSwapAtPeriodEnd));
        $this->assertFalse(Cashier::supports(Capability::SubscriptionSwapImmediate));

        $this->assertTrue(Cashier::supports(Capability::CheckoutAmount));
        $this->assertFalse(Cashier::supports(Capability::CheckoutPrices));
    }

    public function test_an_immediate_swap_is_refused_rather_than_quietly_deferred(): void
    {
        $user = User::asRevolutCustomer('cus_1');

        $this->expectException(UnsupportedOperationException::class);
        $user->swapSubscription('default', 'plan_var_2', SwapTiming::Immediate);
    }

    public function test_the_default_swap_is_refused_too_because_it_means_immediate(): void
    {
        // The unsurprising default is Stripe's semantics. Revolut cannot do it,
        // so a caller who never thought about timing gets told, not surprised
        // next month.
        $user = User::asRevolutCustomer('cus_1');

        $this->expectException(UnsupportedOperationException::class);
        $user->swapSubscription('default', 'plan_var_2');
    }

    public function test_a_deferred_swap_goes_through(): void
    {
        $this->fakeRevolut();

        $user = User::asRevolutCustomer('cus_1');
        $user->subscriptions()->create([
            'type' => 'default',
            'provider' => 'revolut',
            'provider_id' => 'sub_1',
            'status' => 'active',
        ]);

        $subscription = $user->swapSubscription('default', 'plan_var_2', SwapTiming::AtPeriodEnd);

        $this->assertSame('sub_1', $subscription->id);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/subscriptions/sub_1/change-plan'));
    }

    public function test_a_price_checkout_is_a_cashier_exception_not_a_bare_argument_error(): void
    {
        $this->fakeRevolut();

        $user = User::asRevolutCustomer('cus_1');

        $this->expectException(UnsupportedOperationException::class);
        $user->checkout(CheckoutRequest::forPrices(['price_monthly' => 1]));
    }

    public function test_a_direct_provider_call_with_a_price_request_is_refused_before_any_http(): void
    {
        // Billable's gate is bypassed when the gateway is called directly. The
        // driver must still refuse, or it would POST an order with no amount.
        $this->fakeRevolut();

        $this->expectException(UnsupportedOperationException::class);
        Cashier::provider()->checkout(
            User::asRevolutCustomer('cus_1'),
            CheckoutRequest::forPrices(['price_monthly' => 1]),
        );
    }

    public function test_a_zero_amount_request_never_reaches_the_orders_endpoint(): void
    {
        // The old driver guarded this with a bare InvalidArgumentException. The
        // guard moved, it did not disappear: a request built through Data::from()
        // skips the named constructor's validation, so the shape check is what
        // stops a zero-amount order.
        $this->fakeRevolut();

        try {
            Cashier::provider()->checkout(
                User::asRevolutCustomer('cus_1'),
                CheckoutRequest::from(['amount' => 0, 'currency' => 'EUR']),
            );
            $this->fail('A zero amount must not be accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('positive', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_metadata_revolut_would_reject_fails_before_the_call_not_as_an_opaque_400(): void
    {
        // Revolut's order metadata takes string values only (and caps keys,
        // lengths and pair count). An int user id would come back as a bare 400
        // wrapped in a generic API failure — name the violation instead.
        $this->fakeRevolut();

        $user = User::asRevolutCustomer('cus_1');

        try {
            $user->checkout(CheckoutRequest::forAmount(
                amount: 1500,
                currency: Currency::EUR,
                metadata: ['user_id' => 42],
            ));
            $this->fail('A non-string metadata value must not be sent.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('must be a string', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_metadata_revolut_accepts_is_sent_with_the_order(): void
    {
        $this->fakeRevolut();

        User::asRevolutCustomer('cus_1')->checkout(CheckoutRequest::forAmount(
            amount: 1500,
            currency: Currency::EUR,
            metadata: ['user_id' => '42'],
        ));

        Http::assertSent(fn ($request) => $request->data()['metadata'] === ['user_id' => '42']);
    }

    public function test_the_order_links_the_customer_through_the_nested_object_the_api_defines(): void
    {
        // POST /orders has no top-level customer_id — the customer is a nested
        // object. Sending the flat key left every order attached to nobody: the
        // widget offered no saved card, and the card used was never linked to
        // the customer, so a later charge had no payment method to reach for.
        $this->fakeRevolut();

        User::asRevolutCustomer('cus_1')->checkout(
            CheckoutRequest::forAmount(1500, Currency::EUR),
        );

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $body['customer'] === ['id' => 'cus_1']
                && ! array_key_exists('customer_id', $body);
        });
    }

    public function test_a_charge_links_its_customer_the_same_way(): void
    {
        // The order body is shared with charge(), so the flat customer_id
        // detached those orders too.
        Http::fake([
            '*/orders/*/payments' => Http::response(['id' => 'pay_1', 'state' => 'authorised']),
            '*/orders/ord_1' => Http::response([
                'id' => 'ord_1', 'amount' => 1500, 'currency' => 'EUR', 'state' => 'completed',
            ]),
            '*/orders' => Http::response([
                'id' => 'ord_1', 'amount' => 1500, 'currency' => 'EUR', 'state' => 'pending',
            ]),
        ]);

        User::asRevolutCustomer('cus_1')->charge(1500, 'pm_1');

        Http::assertSent(function ($request): bool {
            if (! str_ends_with($request->url(), '/orders')) {
                return true;
            }

            return $request->data()['customer'] === ['id' => 'cus_1'];
        });
    }

    public function test_a_mode_an_order_cannot_carry_is_refused_not_downgraded(): void
    {
        // An order is a one-off payment; POST /orders has no mode at all, and a
        // Revolut subscription is created through the subscriptions API. Silently
        // downgrading would hand the app a session reporting Subscription over an
        // order that will never renew.
        $this->fakeRevolut();

        $this->expectException(UnsupportedOperationException::class);
        User::asRevolutCustomer('cus_1')->checkout(CheckoutRequest::forAmount(
            amount: 1500,
            currency: Currency::EUR,
            mode: CheckoutMode::Subscription,
        ));
    }

    public function test_a_metadata_key_with_a_trailing_newline_is_refused(): void
    {
        // PCRE's $ matches before a trailing newline, so an unanchored pattern
        // would pass "user_id\n" straight through to a 400.
        $this->fakeRevolut();

        $this->expectException(InvalidArgumentException::class);
        User::asRevolutCustomer('cus_1')->checkout(CheckoutRequest::forAmount(
            amount: 1500,
            currency: Currency::EUR,
            metadata: ["user_id\n" => '42'],
        ));
    }

    public function test_an_amount_checkout_creates_an_order_and_exposes_its_token(): void
    {
        $this->fakeRevolut();

        $user = User::asRevolutCustomer('cus_1');

        $session = $user->checkout(CheckoutRequest::forAmount(
            amount: 1500,
            currency: Currency::EUR,
            description: 'One coffee',
            successUrl: 'https://app.test/ok',
        ));

        $this->assertInstanceOf(RevolutCheckoutSession::class, $session);
        $this->assertSame('ord_1', $session->id());
        $this->assertSame('tok_1', $session->clientSecret());
        $this->assertSame('https://pay.revolut.com/x', $session->url());

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $body['amount'] === 1500
                && $body['currency'] === 'EUR'
                && $body['description'] === 'One coffee'
                && $body['redirect_url'] === 'https://app.test/ok';
        });
    }
}
