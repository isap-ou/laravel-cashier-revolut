<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Sandbox;

use Isapp\CashierRevolut\Tests\Fixtures\User;
use Isapp\CashierSupport\DTO\CheckoutRequest;
use Isapp\CashierSupport\DTO\CustomerDetails;
use Money\Currency;
use PHPUnit\Framework\Attributes\Group;

/**
 * Live sandbox: hosted checkout. Revolut checks out an AMOUNT (Capability::CheckoutAmount), not a
 * price catalogue, so the gateway POSTs an order and hands back the widget `token` and hosted
 * `checkout_url`. This is as far as a server-to-server test can go — actually PAYING the order is a
 * browser flow in the Checkout Widget and cannot be completed headless.
 *
 * @group sandbox
 */
#[Group('sandbox')]
class CheckoutWorkflowTest extends SandboxTestCase
{
    public function test_amount_checkout_creates_an_order_and_returns_a_widget_token(): void
    {
        $user = User::query()->create([
            'name' => 'Checkout Probe',
            'email' => 'checkoutprobe+'.uniqid().'@example.test',
        ]);
        $this->gateway()->createCustomer($user, new CustomerDetails(
            name: 'Checkout Probe',
            email: (string) $user->email,
        ));

        $session = $this->gateway()->checkout($user, CheckoutRequest::forAmount(
            amount: 1500,
            currency: new Currency('EUR'),
            description: 'Sandbox checkout probe',
            successUrl: 'https://app.test/return',
        ));

        $this->assertNotEmpty($session->id());
        $this->assertNotEmpty($session->clientSecret(), 'the order token the Checkout Widget consumes must be present');
        $this->assertNotEmpty($session->url(), 'a hosted checkout_url is returned for the order');
    }
}
