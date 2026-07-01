<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Webhooks;

use Isapp\CashierRevolut\Enums\RevolutWebhookEvent;
use Isapp\CashierRevolut\Exceptions\UnexpectedWebhookEventException;
use Isapp\CashierSupport\Contracts\WebhookHandler;
use Isapp\CashierSupport\DTO\WebhookPayload;
use Isapp\CashierSupport\Exceptions\InvalidConfigurationException;
use Isapp\CashierSupport\Exceptions\WebhookVerificationException;

/**
 * Verifies Revolut webhook signatures (HMAC-SHA256 over "v1.{timestamp}.{body}")
 * and translates Revolut events into the provider-agnostic WebhookPayload.
 */
class RevolutWebhookHandler implements WebhookHandler
{
    public function __construct(
        private readonly ?string $signingSecret,
        private readonly int $tolerance = 300,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function verifyWebhook(string $payload, array $headers): void
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
     * {@inheritDoc}
     *
     * @throws UnexpectedWebhookEventException When the event is not one this driver subscribes to.
     */
    public function parseWebhook(string $payload, array $headers): WebhookPayload
    {
        $decoded = json_decode($payload, true);
        /** @var array<string, mixed> $data */
        $data = is_array($decoded) ? $decoded : [];

        $raw = is_string($data['event'] ?? null) ? $data['event'] : '';
        $event = RevolutWebhookEvent::tryFrom($raw)
            ?? throw UnexpectedWebhookEventException::forEvent($raw);

        return new WebhookPayload(
            event: $event->toWebhookEvent(),
            id: $this->resourceId($data),
            data: $data,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resourceId(array $data): string
    {
        foreach (['order_id', 'subscription_id', 'id'] as $key) {
            if (is_string($data[$key] ?? null)) {
                return $data[$key];
            }
        }

        return '';
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
