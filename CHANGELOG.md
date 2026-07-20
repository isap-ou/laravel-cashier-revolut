# Changelog

All notable changes to `isapp/laravel-cashier-revolut` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

> The first release, **1.0.0**. The package was never published to a consumer, so
> its pre-release history has been collapsed into this one entry rather than
> carried as a version trail that describes tags nobody ever installed.

### A subscription can no longer go live at Revolut without us knowing

- **`create()` wrapped only the API call.** The local write sat outside that try/catch and
  outside any transaction, so a DB failure after a `201` left a subscription **live at Revolut,
  billing the customer, with no local record** — while the app caught a bare `QueryException`
  saying "database error", which reads like nothing happened.

  Nothing repaired it afterwards, which is what made it a release blocker rather than a nuisance:
  every later `SUBSCRIPTION_*` webhook found no local record, the synchronizer returned false,
  support's controller answered 200, and Revolut never redelivered. The customer was charged
  every cycle, indefinitely, and no log said so.

  Revolut has no undo for `POST /subscriptions`, so this cannot be rolled back — it can only be
  *reported*. The local writes now run in a transaction (a failure between the subscription row
  and its item row used to leave a subscription with no item, which is worse than a crash:
  `subscribedToPrice()` then answers false forever for a subscription that is billing), and any
  failure surfaces as `RevolutApiException::subscriptionCreatedButNotRecorded()` — catchable as a
  `CashierException`, naming the subscription id so the orphan is findable in the dashboard, and
  carrying the original exception as `previous`.

- **One declined attempt announces one `PaymentFailed`.** Revolut sends **three** events for a
  single decline — `ORDER_PAYMENT_DECLINED`, `ORDER_PAYMENT_FAILED`, `ORDER_FAILED` — and all
  three reach `syncOrder()` against the same order id. The success path has been deduplicated
  since it was written (`updateOrCreate` + `wasRecentlyCreated`); the failure path wrote nothing,
  kept no marker, and re-announced every time. An app whose listener emailed the customer and
  counted toward suspension sent three emails and suspended after one decline.

  A failed attempt is now recorded as an invoice row with `PaymentStatus::Failed`, keyed on the
  same unique `(provider, provider_id)` index the success path uses — so the dedup is the same
  mechanism rather than a second one that can drift from it. **Consequence worth knowing:**
  support's `invoices()` does not filter by status, so declined attempts now appear in a
  billable's invoice list. That is deliberate — a decline is part of a billing history and the
  DTO carries the status to filter on — but it is a visible change if you render that list raw.
  The redelivery now returns `false`, so `WebhookHandled` does not fire for it either.

- **CI installs `ext-intl`.** This package depends on `cashier-support`, which requires it since
  `Cashier::formatAmount()` builds a `NumberFormatter` — so the platform requirement reaches here
  too. It was passing only because the runner image happened to carry intl.

- **One more README snippet corrected.** `$user->subscription('default')->swap(…)` calls a method
  on a nullable receiver; `subscription()` returns `?Subscription`. The previous round fixed
  exactly this defect for the `externalReference()` example one section below and missed this one.

### A public-API boundary, and four README snippets that could not run

- **31 classes are now marked `@internal`, and README says what SemVer covers.** Without this a
  1.0 tag freezes every `public` method in `src/` — and for a driver most of that is a
  transcription of Revolut's wire format, which Revolut changes on its own schedule. Excluded:
  `Http\Requests\*` and `Http\Responses\*` (20), `Concerns\*` (9 traits composed into
  `RevolutGateway`), and `Webhooks\RevolutWebhookSynchronizer` / `RevolutIncomingWebhook`.
  Covered: `RevolutGateway`, the builder's own setters, every `Enums\*` case, the `Models\*` and
  their tables, `RevolutApiException`, `RevolutCheckoutSession`, the `RevolutConnector` and
  `RevolutWebhookVerifier` container bindings (because "Extending" tells you to decorate them),
  and the published config keys. The support package did this for its own surface; the driver had
  not, which would have frozen the Revolut API's shape into our SemVer promise.

- **Four README examples were wrong, three of them fatally.** Each is the shape of support#38 — a
  document describing an API that does not exist:
  - `use Isapp\CashierSupport\Enums\Currency;` with `Currency::EUR`. That enum was removed in
    support#32; the field is a `Money\Currency`. The same README already wrote `new Currency('EUR')`
    correctly forty lines earlier.
  - `$user->swapSubscription('default', …)`. Not on `Billable` — support#39 moved swap onto the
    model, `$user->subscription('default')->swap(…)`, which this README also showed correctly
    elsewhere. The old call's fourth positional argument was `$options`, which is now `$proration`.
  - `Cashier::provider()->newSubscription(…)->externalReference(…)` and
    `Cashier::provider()->subscriptionExternalReference(…)`. Neither can work: `provider()` returns
    support's `GuardedProvider`, which is `final`, has no `__call`, and hands back a `final`
    `GuardedSubscriptionBuilder` carrying only the contract's setters. The route that does work is
    `Cashier::driver('revolut')` — the raw driver — and the README now says so, along with the
    trade: no capability gate in front of it, so ask `Cashier::supports()` yourself.
  - `RevolutWebhookHandler` named as a container singleton. It has been `RevolutWebhookVerifier`
    since the rename; the singletons are `RevolutConnector`, `RevolutWebhookVerifier` and
    `RevolutGateway`.

- **`RELEASING.md` no longer claims this repository is private** — it is public, with a live
  Packagist webhook, so the step pointed the releaser at infrastructure that is not in play. It now
  states that support must be **published** (not merely tagged) before this package is tagged, and
  that neither check can be done from inside the monorepo: the `path` repository pins a fake
  `1.0.0` that satisfies `^1.0` forever, here and in CI, which is exactly why the constraint sat
  unsatisfiable and unnoticed.

### `WebhookHandled` now means the delivery had an effect, not "the event was recognised" (#35)

- **`handle()` no longer assumes a matched event was applied.** It set `$applied = true` before
  the `match` and cleared it only in the `default` arm, so any of the 8 events this driver maps
  announced `Events\WebhookHandled` — even when it wrote nothing and returned early. Apps
  reconcile on that event, and it was telling them a delivery had changed local state when it had
  not. `syncOrder()` and `syncSubscription()` were `void`; they now return `bool` and answer for
  themselves.

- **Three deliveries flip from handled to not-handled**, all of them ones the driver recognises
  and deliberately does nothing with: an `ORDER_COMPLETED` whose refetched order is a refund or
  chargeback rather than a payment (`isPaymentOrder()` false); an order whose Revolut customer
  resolves to no local billable; and a `SUBSCRIPTION_*` for a subscription with no local record,
  e.g. one created outside this app. The pre-existing 404 branch already returned `false` and
  reasoned this way in a comment — the sibling paths now agree with it instead of contradicting
  it on the same screen. `WebhookReceived` is unaffected and still fires for every verified body.

- **Two boundaries the fix deliberately does not cross.** An **unchanged subscription status**
  is still `true`: it dispatches no typed event, but the record was mirrored from the API before
  that point — billing period, plan variation and any scheduled price change are written
  regardless, and returning `false` there was the tempting over-correction. A **declined
  payment** is also still `true`, and it is the reason the rule is "had an effect" rather than
  "wrote a row": nothing is persisted, but `PaymentFailed` was dispatched against a resolved
  owner, and that announcement is the outcome an app reconciles against. The two halves of the
  rule are exactly `syncOrder()`'s `@return`: *true when the order was booked **or** a payment
  outcome was announced*. Note that a redelivery of an already-booked order is `true` as well —
  `updateOrCreate` rewrites the same row and `wasRecentlyCreated` suppresses the duplicate
  event, which is idempotency working, not an absence of effect.

- **Three tests were pinning the old behaviour and are corrected, not relaxed.**
  `test_an_applied_event_says_so` and two in `WebhookDeliveryTest` faked only the default order
  — which is `pending` and carries no customer, so nothing was ever applied — and passed anyway
  because `true` was latched regardless. They now set up an order that genuinely applies (state
  `completed`, a customer resolving to a local billable) and assert the invoice row that follows.
  That they could not distinguish "applied" from "recognised" is the defect restated. (#35)

### Webhooks: subscribe to the whole catalogue, apply eight

- **`Enums\RevolutWebhookEvent` is now Revolut's full 22-event catalogue**, not the 8 the driver
  applies. It had done two jobs at once — it was the subscription list *and* the dispatch map — and
  because registration read its cases, `php artisan cashier:webhook revolut` subscribed the endpoint
  to 8 of 22. The other 14 were therefore never **delivered**, which made `WebhookReceived` — the
  escape hatch #24 and support#42/#47 exist to guarantee — unreachable for exactly the events it
  was built for. Every `DISPUTE_*` was in that set.
- **New `cashier-revolut.webhook.events` config key**, defaulting to every case of the enum. This is
  what `registerWebhook()` subscribes to when the caller passes no explicit list. Narrowing it
  narrows what Revolut *sends*.
- **What the driver applies is now readable in one place** — the `match` in
  `Webhooks\RevolutWebhookSynchronizer::handle()`, which gained `default => false` for the 14 it
  does not sync. `pipeline()` returns false for them and `WebhookHandled` does not fire, unchanged;
  what changed is that they arrive at all.
- **`registerWebhook()` validates against the 22, not the 8.** Subscribing to
  `DISPUTE_ACTION_REQUIRED` used to be refused outright; it is now the supported way to hear about
  a dispute. An unknown name still throws before the call, naming the valid ones.
- **First test coverage for `registerWebhook()`** (`tests/Feature/WebhookRegistrationTest.php`) —
  the method had none, which is why this went unnoticed.

**Upgrading.** An endpoint already registered with the old 8 events keeps receiving only those 8 —
the subscription lives at Revolut, not in this package, so upgrading changes nothing on its own.

To widen it: **delete the existing webhook in the Revolut dashboard first**, then run
`php artisan cashier:webhook revolut`, then store the new signing secret in
`REVOLUT_WEBHOOK_SECRET`. Do not simply re-run the command. `POST /api/webhooks` is documented as
*create* — Revolut publishes a separate `PATCH /api/webhooks/{id}` for updating one, and caps a
merchant at 10 registered URLs — and this driver only ever calls the create. The docs do not say
what posting an already-registered URL does, which is the reason to delete first rather than find
out: if it registers a second endpoint you get two live ones, each with its **own** signing
secret, so every event arrives twice and the endpoint whose secret is not in your config fails
verification on every delivery.

Then expect more deliveries: all of them reach `WebhookReceived`, none change local state, none
fire `WebhookHandled`. If that volume is a problem, narrow
`config('cashier-revolut.webhook.events')` before registering — note that support's webhook route
is throttled, and a throttled delivery is a 4XX Revolut retries a limited number of times before
dropping. If you run `config:cache`, re-run it after upgrading: a cached list is a frozen one and
will not pick up events added to the catalogue in a later release.

### Reworked onto the current cashier-support

- **`RevolutGateway` now extends `Gateway\BaseGateway`.** `capabilities()` and `supports()` are no
  longer hand-written — they are derived from the methods the driver actually overrides, and the
  operations Revolut cannot do (cancel-now, pause, resume, add-payment-method, quantity-update) are
  inherited `Refuses*` refusals instead of throwing stubs. Only the three intents no method can
  express are declared: `SubscriptionSwapAtPeriodEnd`, `SubscriptionTrials`, `CheckoutAmount`.
  Closes the driver's #32. A stub must never be re-added for an inherited refusal: under BaseGateway
  an overridden method reads as *supported*, so a stub would falsely claim the capability.
- **`updateCustomer()` is now supported** (`Capability::CustomersUpdate`), via
  `PATCH /api/customers/{id}` — only the named fields are sent, so an unmentioned one is left
  untouched at the gateway. `createCustomer()` now takes a typed `DTO\CustomerDetails`
  (support#52) instead of an untyped options array.
- **Currency is a `Money\Currency`, not an enum** (support#32). `OrderResponse`/`RefundResponse`
  build it through support's `Casts\CurrencyCast`, keeping a bad code a catchable
  `RevolutApiException` rather than the cast's `InvalidArgumentException`; the driver adds a direct
  `moneyphp/money` dependency. `CheckoutRequest::forAmount(1500, new Currency('EUR'))`.
- **`swapSubscription()` gains a `Proration` parameter** (support#53) to match the current
  contract. Pause, resume and cancel-now are no longer defined in the driver at all — they inherit
  `BaseGateway`'s refusals, which already carry the current signatures for free (pause's
  `?DateTimeInterface $until`, support#72). Revolut swaps only at cycle end and never prorates, so
  the driver accepts the proration intent but does not act on it, and does not declare
  `SubscriptionNoProration` — the support guard therefore refuses a `NoProrate` swap. How the
  abstraction should model a gateway that can never prorate is an open support question.
- **Invoices are deferred.** support#33 moved invoice *rendering* to the driver
  (`Contracts\InvoiceRenderer`/`RendersInvoices`); its engine, layout and contents are an open
  design question, so the driver ships no renderer and `Invoices` reports unsupported. The webhook
  synchronizer still writes the local invoice rows, so no data is lost.
- **A charge routed to 3DS/SCA now surfaces, instead of stalling silently** (support#35). When
  `POST /orders/{id}/payments` comes back `authentication_challenge`, `charge()` returns a
  `Payment` with `RequiresAction` status carrying the order token as its `clientSecret` — as data,
  not a throw. support's `Concerns\PerformsCharges` turns that into a catchable
  `IncompletePaymentException` the frontend can resume from. Payment state is mapped through the
  new `Http\Responses\PaymentResponse`.
- **A bad caller-supplied currency is caught before the request, not after.**
  `currencyFromOptions()` now validates the code against ISO-4217 via support's `CurrencyCast` (the
  same validation the response side uses), so `charge` and `refund` raise `InvalidArgumentException`
  for a malformed code rather than sending it and surfacing Revolut's opaque 4xx. (`checkout()`
  already took a typed `Money\Currency` and is unaffected.) **Upgrade note:** a misconfigured
  `CASHIER_CURRENCY` (a non-ISO-4217 value) now fails fast on every charge/refund that omits an
  explicit currency, rather than a per-call gateway 4xx — set it to a valid ISO code (a lowercase
  code such as `eur` is fine, it is upper-cased).

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

- **`php artisan cashier:webhook revolut` replaces `cashier-revolut:webhook`**, and it no
  longer takes the URL as an argument — it reads it from the route cashier-support mounts
  (`webhook/cashier/revolut`). Requires `isapp/laravel-cashier-support` #47/#48.

  The old command defaulted its URL from `cashier-revolut.webhook.path`, a key that only
  this package's route ever read. Two sources of truth for one address: the day they
  disagreed, the webhook was registered against a URL that answers 404 on every delivery —
  no error, no log, subscriptions simply stop updating. Stripe has always taken the named
  route instead, and now so do we.

  The gateway call itself is still this driver's, behind `Contracts\RegistersWebhooks` —
  an interface rather than a `Capability` because Paddle ships **no** command at all, so
  "the method does not exist" *is* the declaration. Its partial-failure path survives
  intact and matters: Revolut can register the endpoint and return no `signing_secret`, and
  that now throws rather than returning a secret-less result, because the endpoint exists
  by then and a blind retry accumulates duplicates.

  Gone with it: `routes/webhook.php`, `RevolutWebhookController`, the
  `cashier-revolut.webhook.path` / `.middleware` config keys (both live in
  `cashier-support.webhook.*` now, for every driver at once), and the `illuminate/routing`
  dependency. `webhook.signing_secret` / `.tolerance` / `.sync_timeout` stay — none of them
  were ever about HTTP routing.

- **`RevolutWebhookHandler` is `RevolutWebhookVerifier`, and only verifies.** It used to
  also translate an event into a provider-agnostic DTO; that translation is what made an
  unmapped event inexpressible, and it is gone with `Support\DTO\WebhookPayload` and
  `Support\Enums\WebhookEvent` (support#46/#47). What is left does one thing, so it is
  named for it. `RevolutWebhookEvent::toWebhookEvent()` is gone for the same reason —
  nothing read its result. Agnostic meaning travels on the typed events the synchronizer
  dispatches, which carry the billable and a real DTO rather than a name.

- **`RevolutWebhookSynchronizer::handle()` takes the raw body and returns `bool`.** It took
  a `WebhookPayload` and then dug `$payload->data['event']` — a Revolut-native key — back
  out of a field that DTO called "provider-agnostic event data", while never reading the
  agnostic field beside it. That was the whole of support#46, proven by its only reader.
  The `bool` is what support's controller dispatches `WebhookHandled` on.

  One behaviour changed quietly and deliberately: a **404 from the refetch** (the resource
  vanished at Revolut) is still acknowledged and logged, but now reports `false`. The old
  controller dispatched `WebhookHandled` unconditionally after this call — an app listening
  to it is asking "did local state change?", and there the answer was no.

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
  and returned first, so the dispatch was unreachable for every unmapped event.

  **The fix was four lines, and it is not four lines any more — the ordering left this
  package entirely.** Support#47 took the route, the controller and the sequence, because
  four generic lines that every driver must interleave correctly, for a bug whose only
  symptom is silence, is not a thing to hand to each new driver. This package now ships
  `RevolutIncomingWebhook` — `parse()` (verify + read) and `pipeline()` (apply) — and
  nothing HTTP-shaped at all.

  **The rule that replaced the ordering is one sentence: `pipeline()` returns `false` for
  an event this driver does not map, and never throws.** That is the exact inversion of
  what `parseWebhook()` did, and it is pinned in three places, because an inverted rule
  nobody pins gets re-inverted by the next person who finds the old way more intuitive —
  it *was* more intuitive, and it was #24.

  Unchanged on purpose: an unmapped event still answers `200 "Webhook ignored."`, because
  Revolut retries a 4XX three times ten minutes apart and retrying an event we have no
  handler for would never succeed. **`WebhookHandled` still does not fire** for one —
  nothing was handled, and saying otherwise would trade the old silence for a lie. The
  reference draws the same line (`WebhookController.php:47-52`).

  Signature verification still runs first, and that matters more now that the hatch is
  wide: an unverified body is not an event, and this is exactly where anyone who could
  reach the URL would fabricate one. Support fixes *when* it runs by calling `parse()`
  above the dispatch; it cannot prove this package verifies *inside* it, so that half is
  pinned here — including the case where only `pipeline()` is called, which must verify
  too rather than trusting a caller's sequencing.

  **A body we cannot read still reaches no listener**, and the guard for it is this
  package's now — support cannot check a body it never decodes. It is not an unmapped
  event: dispatching `WebhookReceived([])` for it would hand a listener a content-free
  event indistinguishable from a real unmapped one — the same lie told the other way
  round. The references never dispatch one either (Stripe reads `$payload['type']`
  *before* its dispatch). A body that is not a JSON **object** — unparseable, a scalar,
  `null`, or a JSON list — is acknowledged with the same 200 and an info log, and fires
  nothing. The list case also earns the `array<string, mixed>` `parse()` declares instead
  of asserting it: `json_decode('[1,2]', true)` has int keys, and PHP calls it an array.

  This path had no test before, on either side of the change; it has five now.

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
