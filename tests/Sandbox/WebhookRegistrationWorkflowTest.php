<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Sandbox;

use Illuminate\Support\Facades\Http;
use Isapp\CashierRevolut\RevolutGateway;
use PHPUnit\Framework\Attributes\Group;

/**
 * Live sandbox: webhook endpoint registration. `registerWebhook()` is the opt-in RegistersWebhooks
 * operation the guarded provider does not carry, so it is driven on the concrete gateway. Revolut
 * returns the endpoint id and a `signing_secret` (returned once, on creation, and never again).
 * Inbound delivery is not unit-testable here; the endpoint is deleted in tearDown so a run leaves
 * no live webhook behind.
 *
 * @group sandbox
 */
#[Group('sandbox')]
class WebhookRegistrationWorkflowTest extends SandboxTestCase
{
    private ?string $webhookId = null;

    protected function tearDown(): void
    {
        if ($this->webhookId !== null) {
            try {
                Http::revolut()->delete('/webhooks/'.$this->webhookId);
            } catch (\Throwable) {
                // Already gone — nothing to clean up.
            }
        }

        parent::tearDown();
    }

    public function test_registering_an_endpoint_returns_its_id_and_signing_secret(): void
    {
        $gateway = app(RevolutGateway::class);

        $registration = $gateway->registerWebhook('https://app.test/webhook/cashier/revolut', []);

        $this->webhookId = $registration->id;

        $this->assertNotEmpty($registration->id);
        $this->assertNotEmpty($registration->secret, 'Revolut returns a signing_secret on creation');
    }
}
