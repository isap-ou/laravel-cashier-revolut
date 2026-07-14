<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Feature;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Isapp\CashierRevolut\Tests\Fixtures\RevolutApi;
use Isapp\CashierRevolut\Tests\Fixtures\User;
use Isapp\CashierRevolut\Tests\TestCase;
use Isapp\CashierSupport\Facades\Cashier;

/**
 * An idempotency key identifies the OPERATION, not the request.
 *
 * The driver minted a fresh uuid per API call, which protects the transport — the
 * connector's ->retry() re-sends the same PendingRequest, so a transient failure
 * keeps its key — and nothing above it. A queued job that retries after the API
 * call already succeeded, or a caller that catches an exception thrown by a
 * listener AFTER the money moved, re-enters the method and arrives with a brand-new
 * key. Revolut sees a brand-new operation and charges (or refunds) the customer a
 * second time. The window is small; the loss is real money, and silent.
 *
 * Only the caller knows what the operation is — the job's uuid, a refund record's
 * id — so only the caller can name it. Revolut agrees: the key "can accept any
 * unique string value the merchant uses".
 */
class IdempotencyTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    private function sentKeys(): array
    {
        $keys = [];

        foreach (Http::recorded() as [$request]) {
            if ($request->method() !== 'POST') {
                continue;
            }

            $keys[] = $request->header('Idempotency-Key')[0] ?? '';
        }

        return $keys;
    }

    public function test_a_retried_refund_carries_the_key_it_was_given(): void
    {
        // Without this the second call is, to Revolut, a second refund.
        RevolutApi::fake();

        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

        $user->refund(RevolutApi::ORDER_ID, ['idempotency_key' => 'refund-job-42']);
        $user->refund(RevolutApi::ORDER_ID, ['idempotency_key' => 'refund-job-42']);

        $this->assertSame(['refund-job-42', 'refund-job-42'], $this->sentKeys());
    }

    public function test_a_charge_carries_the_operation_as_the_orders_own_reference(): void
    {
        // POST /orders accepts no Idempotency-Key at all — the header is documented
        // on the refund and the subscription create, and nowhere else. So Revolut
        // will not deduplicate a charge for us; it lets us do it ourselves, by
        // letting the order carry our reference and be looked up by it.
        Http::fake([
            '*/orders?*' => Http::response(['orders' => []]),
            '*/orders/*/payments' => Http::response(['id' => 'pay_1', 'state' => 'authorised']),
            '*/orders/ord_1' => Http::response(['id' => 'ord_1', 'amount' => 1500, 'currency' => 'EUR', 'state' => 'completed']),
            '*/orders' => Http::response(['id' => 'ord_1', 'amount' => 1500, 'currency' => 'EUR', 'state' => 'pending']),
        ]);

        $user = User::asRevolutCustomer('cus_1');

        $user->charge(1500, 'pm_1', ['idempotency_key' => 'charge-job-7']);

        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/orders')) {
                return false;
            }

            return $request->data()['merchant_order_data'] === ['reference' => 'charge-job-7'];
        });
    }

    public function test_a_retried_charge_finds_its_own_order_instead_of_charging_again(): void
    {
        // The whole point. The first attempt paid the customer's card and then the
        // job died — a listener threw, the worker timed out. On retry the driver
        // must NOT create a second order and must NOT pay again.
        Http::fake([
            '*/orders?*' => Http::response(['orders' => [[
                'id' => 'ord_1', 'amount' => 1500, 'currency' => 'EUR', 'state' => 'completed',
            ]]]),
            '*/orders/ord_1' => Http::response(['id' => 'ord_1', 'amount' => 1500, 'currency' => 'EUR', 'state' => 'completed']),
            '*/orders/*/payments' => Http::response(['id' => 'pay_1', 'state' => 'authorised']),
            '*/orders' => Http::response(['id' => 'ord_2', 'amount' => 1500, 'currency' => 'EUR', 'state' => 'pending']),
        ]);

        $user = User::asRevolutCustomer('cus_1');

        $payment = $user->charge(1500, 'pm_1', ['idempotency_key' => 'charge-job-7']);

        $this->assertSame('ord_1', $payment->id);

        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/orders'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/payments'));
    }

    public function test_a_retry_of_an_unpaid_order_pays_the_one_it_already_created(): void
    {
        // The first attempt died between creating the order and paying it. The order
        // exists and is pending: pay THAT one, rather than leaving an orphan behind
        // and creating another.
        Http::fake([
            '*/orders?*' => Http::response(['orders' => [[
                'id' => 'ord_1', 'amount' => 1500, 'currency' => 'EUR', 'state' => 'pending',
            ]]]),
            '*/orders/ord_1' => Http::response(['id' => 'ord_1', 'amount' => 1500, 'currency' => 'EUR', 'state' => 'completed']),
            '*/orders/*/payments' => Http::response(['id' => 'pay_1', 'state' => 'authorised']),
            '*/orders' => Http::response(['id' => 'ord_2', 'amount' => 1500, 'currency' => 'EUR', 'state' => 'pending']),
        ]);

        $user = User::asRevolutCustomer('cus_1');

        $user->charge(1500, 'pm_1', ['idempotency_key' => 'charge-job-7']);

        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/orders'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/orders/ord_1/payments'));
    }

    public function test_creating_a_subscription_carries_the_key_as_a_header_not_a_body_field(): void
    {
        RevolutApi::fake();

        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

        Cashier::provider()
            ->newSubscription($user, 'default', '850e8400-e29b-41d4-a716-446655440003')
            ->create(null, ['idempotency_key' => 'subscribe-job-9']);

        Http::assertSent(function ($request): bool {
            if (! str_ends_with($request->url(), '/subscriptions')) {
                return false;
            }

            return ($request->header('Idempotency-Key')[0] ?? null) === 'subscribe-job-9'
                && ! array_key_exists('idempotency_key', $request->data());
        });
    }

    public function test_an_unusable_key_is_refused(): void
    {
        RevolutApi::fake();

        $this->expectException(InvalidArgumentException::class);
        User::asRevolutCustomer(RevolutApi::CUSTOMER_ID)
            ->refund(RevolutApi::ORDER_ID, ['idempotency_key' => '']);
    }

    public function test_a_call_that_names_no_operation_still_gets_a_key(): void
    {
        // The random default stays: a DETERMINISTIC default would be worse than
        // none, because Revolut allows several partial refunds of one order and
        // would silently swallow the second legitimate one.
        RevolutApi::fake();

        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

        $user->refund(RevolutApi::ORDER_ID);
        $user->refund(RevolutApi::ORDER_ID);

        $keys = $this->sentKeys();

        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1]);
    }
}
