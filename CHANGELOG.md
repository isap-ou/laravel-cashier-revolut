# Changelog

All notable changes to `isapp/laravel-cashier-revolut` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

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

  **Requires `isapp/laravel-cashier-support` ^2.0** (nullable quantity +
  `Capability::SubscriptionQuantity`). Republish and run its migrations when
  upgrading — see that package's changelog.

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

## [1.0.0] - 2026-07-02

### Added

- `RevolutGateway` implementing the `isapp/laravel-cashier-support` (^1.1)
  `GatewayProvider` contract: orders-based charges and refunds, customers,
  native Subscriptions API (create/cancel/trials), payment methods
  (list/delete), Checkout Widget sessions, and local invoices via the support
  package's `Gateway\ManagesLocalInvoices`.
- Honest capability gating: subscription pause/resume/swap/cancel-now and
  server-side payment-method creation throw `UnsupportedOperationException` —
  no local workarounds.
- Money-safe semantics throughout: refund-type orders are never booked as
  payments, unknown currencies raise `RevolutApiException` (no EUR-defaulting),
  amounts are required integer minor units.
- Real paid-through grace periods: `cancelSubscription()` records the active
  billing cycle's `end_date` (via `current_cycle_id`, fetched before
  cancelling) as `ends_at`, so a canceling customer keeps access through what
  they paid for.
- `RevolutConnector` (injected Http Factory/Config/Logger): pinned
  `Revolut-Api-Version: 2026-04-20`, per-request `Idempotency-Key`,
  transient-only retries with exponential backoff, call logging, failures
  raised as `RevolutApiException`; app-facing `Http::revolut()` macro.
- Typed request/response layer on `spatie/laravel-data`, with Revolut
  vocabularies as enums (`RevolutOrderState`, `RevolutOrderType`,
  `RevolutSubscriptionState`, `RevolutWebhookEvent`) — verified against the
  official Revolut OpenAPI specification (contract tests over verbatim
  documented payloads).
- Webhooks: HMAC-SHA256 signature verification (ms/s-tolerant timestamps,
  constant-time, multi-signature incl. rotation), throttled route, and an
  idempotent synchronizer: mirrors subscription state (incl. clearing a stale
  `ends_at`), persists invoices from completed orders, dispatches typed
  support events exactly once per transition, acknowledges deterministic 404s,
  and uses a short `sync_timeout` inside the delivery window.
- `cashier-revolut:webhook` artisan command registering the endpoint and
  printing the signing secret (with orphan-id hint on partial failure).
- Per-driver models (`RevolutSubscription`, `RevolutSubscriptionItem`,
  `RevolutInvoice`) registered via `Cashier::useModels()`; publishable
  migration for `revolut_customer_id`; translatable payment-method labels.
- Tooling: PHPStan (larastan) level 8, Pint, and a Laravel 11/12/13 (PHP
  8.2–8.5) CI matrix with a side-by-side checkout of the support package.

[Unreleased]: https://github.com/isap-ou/laravel-cashier-revolut/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/isap-ou/laravel-cashier-revolut/releases/tag/v1.0.0
