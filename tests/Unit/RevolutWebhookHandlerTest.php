<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Unit;

use Isapp\CashierRevolut\Webhooks\RevolutWebhookHandler;
use Isapp\CashierSupport\Enums\WebhookEvent;
use Isapp\CashierSupport\Exceptions\InvalidConfigurationException;
use Isapp\CashierSupport\Exceptions\UnexpectedWebhookEventException;
use Isapp\CashierSupport\Exceptions\WebhookVerificationException;
use PHPUnit\Framework\TestCase;

class RevolutWebhookHandlerTest extends TestCase
{
    private const SECRET = 'wsk_test_secret';

    public function test_it_accepts_a_valid_signature_with_a_millisecond_timestamp(): void
    {
        $handler = new RevolutWebhookHandler(self::SECRET);
        $payload = '{"event":"ORDER_COMPLETED","order_id":"ord_1"}';
        $headers = $this->sign($payload);

        $handler->verifyWebhook($payload, $headers);

        $this->addToAssertionCount(1);
    }

    public function test_it_also_accepts_a_second_precision_timestamp(): void
    {
        $handler = new RevolutWebhookHandler(self::SECRET);
        $payload = '{"event":"ORDER_COMPLETED","order_id":"ord_1"}';
        $headers = $this->sign($payload, timestampHeader: (string) time());

        $handler->verifyWebhook($payload, $headers);

        $this->addToAssertionCount(1);
    }

    public function test_it_rejects_an_invalid_signature(): void
    {
        $handler = new RevolutWebhookHandler(self::SECRET);
        $payload = '{"event":"ORDER_COMPLETED"}';

        $this->expectException(WebhookVerificationException::class);

        $handler->verifyWebhook($payload, [
            'Revolut-Request-Timestamp' => (string) time(),
            'Revolut-Signature' => 'v1=deadbeef',
        ]);
    }

    public function test_it_rejects_a_stale_timestamp(): void
    {
        $handler = new RevolutWebhookHandler(self::SECRET, tolerance: 60);
        $payload = '{"event":"ORDER_COMPLETED"}';
        $headers = $this->sign($payload, time() - 3600);

        $this->expectException(WebhookVerificationException::class);

        $handler->verifyWebhook($payload, $headers);
    }

    public function test_a_missing_signing_secret_is_a_configuration_error_not_a_bad_signature(): void
    {
        $handler = new RevolutWebhookHandler(null);

        $this->expectException(InvalidConfigurationException::class);

        $handler->verifyWebhook('{}', $this->sign('{}'));
    }

    public function test_it_maps_events_to_the_support_enum(): void
    {
        $handler = new RevolutWebhookHandler(self::SECRET);

        $this->assertSame(
            WebhookEvent::PaymentSucceeded,
            $handler->parseWebhook('{"event":"ORDER_COMPLETED","order_id":"ord_1"}', [])->event,
        );
        $this->assertSame(
            WebhookEvent::PaymentFailed,
            $handler->parseWebhook('{"event":"ORDER_PAYMENT_FAILED"}', [])->event,
        );
        $this->assertSame(
            WebhookEvent::SubscriptionCreated,
            $handler->parseWebhook('{"event":"SUBSCRIPTION_INITIATED","subscription_id":"sub_1"}', [])->event,
        );
        $this->assertSame(
            'sub_1',
            $handler->parseWebhook('{"event":"SUBSCRIPTION_CANCELLED","subscription_id":"sub_1"}', [])->id,
        );
    }

    public function test_it_rejects_an_unknown_event_instead_of_misclassifying_it(): void
    {
        $handler = new RevolutWebhookHandler(self::SECRET);

        $this->expectException(UnexpectedWebhookEventException::class);

        $handler->parseWebhook('{"event":"PAYOUT_INITIATED","id":"po_1"}', []);
    }

    /**
     * Sign the payload the way Revolut does. The timestamp header is in
     * milliseconds (the documented format) unless overridden explicitly.
     *
     * @return array<string, string>
     */
    private function sign(string $payload, ?int $timestamp = null, ?string $timestampHeader = null): array
    {
        $timestampHeader ??= (string) (($timestamp ?? time()) * 1000);
        $signature = 'v1='.hash_hmac('sha256', "v1.{$timestampHeader}.{$payload}", self::SECRET);

        return [
            'Revolut-Request-Timestamp' => $timestampHeader,
            'Revolut-Signature' => $signature,
        ];
    }
}
