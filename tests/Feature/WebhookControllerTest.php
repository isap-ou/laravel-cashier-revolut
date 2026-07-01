<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Isapp\CashierRevolut\Tests\Fixtures\RevolutApi;
use Isapp\CashierRevolut\Tests\TestCase;
use Isapp\CashierSupport\Enums\WebhookEvent;
use Isapp\CashierSupport\Events\WebhookHandled;
use Isapp\CashierSupport\Events\WebhookReceived;

class WebhookControllerTest extends TestCase
{
    private const SECRET = 'wsk_test_secret';

    public function test_a_valid_webhook_dispatches_the_support_events(): void
    {
        Event::fake([WebhookReceived::class, WebhookHandled::class]);
        RevolutApi::fake();

        $response = $this->postSigned('{"event":"ORDER_COMPLETED","order_id":"'.RevolutApi::ORDER_ID.'"}');

        $response->assertOk();

        Event::assertDispatched(WebhookReceived::class, function (WebhookReceived $event): bool {
            return $event->payload->event === WebhookEvent::PaymentSucceeded
                && $event->payload->id === RevolutApi::ORDER_ID;
        });
        Event::assertDispatched(WebhookHandled::class);
    }

    public function test_an_invalid_signature_is_rejected_with_400(): void
    {
        Event::fake([WebhookReceived::class, WebhookHandled::class]);

        $response = $this->postRaw('{"event":"ORDER_COMPLETED"}', [
            'Revolut-Request-Timestamp' => (string) (time() * 1000),
            'Revolut-Signature' => 'v1=deadbeef',
        ]);

        $response->assertStatus(400);
        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_an_unexpected_event_is_acknowledged_but_not_dispatched(): void
    {
        Event::fake([WebhookReceived::class, WebhookHandled::class]);

        $response = $this->postSigned('{"event":"PAYOUT_INITIATED","id":"po_1"}');

        $response->assertOk();
        $response->assertSee('Webhook ignored.');

        Event::assertNotDispatched(WebhookReceived::class);
        Event::assertNotDispatched(WebhookHandled::class);
    }

    private function postSigned(string $payload): TestResponse
    {
        $timestamp = (string) (time() * 1000);

        return $this->postRaw($payload, [
            'Revolut-Request-Timestamp' => $timestamp,
            'Revolut-Signature' => 'v1='.hash_hmac('sha256', "v1.{$timestamp}.{$payload}", self::SECRET),
        ]);
    }

    /**
     * POST a raw body so the signature covers the exact bytes sent.
     *
     * @param  array<string, string>  $headers
     */
    private function postRaw(string $payload, array $headers): TestResponse
    {
        return $this->call(
            'POST',
            '/webhook/revolut',
            [],
            [],
            [],
            $this->transformHeadersToServerVars($headers + ['CONTENT_TYPE' => 'application/json']),
            $payload,
        );
    }
}
