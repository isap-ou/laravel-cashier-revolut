<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Isapp\CashierRevolut\Tests\Fixtures\RevolutApi;
use Isapp\CashierRevolut\Tests\Fixtures\User;
use Isapp\CashierRevolut\Tests\TestCase;
use Isapp\CashierSupport\Contracts\GatewayProvider;
use Isapp\CashierSupport\DTO\CustomerDetails;
use Isapp\CashierSupport\Facades\Cashier;

/**
 * date_of_birth is a documented, sandbox-verified optional field on the Merchant API customer
 * object (POST/PATCH /api/customers), carried into the driver through the CustomerDetails options
 * escape hatch exactly as `phone` is — request-side only, no round-trip into the neutral Customer
 * DTO. These tests pin the outgoing request body: the field is on the wire when supplied, and the
 * null-omit contract keeps it off the wire when it is not (or is not a string).
 */
class CustomerDateOfBirthTest extends TestCase
{
    private function gateway(): GatewayProvider
    {
        return Cashier::provider();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertCustomerBodySent(string $method, callable $assert): void
    {
        Http::assertSent(function ($request) use ($method, $assert): bool {
            if ($request->method() !== $method || ! str_contains($request->url(), '/customers')) {
                return false;
            }
            // The change-plan / payment-methods routes also contain "customers"; the customer
            // create/update bodies are the ones with a full_name.
            if (str_contains($request->url(), '/payment-methods')) {
                return false;
            }

            return $assert($request->data());
        });
    }

    public function test_create_sends_date_of_birth_from_options(): void
    {
        RevolutApi::fake();
        $user = User::query()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

        $this->gateway()->createCustomer($user, new CustomerDetails(
            name: 'Ada Lovelace',
            email: 'ada@example.com',
            options: ['date_of_birth' => '1990-01-01'],
        ));

        $this->assertCustomerBodySent('POST', fn (array $data): bool => ($data['date_of_birth'] ?? null) === '1990-01-01');
    }

    public function test_create_omits_date_of_birth_when_not_supplied(): void
    {
        RevolutApi::fake();
        $user = User::query()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

        $this->gateway()->createCustomer($user, new CustomerDetails(
            name: 'Ada Lovelace',
            email: 'ada@example.com',
        ));

        $this->assertCustomerBodySent('POST', fn (array $data): bool => ! array_key_exists('date_of_birth', $data));
    }

    public function test_create_omits_a_non_string_date_of_birth(): void
    {
        RevolutApi::fake();
        $user = User::query()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

        $this->gateway()->createCustomer($user, new CustomerDetails(
            name: 'Ada Lovelace',
            email: 'ada@example.com',
            options: ['date_of_birth' => 19900101],
        ));

        $this->assertCustomerBodySent('POST', fn (array $data): bool => ! array_key_exists('date_of_birth', $data));
    }

    public function test_update_sends_date_of_birth_from_options(): void
    {
        RevolutApi::fake();
        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID, [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
        ]);

        $this->gateway()->updateCustomer($user, new CustomerDetails(
            name: 'Ada Byron',
            options: ['date_of_birth' => '1815-12-10'],
        ));

        $this->assertCustomerBodySent('PATCH', fn (array $data): bool => ($data['date_of_birth'] ?? null) === '1815-12-10');
    }
}
