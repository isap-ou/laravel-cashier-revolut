<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Sandbox;

use Isapp\CashierRevolut\Tests\Fixtures\User;
use Isapp\CashierSupport\DTO\CustomerDetails;
use PHPUnit\Framework\Attributes\Group;

/**
 * Live sandbox: saved payment methods. A fresh customer has none — Revolut can only save a method
 * through the Checkout Widget's save flow (there is no server-side add), so a headless run can
 * assert the empty list and null default, which is exactly the state an app sees before any widget
 * payment. Adding/deleting a real method is not server-to-server reachable.
 *
 * @group sandbox
 */
#[Group('sandbox')]
class PaymentMethodsWorkflowTest extends SandboxTestCase
{
    public function test_a_fresh_customer_has_no_saved_payment_methods(): void
    {
        $user = User::query()->create([
            'name' => 'PM Probe',
            'email' => 'pmprobe+'.uniqid().'@example.test',
        ]);
        $this->gateway()->createCustomer($user, new CustomerDetails(
            name: 'PM Probe',
            email: (string) $user->email,
        ));

        $this->assertSame([], $this->gateway()->paymentMethods($user));
        $this->assertNull($this->gateway()->defaultPaymentMethod($user));
    }
}
