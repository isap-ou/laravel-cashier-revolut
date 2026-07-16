<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Webhooks;

use Isapp\CashierSupport\Exceptions\InvalidConfigurationException;
use Isapp\CashierSupport\Exceptions\WebhookVerificationException;

/**
 * Verifies Revolut webhook signatures: HMAC-SHA256 over "v1.{timestamp}.{body}".
 *
 * Verification only. It used to be RevolutWebhookHandler and also translated an event
 * into a provider-agnostic DTO — support#47 took that job away, because that translation
 * was the part that made an event we do not map inexpressible. What is left does one
 * thing, so it is named for it.
 *
 * It refuses a webhook it cannot verify, and a missing secret is a hard failure rather
 * than a shrug. Both references disagree: they attach their signature middleware only
 * `if (config(...secret))` and otherwise accept unsigned webhooks with no throw and no
 * log line (laravel/cashier's WebhookController.php:29, -paddle's :32).
 */
class RevolutWebhookVerifier
{
    public function __construct(
        private readonly ?string $signingSecret,
        private readonly int $tolerance = 300,
    ) {}

    /**
     * @param  array<string, string>  $headers
     *
     * @throws WebhookVerificationException When the signature cannot be verified.
     * @throws InvalidConfigurationException When no signing secret is configured.
     */
    public function verify(string $payload, array $headers): void
    {
        if ($this->signingSecret === null || $this->signingSecret === '') {
            throw InvalidConfigurationException::missingKey('cashier-revolut.webhook.signing_secret');
        }

        $timestamp = $this->header($headers, 'revolut-request-timestamp');
        $signature = $this->header($headers, 'revolut-signature');

        if ($timestamp === null || $signature === null) {
            throw WebhookVerificationException::invalidSignature();
        }

        if (abs(time() - $this->timestampSeconds($timestamp)) > $this->tolerance) {
            throw WebhookVerificationException::invalidSignature();
        }

        // The raw header value is part of the signed payload — never normalize it here.
        $expected = 'v1='.hash_hmac('sha256', "v1.{$timestamp}.{$payload}", $this->signingSecret);

        foreach (preg_split('/[,\s]+/', $signature) ?: [] as $candidate) {
            if ($candidate !== '' && hash_equals($expected, $candidate)) {
                return;
            }
        }

        throw WebhookVerificationException::invalidSignature();
    }

    /**
     * Normalize the Revolut-Request-Timestamp header to seconds.
     *
     * Revolut documents the timestamp in milliseconds; accept seconds too so a
     * unit change upstream cannot silently reject every webhook.
     */
    private function timestampSeconds(string $timestamp): int
    {
        $value = (int) $timestamp;

        return $value > 9_999_999_999 ? intdiv($value, 1000) : $value;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower($key) === $name) {
                return $value;
            }
        }

        return null;
    }
}
