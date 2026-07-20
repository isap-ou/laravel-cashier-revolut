---
description: Handle Revolut webhook events
---

# Webhook Handling

**This package ships no controller and no route.** support#47 took the entry point
(`webhook/cashier/{provider}`) and — the point of the move — the ORDER of the steps around it.
Getting that order wrong was #24, and it hid 14 event types behind a 200 for the life of the
package.

The flow, and who owns each part:

1. **support's `WebhookController`** receives the POST and calls the driver's one webhook method,
   `RevolutGateway::webhook($content, $headers)` (`Concerns/HandlesRevolutWebhooks.php`), which
   hands back a `RevolutIncomingWebhook`.
2. **`RevolutIncomingWebhook::parse()`** — verifies the HMAC-SHA256 signature over
   `v1.{timestamp}.{body}` (`Webhooks/RevolutWebhookVerifier.php`), then decodes. A missing signing
   secret is a hard failure, not a shrug. A body that is not a JSON object throws.
3. **support dispatches `Events\WebhookReceived`** with the raw decoded body — above any decision
   about what the event means. This is the escape hatch, and it is support's to sequence.
4. **`RevolutIncomingWebhook::pipeline()`** → `RevolutWebhookSynchronizer::handle()` applies the
   event and returns `bool`.
5. **support dispatches `Events\WebhookHandled`** only when that bool is `true`.

## The two sets, which are not the same set

- **Subscribed** — `config('cashier-revolut.webhook.events')`, defaulting to all 22 cases of
  `Enums\RevolutWebhookEvent`. This is what `RevolutGateway::registerWebhook()` tells Revolut to
  send. An event not in this list is never *delivered*, so it reaches no listener at all.
- **Applied** — the `match` in `RevolutWebhookSynchronizer::handle()`, 8 of the 22. Everything
  else takes `default => $applied = false`.

Matching an arm is **necessary, not sufficient** (#35). A mapped event can still find nothing to
apply itself to, and then it is as unhandled as the 14: a refund/chargeback order, an owner that
resolves to nobody, a `SUBSCRIPTION_*` with no local record, a 404 on the refetch. `syncOrder()`
and `syncSubscription()` return `bool` and each arm assigns from them — so the *candidate* set is
the match, and the *applied* set is what those methods answer. Do not latch `true` before the
match again; that was the bug.

The rule they implement is "the delivery had an effect": a local row was written, **or** a payment
outcome was announced. Two edges follow, and both are deliberate — an unchanged subscription
status is `true` (the row was mirrored before the typed event was skipped), and a declined payment
is `true` (nothing persisted, but `PaymentFailed` was dispatched).

`Enums\RevolutWebhookEvent` is the **catalogue** — a fact about Revolut. It says nothing about our
coverage, and must not grow an `isMapped()`. Conflating the two is what made the hatch unreachable.

## Adding an event

To **deliver** one Revolut adds: add the case to the enum. It is then subscribed by default and
reaches `WebhookReceived` listeners, with no further work.

To **apply** one: add an arm to the synchronizer's match that **assigns** from a sync method —
`=> $applied = $this->syncX($event, $id)` — and make that method `return bool`, `true` only when
the delivery had an effect and `false` on every path that returns early having done nothing. An
arm that calls a `void` method drops the answer; one that assigns `true` outright re-creates #35.
Dispatch the support event (`Events\PaymentSucceeded`, `Events\SubscriptionCanceled`, …) from
inside the sync method. Whether an event *deserves* a typed agnostic event is a support question
first (#28) — support leads, drivers follow.

## The one rule

**`pipeline()` returns `false` for an event we do not apply, and never throws.** A throw turns an
event we merely have no opinion about into a failed delivery — Revolut retries a 4XX three more
times at 10-minute intervals and then drops it to the failed-events list, so the cost is wasted
retries and a polluted failure log, not an infinite loop. If making it throw feels more honest,
that instinct is #24 — `WebhookSyncTest` and `WebhookDeliveryTest` will stop you.
