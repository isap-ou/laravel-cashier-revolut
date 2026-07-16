<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Concerns;

use Isapp\CashierRevolut\Webhooks\RevolutIncomingWebhook;
use Isapp\CashierRevolut\Webhooks\RevolutWebhookSynchronizer;
use Isapp\CashierRevolut\Webhooks\RevolutWebhookVerifier;
use Isapp\CashierSupport\Contracts\IncomingWebhook;

/**
 * The gateway's whole webhook surface: hand back a delivery.
 *
 * It used to be verifyWebhook() + parseWebhook(), both delegated straight through. The
 * delivery replaces them because verifying and applying need the same raw bytes, and a
 * per-delivery object takes them once instead of asking the caller to pass the same pair
 * twice and trusting it did.
 */
trait HandlesRevolutWebhooks
{
    protected RevolutWebhookVerifier $webhookVerifier;

    protected RevolutWebhookSynchronizer $webhookSynchronizer;

    /**
     * {@inheritDoc}
     */
    public function webhook(string $content, array $headers): IncomingWebhook
    {
        return new RevolutIncomingWebhook(
            $content,
            $headers,
            $this->webhookVerifier,
            $this->webhookSynchronizer,
        );
    }
}
