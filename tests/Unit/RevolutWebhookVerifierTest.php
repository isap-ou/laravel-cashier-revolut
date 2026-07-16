<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Unit;

use Isapp\CashierRevolut\Webhooks\RevolutWebhookVerifier;
use Isapp\CashierSupport\Exceptions\InvalidConfigurationException;
use Isapp\CashierSupport\Exceptions\WebhookVerificationException;
use PHPUnit\Framework\TestCase;

class RevolutWebhookVerifierTest extends TestCase
{
    private const SECRET = 'wsk_test_secret';

    public function test_it_accepts_a_valid_signature_with_a_millisecond_timestamp(): void
    {
        $verifier = new RevolutWebhookVerifier(self::SECRET);
        $payload = '{"event":"ORDER_COMPLETED","order_id":"ord_1"}';
        $headers = $this->sign($payload);

        $verifier->verify($payload, $headers);

        $this->addToAssertionCount(1);
    }

    public function test_it_also_accepts_a_second_precision_timestamp(): void
    {
        $verifier = new RevolutWebhookVerifier(self::SECRET);
        $payload = '{"event":"ORDER_COMPLETED","order_id":"ord_1"}';
        $headers = $this->sign($payload, timestampHeader: (string) time());

        $verifier->verify($payload, $headers);

        $this->addToAssertionCount(1);
    }

    public function test_it_rejects_an_invalid_signature(): void
    {
        $verifier = new RevolutWebhookVerifier(self::SECRET);
        $payload = '{"event":"ORDER_COMPLETED"}';

        $this->expectException(WebhookVerificationException::class);

        $verifier->verify($payload, [
            'Revolut-Request-Timestamp' => (string) time(),
            'Revolut-Signature' => 'v1=deadbeef',
        ]);
    }

    public function test_it_rejects_a_stale_timestamp(): void
    {
        $verifier = new RevolutWebhookVerifier(self::SECRET, tolerance: 60);
        $payload = '{"event":"ORDER_COMPLETED"}';
        $headers = $this->sign($payload, time() - 3600);

        $this->expectException(WebhookVerificationException::class);

        $verifier->verify($payload, $headers);
    }

    public function test_a_missing_signing_secret_is_a_configuration_error_not_a_bad_signature(): void
    {
        $verifier = new RevolutWebhookVerifier(null);

        $this->expectException(InvalidConfigurationException::class);

        $verifier->verify('{}', $this->sign('{}'));
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
