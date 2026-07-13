# Changelog

All notable changes to `isapp/laravel-cashier-revolut` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

> The first release, **1.0.0**. The package was never published to a consumer, so
> its pre-release history has been collapsed into this one entry rather than
> carried as a version trail that describes tags nobody ever installed.

### Added

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

- `SUBSCRIPTION_OVERDUE` now maps to `WebhookEvent::SubscriptionPastDue` and
  dispatches `SubscriptionPastDue`, not `SubscriptionUpdated`. A failed payment is
  not "something changed" — that mapping was the second thing overloading that
  event.
- Requires `isapp/laravel-cashier-support` ^1.0.

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
