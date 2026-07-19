<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Isapp\CashierRevolut\Enums\RevolutWebhookEvent;
use Isapp\CashierRevolut\RevolutGateway;
use Isapp\CashierRevolut\Tests\Fixtures\RevolutApi;
use Isapp\CashierRevolut\Tests\TestCase;
use Isapp\CashierSupport\Exceptions\CashierException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What POST /webhooks is asked to subscribe the endpoint to.
 *
 * This method had NO test until now, and that is why it took this long to notice it was
 * subscribing to 8 of Revolut's 22 documented events: the enum it read held only the events
 * the synchronizer applies, so `php artisan cashier:webhook revolut` quietly left the other
 * 14 undelivered — and an undelivered event reaches no listener, which made the
 * WebhookReceived escape hatch (#24, support#42/#47) unreachable for exactly the events it
 * was built for.
 *
 * The subscribed set and the applied set are two different questions. This file owns the
 * first; the match in RevolutWebhookSynchronizer owns the second.
 */
class WebhookRegistrationTest extends TestCase
{
    private const URL = 'https://example.test/webhook/cashier/revolut';

    private function gateway(): RevolutGateway
    {
        return $this->app->make(RevolutGateway::class);
    }

    /**
     * The event names sent in the single POST /webhooks request.
     *
     * @return array<int, string>
     */
    private function subscribedEvents(): array
    {
        $events = [];

        Http::recorded(function ($request) use (&$events): bool {
            if (str_contains($request->url(), '/webhooks')) {
                $events = $request->data()['events'] ?? [];
            }

            return true;
        });

        return $events;
    }

    public function test_an_empty_event_list_subscribes_to_the_whole_documented_catalogue(): void
    {
        RevolutApi::fake();

        $registration = $this->gateway()->registerWebhook(self::URL, []);

        // All 22, not the 8 we apply. Anything missing here is an event Revolut never sends
        // us, so it cannot reach a WebhookReceived listener either.
        $this->assertSame(RevolutWebhookEvent::values()->all(), $this->subscribedEvents());

        // One request, and the URL actually travelled in it. Without these, dropping the `url`
        // field entirely would leave every test in this file green — the endpoint would be
        // registered against nothing, which is the silent failure this class is here to catch.
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && ($request->data()['url'] ?? null) === self::URL);

        $this->assertNotSame('', $registration->id);
        $this->assertNotSame('', $registration->secret);
    }

    public function test_the_configured_list_narrows_what_revolut_will_send(): void
    {
        RevolutApi::fake();
        config(['cashier-revolut.webhook.events' => ['ORDER_COMPLETED', 'SUBSCRIPTION_CANCELLED']]);

        $this->gateway()->registerWebhook(self::URL, []);

        $this->assertSame(['ORDER_COMPLETED', 'SUBSCRIPTION_CANCELLED'], $this->subscribedEvents());
    }

    public function test_an_explicit_list_beats_the_configured_one(): void
    {
        RevolutApi::fake();
        config(['cashier-revolut.webhook.events' => ['ORDER_COMPLETED']]);

        $this->gateway()->registerWebhook(self::URL, ['ORDER_FAILED']);

        $this->assertSame(['ORDER_FAILED'], $this->subscribedEvents());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableConfigProvider(): array
    {
        return [
            // The likeliest way to get it wrong is not a bare string — it is emptying the list,
            // or an env-driven explode() on an unset variable.
            'an empty list' => [[]],
            'a bare string' => ['ORDER_COMPLETED'],
            'null' => [null],
        ];
    }

    #[DataProvider('unusableConfigProvider')]
    public function test_an_unusable_events_key_falls_back_to_the_catalogue(mixed $configured): void
    {
        RevolutApi::fake();
        // An endpoint subscribed to nothing succeeds and is discovered much later, by the
        // webhooks that never arrive. A bad config must not be able to produce one — and it
        // must not fatal on the way to saying so either.
        config(['cashier-revolut.webhook.events' => $configured]);

        $this->gateway()->registerWebhook(self::URL, []);

        $this->assertSame(RevolutWebhookEvent::values()->all(), $this->subscribedEvents());
    }

    public function test_a_list_containing_garbage_is_refused_rather_than_quietly_widened(): void
    {
        RevolutApi::fake();
        // Nested one level too deep — a plausible copy-paste of the README block, and it used
        // to fatal inside strval(). It does NOT fall back to the catalogue: the operator wrote
        // a list and meant it, so a malformed one is their bug to see, not ours to paper over
        // by silently subscribing to everything. The fallback is for an ABSENT key.
        config(['cashier-revolut.webhook.events' => [['ORDER_COMPLETED', 'ORDER_FAILED']]]);

        $this->expectException(CashierException::class);
        $this->expectExceptionMessage('Unknown webhook event [array]');

        try {
            $this->gateway()->registerWebhook(self::URL, []);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_typo_in_the_configured_list_is_refused_like_any_other(): void
    {
        RevolutApi::fake();
        // The config path runs through the same validation loop as an explicit argument, so a
        // typo in config/cashier-revolut.php fails `cashier:webhook revolut` loudly instead of
        // registering a partially-subscribed endpoint.
        config(['cashier-revolut.webhook.events' => ['ORDER_COMPLETED', 'ORDER_TYPO']]);

        $this->expectException(CashierException::class);
        $this->expectExceptionMessage('Unknown webhook event [ORDER_TYPO]');

        try {
            $this->gateway()->registerWebhook(self::URL, []);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_non_string_event_name_is_refused_rather_than_fatal(): void
    {
        RevolutApi::fake();

        // The signature promises array<int, string> and PHPStan believes it, but this is a
        // public entry point: under strict_types a non-string reaching tryFrom() is a TypeError,
        // and Contracts\RegistersWebhooks promises a catchable CashierException.
        $this->expectException(CashierException::class);
        $this->expectExceptionMessage('Unknown webhook event [42]');

        /** @phpstan-ignore-next-line argument.type — deliberately violating the signature */
        $this->gateway()->registerWebhook(self::URL, [42]);
    }

    public function test_an_event_we_do_not_apply_can_still_be_subscribed_to(): void
    {
        RevolutApi::fake();

        // The point of the hatch: we never sync a dispute, and an app must still be able to
        // hear about one. Validating against the 8 we apply used to refuse this outright.
        $this->gateway()->registerWebhook(self::URL, ['DISPUTE_ACTION_REQUIRED']);

        $this->assertSame(['DISPUTE_ACTION_REQUIRED'], $this->subscribedEvents());
    }

    public function test_an_event_outside_the_catalogue_is_refused_before_the_call(): void
    {
        RevolutApi::fake();

        // Caught by hand rather than via expectExceptionMessage(): the contract has TWO
        // obligations here — name the offender, and name the valid ones — and a second
        // expectExceptionMessage() call would silently replace the first rather than add to it.
        try {
            $this->gateway()->registerWebhook(self::URL, ['ORDER_COMPLETED', 'ORDER_NOPE']);
            $this->fail('An event outside the catalogue must be refused.');
        } catch (CashierException $exception) {
            $this->assertStringContainsString('Unknown webhook event [ORDER_NOPE]', $exception->getMessage());
            // Contracts\RegistersWebhooks:37-40 requires the message to name the ones that
            // exist. Asserting only up to the colon would pass with an empty list.
            $this->assertStringContainsString('DISPUTE_ACTION_REQUIRED', $exception->getMessage());
        }

        // Refused BEFORE the call: nothing was registered, so there is no half-created
        // endpoint for an operator to hunt down in the dashboard.
        Http::assertNothingSent();
    }
}
