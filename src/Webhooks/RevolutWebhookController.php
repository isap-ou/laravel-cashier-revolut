<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Isapp\CashierSupport\Events\WebhookHandled;
use Isapp\CashierSupport\Events\WebhookReceived;
use Isapp\CashierSupport\Exceptions\InvalidConfigurationException;
use Isapp\CashierSupport\Exceptions\UnexpectedWebhookEventException;
use Isapp\CashierSupport\Exceptions\WebhookVerificationException;
use Psr\Log\LoggerInterface;

/**
 * Receives Revolut webhooks: verifies the signature, normalizes the payload,
 * dispatches WebhookReceived, applies the webhook to local state via the
 * synchronizer (subscription mirror + invoice records + typed events), and
 * only then dispatches WebhookHandled.
 *
 * A synchronizer failure bubbles up as a 5xx so Revolut retries the delivery;
 * all synchronizer writes are idempotent.
 *
 * Revolut acknowledges a delivery with any 200-399. A failed delivery — a 4XX or a
 * timeout — is retried three more times, ten minutes apart. So a refusal this
 * driver can recover from (a missing signing secret, a missing API key) answers
 * with a 4XX and a critical log line, rather than rendering as an unhandled 500
 * whose retry behaviour the API does not document.
 *
 * @see https://developer.revolut.com/docs/guides/merchant/monitor-and-observe/webhooks/using-webhooks
 */
class RevolutWebhookController
{
    public function __construct(
        private readonly RevolutWebhookHandler $handler,
        private readonly RevolutWebhookSynchronizer $synchronizer,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request): Response
    {
        $content = (string) $request->getContent();
        $headers = $this->normalizeHeaders($request);

        try {
            $this->handler->verifyWebhook($content, $headers);
        } catch (WebhookVerificationException) {
            // A secret that is set but wrong — rotated in the Revolut dashboard and
            // not in .env, or a sandbox key in production — refuses every webhook
            // exactly like an unset one, and it is the likelier mistake of the two.
            // Refusing it silently is what leaves an operator with no signal while
            // the subscription mirror goes stale.
            $this->logger->warning('A Revolut webhook failed signature verification and was refused', [
                'signing_secret_configured' => true,
            ]);

            return new Response('Invalid signature.', 400);
        } catch (InvalidConfigurationException $exception) {
            return $this->refuseMisconfigured($exception);
        }

        $decoded = json_decode($content, true);
        /** @var array<string, mixed> $body */
        $body = is_array($decoded) ? $decoded : [];

        // The escape hatch, and it must sit here: above every decision about what
        // this event means, below verifyWebhook(). Revolut documents 22 event types
        // and this driver maps 8, so the other 14 — every DISPUTE_* among them —
        // reach a listener only through this line. It used to sit below
        // parseWebhook(), which throws for exactly those 14, so it was unreachable
        // for all of them and they vanished behind the 200 below.
        //
        // Both references do the same, for the same reason
        // (laravel/cashier's WebhookController.php:45, -paddle's :49).
        event(new WebhookReceived($body));

        try {
            $payload = $this->handler->parseWebhook($content, $headers);
        } catch (UnexpectedWebhookEventException $exception) {
            // Acknowledge events we do not subscribe to instead of erroring, so
            // Revolut does not retry them — retrying an event this driver has no
            // handler for would never succeed. Harmless now in a way it was not
            // before: the hatch above already fired, so this drops the local sync,
            // not the event.
            $this->logger->info('A Revolut webhook event was acknowledged without being handled', [
                'exception' => $exception->getMessage(),
            ]);

            return new Response('Webhook ignored.', 200);
        }

        try {
            $this->synchronizer->handle($payload);
        } catch (InvalidConfigurationException $exception) {
            // The same defect one path over: the synchronizer calls back into the
            // API, so a missing secret_key (as opposed to the webhook secret) threw
            // straight through the controller as an unhandled 500. Fixing the
            // verification path alone would have left the sibling wide open.
            return $this->refuseMisconfigured($exception);
        }

        // Only for an event we actually applied — the reference draws the same line
        // (laravel/cashier's WebhookController.php:47-52 dispatches it only when a
        // handler existed). The early return above is what keeps that true.
        event(new WebhookHandled($body));

        return new Response('Webhook handled.', 200);
    }

    /**
     * Refuse a webhook this driver is not configured to handle.
     *
     * A 4XX is a delivery failure Revolut retries — three times, ten minutes apart
     * — so it is the only answer that gives the event a chance of surviving the
     * fix. An uncaught exception renders whatever the app's error handler decides.
     *
     * It is logged at critical because the alternative symptom is silence: every
     * webhook refused, the retries exhausted, and the subscriptions quietly stale.
     */
    private function refuseMisconfigured(InvalidConfigurationException $exception): Response
    {
        $this->logger->critical('The Revolut driver is not configured; the webhook was refused', [
            'exception' => $exception->getMessage(),
        ]);

        return new Response('The Revolut driver is not configured.', 400);
    }

    /**
     * @return array<string, string>
     */
    private function normalizeHeaders(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $key => $values) {
            $headers[$key] = implode(', ', array_map('strval', $values));
        }

        return $headers;
    }
}
