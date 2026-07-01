<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Concerns;

use Isapp\CashierRevolut\Webhooks\RevolutWebhookHandler;
use Isapp\CashierSupport\DTO\WebhookPayload;

/**
 * Delegates the gateway's webhook responsibilities to the injected
 * RevolutWebhookHandler.
 */
trait HandlesRevolutWebhooks
{
    protected RevolutWebhookHandler $webhooks;

    /**
     * {@inheritDoc}
     */
    public function verifyWebhook(string $payload, array $headers): void
    {
        $this->webhooks->verifyWebhook($payload, $headers);
    }

    /**
     * {@inheritDoc}
     */
    public function parseWebhook(string $payload, array $headers): WebhookPayload
    {
        return $this->webhooks->parseWebhook($payload, $headers);
    }
}
