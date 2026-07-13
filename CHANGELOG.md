# Changelog

All notable changes to `isapp/laravel-cashier-revolut` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- `refund()` now dispatches `RefundProcessed`. `Capability::Refunds` was
  declared and the API call worked, but the lifecycle event was never fired, so
  an app listening for refunds through the provider-agnostic API got nothing.
  This is the only path to that event — Revolut's webhook catalogue has no
  refund event at all (Order, Payment, Subscription, Payout and Dispute only),
  so a refund issued from the Revolut dashboard cannot be observed.

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
