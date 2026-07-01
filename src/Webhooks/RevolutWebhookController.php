<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Isapp\CashierRevolut\Exceptions\UnexpectedWebhookEventException;
use Isapp\CashierSupport\Events\WebhookHandled;
use Isapp\CashierSupport\Events\WebhookReceived;
use Isapp\CashierSupport\Exceptions\WebhookVerificationException;

/**
 * Receives Revolut webhooks: verifies the signature, normalizes the payload,
 * dispatches WebhookReceived, applies the webhook to local state via the
 * synchronizer (subscription mirror + invoice records + typed events), and
 * only then dispatches WebhookHandled.
 *
 * A synchronizer failure bubbles up as a 5xx so Revolut retries the delivery;
 * all synchronizer writes are idempotent.
 */
class RevolutWebhookController
{
    public function __construct(
        private readonly RevolutWebhookHandler $handler,
        private readonly RevolutWebhookSynchronizer $synchronizer,
    ) {}

    public function __invoke(Request $request): Response
    {
        $content = (string) $request->getContent();
        $headers = $this->normalizeHeaders($request);

        try {
            $this->handler->verifyWebhook($content, $headers);
        } catch (WebhookVerificationException) {
            return new Response('Invalid signature.', 400);
        }

        try {
            $payload = $this->handler->parseWebhook($content, $headers);
        } catch (UnexpectedWebhookEventException) {
            // Acknowledge events we do not subscribe to instead of erroring,
            // so Revolut does not retry them.
            return new Response('Webhook ignored.', 200);
        }

        event(new WebhookReceived($payload));

        $this->synchronizer->handle($payload);

        event(new WebhookHandled($payload));

        return new Response('Webhook handled.', 200);
    }

    /**
     * @return array<string, string>
     */
    private function normalizeHeaders(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $key => $values) {
            $headers[$key] = (string) ($values[0] ?? '');
        }

        return $headers;
    }
}
