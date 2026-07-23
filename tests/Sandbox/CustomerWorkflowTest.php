<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Sandbox;

use Illuminate\Support\Facades\Http;
use Isapp\CashierRevolut\Tests\Fixtures\User;
use Isapp\CashierSupport\DTO\CustomerDetails;
use PHPUnit\Framework\Attributes\Group;

/**
 * Live sandbox: the full customer lifecycle a real app drives — create, retrieve, update — through
 * the actual RevolutGateway against https://sandbox-merchant.revolut.com.
 *
 * Pins two findings from the sandbox run:
 *   - the driver now sends `date_of_birth` (a documented optional field) and Revolut stores it;
 *   - the Merchant API customer object in api-version 2026-04-20 has NO individual/business concept
 *     — `business_name` is "not supported in this version" and is silently dropped, and there is no
 *     `type`/`customer_type` field at all. So "a customer is always individual" is the API, not a
 *     driver bug, and there is nothing here to fix or work around.
 *
 * The neutral Customer DTO carries only id/name/email, so date_of_birth and the absence of a
 * business/type field are asserted against the raw retrieve body (Http::revolut()).
 *
 * @group sandbox
 */
#[Group('sandbox')]
class CustomerWorkflowTest extends SandboxTestCase
{
    public function test_customer_create_retrieve_update_lifecycle(): void
    {
        $user = User::query()->create([
            'name' => 'CC Probe',
            'email' => 'ccprobe+'.uniqid().'@example.test',
        ]);

        // CREATE — supply date_of_birth (the fix) and business_name (documented as unsupported).
        $customer = $this->gateway()->createCustomer($user, new CustomerDetails(
            name: 'CC Probe Individual',
            email: (string) $user->email,
            options: [
                'phone' => '+3222000000',
                'date_of_birth' => '1990-01-01',
                'business_name' => 'Acme Corp BV',
            ],
        ));

        $this->assertNotEmpty($customer->id);
        $this->assertSame('CC Probe Individual', $customer->name);
        $this->assertSame($customer->id, $user->customerId());

        // RETRIEVE the raw stored object — the DTO cannot carry dob / business / type.
        $stored = Http::revolut()->get('/customers/'.$customer->id)->json();

        $this->assertIsArray($stored);
        $this->assertSame('1990-01-01', $stored['date_of_birth'] ?? null, 'date_of_birth must be stored (the fix)');
        $this->assertArrayNotHasKey('business_name', $stored, 'business_name is unsupported in this API version — dropped');
        $this->assertArrayNotHasKey('type', $stored, 'the Merchant customer has no individual/business type');
        $this->assertArrayNotHasKey('customer_type', $stored);

        // UPDATE — rename and try business_name again; date_of_birth is left untouched (null-omit).
        $updated = $this->gateway()->updateCustomer($user, new CustomerDetails(
            name: 'CC Probe Renamed',
            options: ['business_name' => 'Acme Corp BV'],
        ));

        $this->assertSame('CC Probe Renamed', $updated->name);

        $afterUpdate = Http::revolut()->get('/customers/'.$customer->id)->json();

        $this->assertIsArray($afterUpdate);
        $this->assertSame('CC Probe Renamed', $afterUpdate['full_name'] ?? null);
        $this->assertArrayNotHasKey('business_name', $afterUpdate);
        $this->assertSame('1990-01-01', $afterUpdate['date_of_birth'] ?? null, 'an unrelated PATCH must not clear date_of_birth');
    }
}
