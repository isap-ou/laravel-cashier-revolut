<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
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

    public function test_an_unconfigured_signing_secret_is_a_400_and_a_log_line_not_a_500(): void
    {
        // Revolut acknowledges any 2xx-3xx and retries a 4XX three times, ten
        // minutes apart — that window is this event's only chance of surviving the
        // fix. An unhandled 500 renders whatever the app's error handler decides,
        // and Revolut documents no retry for it.
        config()->set('cashier-revolut.webhook.signing_secret', null);

        Event::fake([WebhookReceived::class, WebhookHandled::class]);
        Log::spy();

        $response = $this->postRaw('{"event":"ORDER_COMPLETED"}', [
            'Revolut-Request-Timestamp' => (string) (time() * 1000),
            'Revolut-Signature' => 'v1=whatever',
        ]);

        $response->assertStatus(400);
        Event::assertNotDispatched(WebhookReceived::class);

        // And it is loud: the only other symptom of an unset secret is silence —
        // every webhook refused, the retries exhausted, the subscriptions quietly
        // stale.
        Log::shouldHaveReceived('critical')
            ->once()
            ->withArgs(fn (string $message): bool => str_contains($message, 'not configured'));
    }

    public function test_a_missing_api_key_refuses_the_webhook_instead_of_500ing_in_the_synchronizer(): void
    {
        // The sibling of the same defect: the synchronizer calls back into the API,
        // so a missing secret_key escaped the controller entirely. Fixing only the
        // verification path would have left this one wide open.
        config()->set('cashier-revolut.secret_key', '');

        RevolutApi::fake();
        Log::spy();

        $response = $this->postSigned('{"event":"ORDER_COMPLETED","order_id":"'.RevolutApi::ORDER_ID.'"}');

        $response->assertStatus(400);
        Log::shouldHaveReceived('critical')->once();
    }

    public function test_a_wrong_signature_is_logged_not_merely_refused(): void
    {
        // A secret set but wrong (rotated in the dashboard, sandbox key in prod) is
        // the likelier misconfiguration, and it refuses every webhook just the same.
        // Silently is exactly how the subscription mirror goes stale unnoticed.
        Log::spy();

        $this->postRaw('{"event":"ORDER_COMPLETED"}', [
            'Revolut-Request-Timestamp' => (string) (time() * 1000),
            'Revolut-Signature' => 'v1=deadbeef',
        ])->assertStatus(400);

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_an_event_we_cannot_handle_is_acknowledged_but_recorded(): void
    {
        Log::spy();

        $this->postSigned('{"event":"PAYOUT_INITIATED","id":"po_1"}')->assertOk();

        Log::shouldHaveReceived('info')->once();
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
