<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Webhooks;

use Isapp\CashierSupport\Contracts\IncomingWebhook;
use Isapp\CashierSupport\Exceptions\UnexpectedWebhookEventException;

/**
 * One Revolut webhook delivery.
 *
 * All this driver still owns of the webhook flow: what a delivery is, and what applying
 * it does. The route, the dispatches, the status codes and — crucially — the ORDER of
 * them belong to cashier-support now. That order was #24: WebhookReceived sat below the
 * step that decides what an event means, and that step threw for the 14 of Revolut's 22
 * documented types this driver does not map, so every one of them reached no listener.
 * The fix is no longer four lines in a controller this package could get wrong again; it
 * is pipeline() returning false.
 *
 * @internal One delivery, handed to support's WebhookController through Contracts\IncomingWebhook. Not public surface: outside the backward-compatibility promise in README.
 */
class RevolutIncomingWebhook implements IncomingWebhook
{
    /**
     * The verified, decoded body. Null until body() has run.
     *
     * @var array<string, mixed>|null
     */
    private ?array $body = null;

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        private readonly string $content,
        private readonly array $headers,
        private readonly RevolutWebhookVerifier $verifier,
        private readonly RevolutWebhookSynchronizer $synchronizer,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function parse(): array
    {
        return $this->body();
    }

    /**
     * {@inheritDoc}
     */
    public function pipeline(): bool
    {
        // The synchronizer answers "did I apply this?", because it is the only thing that
        // knows. An event this driver does not map comes back false and never throws —
        // the one rule that replaced the ordering.
        return $this->synchronizer->handle($this->body());
    }

    /**
     * Verify the delivery once, then read it.
     *
     * Memoized rather than done in parse() alone, so verification happens exactly once
     * however the caller sequences the two methods — and, more to the point, so it cannot
     * be skipped by calling pipeline() on its own. Support fixes when verification runs;
     * this is the half that makes sure it ran at all.
     *
     * @return array<string, mixed>
     *
     * @throws UnexpectedWebhookEventException
     */
    private function body(): array
    {
        if ($this->body !== null) {
            return $this->body;
        }

        $this->verifier->verify($this->content, $this->headers);

        $decoded = json_decode($this->content, true);

        // Anything that is not a populated JSON object is not an event, and must not reach
        // a listener: an empty array standing in for content is indistinguishable from a
        // real unmapped event, which is the same lie in the other direction.
        //
        // Three cases, and they are not the same one wearing different clothes:
        //  - not an array at all — unparseable bytes, a scalar, null;
        //  - a JSON list, which json_decode makes an array with INT keys. is_array() waves
        //    it through, and the array<string, mixed> promised below would be a lie PHPStan
        //    cannot catch. The annotation is earned here rather than asserted;
        //  - `{}`, a valid but empty object. json_decode makes it [], and array_is_list([])
        //    is true, so it takes this branch too — correctly, since a body with no event
        //    name is nothing we can act on or hand to anyone.
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw UnexpectedWebhookEventException::forEvent(get_debug_type($decoded));
        }

        /** @var array<string, mixed> $decoded */
        return $this->body = $decoded;
    }
}
