# Changelog

All notable changes to `isapp/laravel-cashier-revolut` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

> The first release, **1.0.0**. The package was never published to a consumer, so
> its pre-release history has been collapsed into this one entry rather than
> carried as a version trail that describes tags nobody ever installed.

### Added

- **A scheduled plan change is now visible to the app.** Revolut reports it back on
  the subscription as `scheduled_action`, so the driver records it as the pending
  price (`hasPendingPriceChange()` / `pendingPrice()` / `pendingPriceStartsAt()`) and
  dispatches the new `SubscriptionPriceChangeScheduled` — **instead of**
  `SubscriptionUpdated`, which now fires only when the change actually lands on the
  paid renewal.

  A deferred swap used to discard its own most important output: the item row keeps
  naming the variation the customer is still billed on (correctly), and the requested
  one lived nowhere, so a successful swap was indistinguishable from no swap and
  "you'll move to Pro on 1 Aug" could not be rendered.

  The pending price is what **Revolut** scheduled, never what was requested — if the
  gateway scheduled something else, the customer must be shown what they will actually
  be moved to. A scheduled *cancellation* (the other `scheduled_action` type) is not
  read as a price change.

- **Revolut now declares what it can actually honour.** `Capability::SubscriptionSwapAtPeriodEnd`
  and `Capability::CheckoutAmount` — and, deliberately, **not** `SubscriptionSwapImmediate`
  or `CheckoutPrices`. Asking for an immediate swap (including by omitting the timing, since
  `SwapTiming::Immediate` is the default) raises `UnsupportedOperationException` instead of
  quietly scheduling a change that lands next month. `RevolutCheckoutSession::clientSecret()`
  returns the order token — the contract's provider-neutral name for it.

- **A renewal now has a signal.** `SubscriptionRenewed` is dispatched when a
  billing cycle is paid for, carrying the invoice that settled it. A plain
  renewal previously fired no subscription event at all — Revolut sends no
  renewal webhook, and the `SUBSCRIPTION_*` events do not fire on one — so a
  monthly renewal produced only a payment and an orphan invoice row.
  Reconstructed from `ORDER_COMPLETED` whose `subscription_data.billing_reason`
  is `cycle_billing`, which is the only place a renewal is visible.
  Announced exactly once, keyed on the invoice not yet being linked to its
  subscription, so a redelivery does not double-extend entitlement — and a first
  delivery that failed halfway is repaired by the redelivery rather than lost.
- **Subscriptions record the period they are paid through.** The billing cycle
  the driver already fetched at cancellation is no longer thrown away, and the
  webhook sync fills it too, so `currentPeriodEnd()` answers "when am I next
  billed?". The cycle fetch is tolerant: it is enrichment, and it must never cost
  the status write it accompanies.
- **Invoices say what they paid for** — `subscription_id`, the cycle's period, and
  a `billing_reason`. The setup order is linked as well as renewals: leaving it out
  would put a hole at the start of every billing history. An unrecognised reason is
  recorded as unknown rather than guessed as a renewal.

### Changed

- **`withMetadata()` throws instead of silently losing the data.** The driver sent a
  `metadata` field on `POST /api/subscriptions`; the API documents five fields and that
  is not one of them, and no subscription endpoint returns it. Revolut ignored it, so an
  app correlating a subscription with its own records had that correlation thrown away.

  Revolut's correlation surface is a single `external_reference` string — not a metadata
  map — and it is exposed as one: `RevolutSubscriptionBuilder::externalReference()`.
  Mapping a one-entry array onto it would have made the same call work or fail depending
  on how much the caller put in the array. The `create($pm, ['metadata' => ...])` back
  door is closed too, as it was for `quantity`.

- **A malformed argument is no longer reported as a subscription update failure.**
  A swap to no price at all (or to more prices than Revolut bills a subscription on),
  a bad phase id, an unknown change reason — all now raise `InvalidArgumentException`,
  as the reference Cashier packages do. `SubscriptionUpdateFailure` keeps its actual
  meaning: the *subscription* could not be updated (there is none, the gateway refused).

  Catching `SubscriptionUpdateFailure` around a swap used to silently swallow a bug in
  the call itself.

- **Breaking: checkout takes a `CheckoutRequest`, swap takes a `SwapTiming`.**
  `$user->checkout('plan', ['amount' => 1500])` becomes
  `$user->checkout(CheckoutRequest::forAmount(1500, Currency::EUR))`, and
  `$user->swapSubscription('default', $variation, $options)` becomes
  `$user->swapSubscription('default', $variation, SwapTiming::AtPeriodEnd, $options)`.
  The driver's own `InvalidArgumentException` for a missing `options['amount']` — which
  lived outside the `CashierException` hierarchy — is gone: the amount is a typed field,
  and a price-shaped request is refused by cashier-support before the driver sees it.
  The order body now also carries `description` and `metadata` from the request. Nothing
  was ever published, so no installation is affected.

- `SUBSCRIPTION_OVERDUE` now maps to `WebhookEvent::SubscriptionPastDue` and
  dispatches `SubscriptionPastDue`, not `SubscriptionUpdated`. A failed payment is
  not "something changed" — that mapping was the second thing overloading that
  event.
- Requires `isapp/laravel-cashier-support` ^1.0.

- **The Revolut customer id no longer lives on the app's users table.** It is a
  row in the support package's `cashier_customers` (morphed owner + provider +
  provider_id), so `users.revolut_customer_id`, its migration, and the
  `billable_model` config key are all gone — and this package now ships no
  migrations at all.

  The old column forbade polymorphism structurally: an order webhook resolved its
  owner through a **single configured billable class**, so a Team could not be
  billed alongside a User — its order resolved no owner and its invoice was
  silently dropped. The reverse lookup is now polymorphic and finds any billable
  type, which is what let `billable_model` be deleted rather than merely
  deprecated.

  An app reads the id with `$user->customerId()` — provider-neutral — instead of
  `$user->revolut_customer_id`.

### Fixed

- **An event this driver does not map now reaches a `WebhookReceived` listener.**
  Revolut documents **22** event types and `RevolutWebhookEvent` maps **8**, so 14 were
  acknowledged with a 200 and vanished — the 4 `DISPUTE_*`, the 3 `PAYOUT_*`, the 3
  `ORDER_INCREMENTAL_AUTHORISATION_*`, `ORDER_AUTHORISED`, `ORDER_CANCELLED`,
  `ORDER_PAYMENT_AUTHENTICATED` and `ORDER_PAYMENT_AUTHENTICATION_CHALLENGED` (3DS).
  **No listener ever saw them**, so a customer disputing a charge was invisible to the
  app, and `DISPUTE_ACTION_REQUIRED` is the one with a deadline attached. Requires
  `isapp/laravel-cashier-support` #42.

  `event(new WebhookReceived(...))` sat **below** `parseWebhook()`, which throws
  `UnexpectedWebhookEventException` for exactly those 14 — and the controller caught it
  and returned first, so the dispatch was unreachable for every unmapped event. It now
  sits above the parse and below `verifyWebhook()`, which is where both references put
  it (`laravel/cashier`'s `Http/Controllers/WebhookController.php:45`, `-paddle`'s `:49`).

  **The reorder is four lines, and it still needed support to move first.** The event
  carried a typed `Support\DTO\WebhookPayload` whose `$event` was a non-nullable 8-case
  enum: for an unmapped event the payload could not be *constructed*, so there was
  nothing to hoist. Support#42 made `WebhookReceived`/`WebhookHandled` carry the raw
  decoded body — as the references always have — and only then was there something to
  move. `parseWebhook()` still throws for an unmapped event, which is now harmless: the
  hatch has already fired above it.

  Unchanged on purpose: an unmapped event still answers `200 "Webhook ignored."`, because
  Revolut retries a 4XX three times ten minutes apart and retrying an event we have no
  handler for would never succeed. **`WebhookHandled` still does not fire** for one —
  nothing was handled, and saying otherwise would trade the old silence for a lie. The
  reference draws the same line (`WebhookController.php:47-52`).

  Signature verification still runs first, and that matters more now that the hatch is
  wide: an unverified body is not an event, and this is exactly where anyone who could
  reach the URL would fabricate one. A test pins the ordering rather than trusting it.

- **A caller-level retry no longer charges or refunds twice.** The connector minted a
  fresh `Idempotency-Key` per API call, which protects the transport — `->retry()`
  re-sends the same request, so a transient failure keeps its key — and nothing above
  it. A queued job that retried after the call had already succeeded (a mailer timeout,
  a deadlock in a listener) arrived with a brand-new key, and Revolut saw a brand-new
  operation: real money, moved twice, silently.

  `charge()`, `refund()` and the subscription builder's `create()` now accept
  `options['idempotency_key']`, because only the caller knows what the *operation* is.

  How each is made safe differs, because the API differs: Revolut accepts the
  `Idempotency-Key` header on the refund, the subscription create and usage records —
  **and on nothing else**. `POST /orders` and `POST /orders/{id}/payments` accept none,
  so keying them would have been a no-op dressed up as a guarantee. Instead a charge
  writes the key as the order's own `merchant_order_data.reference` and a retry finds
  that order (`GET /orders?merchant_order_data_reference=`) rather than creating a
  second one: an order already paid is returned as it stands, and one left unpaid by a
  half-finished attempt is paid rather than duplicated.

  Still not idempotent, and documented as such: `createCustomer()` (no key, and the
  customer list cannot be filtered by email) and `checkout()` (a spare unpaid order, but
  no money moves). The default key stays random: a deterministic one would silently
  swallow the second of two legitimate partial refunds, which Revolut explicitly allows.

- **An app-initiated cancellation now fires `SubscriptionCanceled`.** It fired nothing at
  all: `cancelSubscription()` wrote the status itself, and the `SUBSCRIPTION_CANCELLED`
  webhook that followed found the status already `Canceled` and short-circuited on its
  "only announce a real transition" guard. So the *common* case — the customer cancelling
  in the app — silently ran no listener: no revoked access, no dunning, no analytics.
  Only a cancellation made in the Revolut dashboard produced an event.

  Cancelling an already-cancelled subscription is now a local no-op: Revolut refuses to
  cancel one that is `cancelled` or `finished`, so a repeat click used to reach the API
  and come back as an exception. And a subscription that never paid its setup order
  announces no cancellation, because it was never announced as created.

  `SubscriptionCreated` is now also dispatched by the builder — but **only** when the
  subscription comes back live. Revolut creates one `pending`, with a setup order the
  customer still has to pay in the Checkout Widget, and announcing that would grant
  access to a customer who may never pay (an abandoned setup produces no webhook, so
  nothing would take it back). The paid setup, reported as `SUBSCRIPTION_INITIATED`, is
  what announces it. A subscription that is live at creation has no such transition
  coming, and would otherwise be announced nowhere.

- **A misconfigured driver refuses the webhook with a 400 and a log line, instead of
  rendering an unhandled 500.** `verifyWebhook()` threw `InvalidConfigurationException`
  and the controller did not catch it — and neither did it catch the same exception from
  the **synchronizer**, which calls back into the API, so a missing `secret_key` (as
  opposed to the webhook secret) 500'd one path over.

  Revolut acknowledges a delivery with any 200-399 and retries a failed one — a 4XX or a
  timeout — three more times, ten minutes apart. That window is the event's only chance
  of surviving the fix; a 500 renders whatever the app's error handler decides and its
  retry behaviour is undocumented.

  Every refusal is now audible: `critical` for a misconfiguration, `warning` for a
  signature that does not verify (a rotated secret is the likelier mistake, and refused
  every webhook in complete silence), `info` for an event acknowledged without being
  handled. The alternative symptom is a subscription mirror that quietly goes stale.

- `UnexpectedWebhookEventException` moved to cashier-support: it was a driver-private
  type thrown from a contract method, so a support-level caller of `parseWebhook()` could
  not catch it without naming the driver.

- **An order never linked to its customer.** The order body sent a flat
  `customer_id`, which `POST /api/orders` does not define — the customer is a nested
  `customer` object. Revolut ignored the field, so every order (checkout *and*
  charge) was created attached to nobody: the widget offered no saved card, and the
  card used to pay was never attached to the customer record, leaving a later
  `charge($amount, $savedPaymentMethodId)` with no payment method to reach for.

- **The driver was sending a `quantity` field Revolut does not accept.**
  `POST /api/subscriptions` documents exactly five fields, and `quantity` is not
  among them — a Revolut subscription has no per-customer quantity at all. It
  lives on the *plan variation's* items (a `flat` item is a fixed amount
  multiplied by its quantity), fixed when the plan is created. So an app calling
  `->quantity(5)` believed it had bought five seats while Revolut billed whatever
  the plan said.

  `quantity()` now throws `UnsupportedOperationException`, the field is gone from
  the create request, and the local item row stores `null` — "not applicable",
  which is the truth. To sell seats, create a plan variation that prices them.

  `create()`'s `$options` is a passthrough to the API, so it is also a back door
  for the same field: `create(null, ['quantity' => 5])` now throws too. Without
  that guard the rest is decoration.

  A stored quantity on an existing item row is replaced with `null` on the next
  sync. It could only have come from the phantom field, and Revolut never billed
  it — keeping it would preserve a fiction the gateway never honoured.

- Every sync path now writes the local `cashier_subscription_items` row, not just
  `newSubscription()`. It previously could not: with a `NOT NULL` quantity column
  the only options were to invent a `1` or to write nothing, so a subscription the
  builder had not created had no item row and `subscribedToPrice()` returned
  `false` for it forever.

  Writing the row for the first time is a **backfill, not a plan change**, so it
  does not dispatch `SubscriptionUpdated`.

  Requires `isapp/laravel-cashier-support` ^1.0 (nullable quantity +
  `Capability::SubscriptionQuantity`).

### Added

- `swapSubscription()` via `POST /subscriptions/{id}/change-plan`, and
  `Capability::SubscriptionSwap` in `RevolutGateway::capabilities()`. Revolut
  does support plan changes — just not through the update endpoint (which only
  covers `external_reference`), which is why they were previously believed
  impossible. The change is **scheduled at cycle end**, not immediate: the
  customer finishes the current cycle on the old variation, nothing is
  prorated, and a trial on the target variation is skipped. `$options` accepts
  `plan_variation_phase_id` and a `reason` (`RevolutChangePlanReason`).
  Dispatches `SubscriptionUpdated`.
- The plan variation a subscription is billed on is now persisted as its local
  `cashier_subscription_items` row, so `subscribedToPrice()` and
  `onTrial($type, $price)` work for Revolut — previously no item row was ever
  written and both could only return false. **This applies to subscriptions
  created from this version on.** Only `newSubscription()` creates the item row,
  because only it is told the quantity: the Revolut subscription resource does
  not expose one, so no sync path may insert a row and silently default a
  five-seat subscription to one seat. Subscriptions created before this version
  therefore have no item row, and there is no backfill.
- `SubscriptionUpdated` is also dispatched when a scheduled plan change actually
  lands (detected on the paid renewal), not only when it is scheduled.

### Fixed

- The local plan variation now catches up when a scheduled swap actually lands.
  Revolut fires no webhook for a plan change, and the `SUBSCRIPTION_*` events do
  not fire on a normal renewal — so the driver now re-mirrors the variation when
  the renewal order completes (`ORDER_COMPLETED` whose
  `subscription_data.billing_reason` is `cycle_billing`), as well as on any
  subscription sync. `OrderResponse` maps `subscription_data` for this.
- `cancelSubscription()` now returns a `Subscription` DTO carrying `endsAt`. The
  grace period was written to the local record but dropped from the returned
  DTO — which is the contract's declared return type, so an app rendering the
  cancellation from it told the customer access had ended immediately while they
  had in fact paid through the end of the billing cycle. `toSubscription()` takes
  the date from the caller because Revolut's subscription resource has no end
  date to map from: it lives on the active billing cycle.
- `refund()` now dispatches `RefundProcessed`. `Capability::Refunds` was
  declared and the API call worked, but the lifecycle event was never fired, so
  an app listening for refunds through the provider-agnostic API got nothing.
  This is the only path to that event — Revolut's webhook catalogue has no
  refund event at all (Order, Payment, Subscription, Payout and Dispute only),
  so a refund issued from the Revolut dashboard cannot be observed, and neither
  can final settlement.
- `refund()` now honours the refund order's state instead of trusting the bare
  2xx, and throws `PaymentFailedException` when Revolut rejects it. The endpoint
  answers `201 Refund order successfully created` — the refund is an order in
  its own right, so a success response can still carry a `failed` or `cancelled`
  state. `RefundResponse` maps `state` for this; the guard mirrors `charge()`.
- Subscription webhooks now carry the grace period on the event's DTO.
  `SubscriptionCanceled` was dispatched with `endsAt: null` while the record
  held a real paid-through date — the same defect as above, on the webhook path.

### Changed

- Subscription pause/resume remain unsupported, but swap no longer throws
  `UnsupportedOperationException`.

[Unreleased]: https://github.com/isap-ou/laravel-cashier-revolut/commits/main
