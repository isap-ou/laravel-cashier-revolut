<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Sandbox;

use Illuminate\Support\Facades\Http;
use Isapp\CashierRevolut\RevolutGateway;
use Isapp\CashierRevolut\Tests\Fixtures\User;
use Isapp\CashierSupport\DTO\CustomerDetails;
use Isapp\CashierSupport\DTO\Payment;
use PHPUnit\Framework\Attributes\Group;

/**
 * Live sandbox: the subscription lifecycle an app drives through the gateway — create a plan-based
 * subscription (which Revolut opens `pending`, with a setup order paid in the Checkout Widget),
 * read it back, resolve the outstanding setup payment, then cancel.
 *
 * `subscriptionLatestPayment()` is the exact path fixed in v1.2.1 (setup order reached through the
 * billing cycle, not the retrieve response) — this proves it against the real API, not a fixture.
 *
 * Needs a real sandbox plan variation with a setup order; supply its id in
 * REVOLUT_SANDBOX_PLAN_VARIATION_ID or the test self-skips. The subscription is cancelled in
 * tearDown so a run leaves nothing billing.
 *
 * Swap (change-plan) is deliberately NOT exercised here: Revolut answers 500 to a change-plan on a
 * subscription that is still `pending` (verified against the sandbox), and a subscription only
 * becomes active once its setup order is paid in the Checkout Widget — a browser flow with no
 * server-to-server path. So swap is unreachable headless, the same wall as charge/refund, and stays
 * covered by the offline contract test (RevolutApiContractTest, the change-plan body).
 *
 * @group sandbox
 */
#[Group('sandbox')]
class SubscriptionWorkflowTest extends SandboxTestCase
{
    private ?User $user = null;

    private ?string $subscriptionId = null;

    protected function tearDown(): void
    {
        // Best-effort cleanup: cancel the sandbox subscription if it is still cancellable.
        if ($this->subscriptionId !== null) {
            try {
                Http::revolut()->post('/subscriptions/'.$this->subscriptionId.'/cancel');
            } catch (\Throwable) {
                // Already cancelled/finished, or gone — nothing to clean up.
            }
        }

        parent::tearDown();
    }

    private function planVariationId(): string
    {
        $id = getenv('REVOLUT_SANDBOX_PLAN_VARIATION_ID');

        if (! is_string($id) || $id === '') {
            $this->markTestSkipped('Set REVOLUT_SANDBOX_PLAN_VARIATION_ID (a sandbox plan variation with a setup order) to run.');
        }

        return $id;
    }

    public function test_subscription_create_retrieve_latest_payment_cancel_lifecycle(): void
    {
        $planVariationId = $this->planVariationId();

        $this->user = User::query()->create([
            'name' => 'Sub Probe',
            'email' => 'subprobe+'.uniqid().'@example.test',
        ]);
        $this->gateway()->createCustomer($this->user, new CustomerDetails(
            name: 'Sub Probe',
            email: (string) $this->user->email,
        ));

        // CREATE — a setup_order_redirect_url is what makes Revolut mint the setup order whose
        // token the widget consumes; without it there is no outstanding payment to resolve.
        // external_reference is Revolut's whole correlation surface — one string, writable on create
        // and returned on read.
        $reference = 'cashier-ref-'.uniqid();
        $subscription = $this->gateway()
            ->newSubscription($this->user, 'default', $planVariationId)
            ->create(null, [
                'setup_order_redirect_url' => 'https://app.test/return',
                'external_reference' => $reference,
            ]);

        $this->subscriptionId = $subscription->id;
        $this->assertNotEmpty($subscription->id);

        // EXTERNAL REFERENCE — round-trips through the driver accessor (a driver-specific method,
        // reached on the concrete gateway, not the neutral guarded provider) and the raw resource.
        $this->assertSame($reference, app(RevolutGateway::class)->subscriptionExternalReference($this->user));

        // RETRIEVE — a freshly created subscription is pending until its setup order is paid.
        $stored = Http::revolut()->get('/subscriptions/'.$subscription->id)->json();
        $this->assertIsArray($stored);
        $this->assertSame('pending', $stored['state'] ?? null);
        $this->assertSame($reference, $stored['external_reference'] ?? null);
        // The retrieve response carries the cycle id but NOT setup_order_* (the v1.2.1 finding).
        $this->assertArrayHasKey('current_cycle_id', $stored);
        $this->assertArrayNotHasKey('setup_order_id', $stored);

        // LATEST PAYMENT — resolved via the billing cycle → setup order → widget token.
        $payment = $this->gateway()->subscriptionLatestPayment($this->user);
        $this->assertInstanceOf(Payment::class, $payment);
        $this->assertNotEmpty($payment->clientSecret, 'the setup order widget token must be present');
        $this->assertTrue($payment->requiresPaymentMethod());

        // CANCEL — takes effect immediately for future billing; the sub flips to cancelled.
        $this->gateway()->cancelSubscription($this->user, 'default');

        $afterCancel = Http::revolut()->get('/subscriptions/'.$subscription->id)->json();
        $this->assertIsArray($afterCancel);
        $this->assertSame('cancelled', $afterCancel['state'] ?? null);
    }
}
