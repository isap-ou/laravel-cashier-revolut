<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Isapp\CashierRevolut\Tests\Fixtures\RevolutApi;
use Isapp\CashierRevolut\Tests\TestCase;
use Isapp\CashierSupport\Events\WebhookHandled;
use Isapp\CashierSupport\Events\WebhookReceived;
use PHPUnit\Framework\Attributes\DataProvider;

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
            return $event->payload['event'] === 'ORDER_COMPLETED'
                && $event->payload['order_id'] === RevolutApi::ORDER_ID;
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

    /**
     * A body we cannot read is NOT an unmapped event, and must not be dispatched as one.
     *
     * This is the case the old controller comment named and no test ever pinned. It
     * matters more now that the hatch fires before the parse: dispatching
     * WebhookReceived([]) here would hand every listener a content-free event that is
     * indistinguishable from a real unmapped one — the same lie the reorder exists to
     * end, told in the other direction. The references never dispatch one either:
     * Stripe reads $payload['type'] BEFORE its dispatch.
     *
     * @param  string  $body  A verified body that is not a JSON object.
     */
    #[DataProvider('unreadableBodies')]
    public function test_a_body_we_cannot_read_is_acknowledged_but_reaches_no_listener(string $body): void
    {
        Event::fake([WebhookReceived::class, WebhookHandled::class]);
        Log::spy();

        $response = $this->postSigned($body);

        // Acknowledged, not refused: retrying it would never succeed.
        $response->assertOk();
        $response->assertSee('Webhook ignored.');

        Event::assertNotDispatched(WebhookReceived::class);
        Event::assertNotDispatched(WebhookHandled::class);

        // But never in silence.
        Log::shouldHaveReceived('info')->once();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unreadableBodies(): array
    {
        return [
            'not json at all' => ['not json'],
            'a json scalar' => ['"5"'],
            'json null' => ['null'],
            'an empty body' => [''],
            // A JSON list decodes to an array with int keys — is_array() alone would
            // wave it through and the event's array<string, mixed> would be a lie.
            'a json list' => ['[1,2,3]'],
        ];
    }

    /**
     * #24's acceptance test, and what the tripwire that stood here was holding the
     * place for.
     *
     * PAYOUT_INITIATED is a real documented Revolut event that this driver does not
     * map — one of 14 out of 22 (see .claude/rules/revolut-api.md). It used to reach
     * no listener at all: parseWebhook() threw for it and the controller caught that
     * ABOVE the dispatch, so the dispatch was unreachable for every unmapped event.
     */
    public function test_an_unmapped_event_reaches_a_listener_and_is_acknowledged_with_200(): void
    {
        /** @var array<string, mixed>|null $seen */
        $seen = null;

        // A real listener, not Event::fake(): the claim is that an app RECEIVES it.
        Event::listen(WebhookReceived::class, function (WebhookReceived $event) use (&$seen): void {
            $seen = $event->payload;
        });
        Event::fake([WebhookHandled::class]);

        $response = $this->postSigned('{"event":"PAYOUT_INITIATED","id":"po_1"}');

        // The escape hatch fired, and it carries the body as Revolut sent it.
        $this->assertSame(
            ['event' => 'PAYOUT_INITIATED', 'id' => 'po_1'],
            $seen,
            'An unmapped event reached no WebhookReceived listener.',
        );

        // The half of #24 that already held and had to survive the fix: Revolut retries
        // a 4XX three times, ten minutes apart, and retrying an event this driver has
        // no handler for would never succeed. So it is acknowledged, not refused.
        $response->assertOk();
        $response->assertSee('Webhook ignored.');

        // Nothing was applied to local state — there is no handler for it. Saying
        // "handled" would trade the old silence for a lie.
        Event::assertNotDispatched(WebhookHandled::class);
    }

    public function test_signature_verification_still_runs_before_the_hatch(): void
    {
        // The other half of #24's acceptance, and it matters more now that the hatch is
        // wide: an unverified body is not an event, it is noise, and dispatching it to
        // listeners would let anyone who can reach the URL fabricate one.
        Event::fake([WebhookReceived::class, WebhookHandled::class]);

        $this->postRaw('{"event":"PAYOUT_INITIATED","id":"po_1"}', [
            'Revolut-Request-Timestamp' => (string) (time() * 1000),
            'Revolut-Signature' => 'v1=deadbeef',
        ])->assertStatus(400);

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_a_mapped_event_carries_the_raw_body_to_both_events(): void
    {
        /** @var array<string, mixed> $seen */
        $seen = [];

        Event::listen(WebhookReceived::class, function (WebhookReceived $event) use (&$seen): void {
            $seen['received'] = $event->payload;
        });
        Event::listen(WebhookHandled::class, function (WebhookHandled $event) use (&$seen): void {
            $seen['handled'] = $event->payload;
        });
        RevolutApi::fake();

        $body = ['event' => 'ORDER_COMPLETED', 'order_id' => RevolutApi::ORDER_ID];
        $this->postSigned((string) json_encode($body))->assertOk();

        // The pair must agree, or an app has to read one webhook two ways.
        $this->assertSame($body, $seen['received'] ?? null);
        $this->assertSame($body, $seen['handled'] ?? null);
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
