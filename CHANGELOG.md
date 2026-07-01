# Changelog

All notable changes to `isapp/laravel-cashier-revolut` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **Refund-type orders are never booked as paid invoices / `PaymentSucceeded`**
  (a Revolut refund is an order of type `refund`).
- Webhook sync clears a stale `ends_at` when the subscription is active again
  (previously dead code — a customer could stay locally "ended" while Revolut
  kept billing).
- `cancelSubscription()` records a real paid-through grace period (`ends_at` =
  the active billing cycle's `end_date` via `current_cycle_id`, fetched before
  cancelling) instead of ending access instantly.
- Unknown currencies raise `RevolutApiException` instead of silently becoming
  EUR on money records.
- Typed events dispatch exactly once per transition: `PaymentSucceeded` only
  when the invoice record was created; subscription events only on a status
  change — redeliveries are safe. Declined/failed order events dispatch
  `PaymentFailed` with an explicit `Failed` status.
- Deterministic 404s on webhook refetch are acknowledged (no infinite retry
  loop); webhook API calls use a short `sync_timeout` (default 5s) so slow
  refetches don't overlap the sender's delivery window.
- `TypeError` from schema-mismatched 2xx payloads is wrapped into
  `RevolutApiException`; multi-instance `Revolut-Signature` headers survive
  normalization (secret rotation); the webhook command prints the orphan
  webhook id on partial failure.

### Changed

- Revolut vocabularies are enums (`RevolutOrderState`, `RevolutOrderType`,
  `RevolutSubscriptionState`) instead of magic strings.
- Subscription records resolve through the `Cashier::useModels()` registry
  everywhere (no more direct model references); renamed local column usage to
  `type` in line with cashier-support 1.1.
- `cancelSubscriptionNow()` now honestly throws `UnsupportedOperationException`
  (`Capability::SubscriptionCancelNow`) — Revolut has no immediate cancel.

### Added

- `RevolutGateway` implementing the `isapp/laravel-cashier-support` (^1.0)
  `GatewayProvider` contract: orders-based charges and refunds, customers,
  native Subscriptions API (create/cancel/trials), payment methods
  (list/delete), Checkout Widget sessions, and local invoices via the support
  package's `Gateway\ManagesLocalInvoices`.
- Honest capability gating: subscription pause/resume/swap/cancel-now and
  server-side payment-method creation throw `UnsupportedOperationException`.
- `RevolutConnector` (injected Http Factory/Config/Logger): pinned
  `Revolut-Api-Version: 2026-04-20`, per-request `Idempotency-Key`,
  transient-only retries with exponential backoff, call logging, failures
  raised as `RevolutApiException`; app-facing `Http::revolut()` macro.
- Typed request/response layer on `spatie/laravel-data`, verified against the
  official Revolut OpenAPI specification (contract tests over verbatim
  documented payloads).
- Webhooks: HMAC-SHA256 signature verification (ms/s-tolerant timestamps,
  constant-time, multi-signature), enum-mapped events, throttled route, and a
  synchronizer mirroring subscription state, persisting invoices from
  completed orders and dispatching typed support events (idempotent).
- `cashier-revolut:webhook` artisan command registering the endpoint and
  printing the signing secret.
- Per-driver models (`RevolutSubscription`, `RevolutSubscriptionItem`,
  `RevolutInvoice`) registered via `Cashier::useModels()`; publishable
  migration for `revolut_customer_id`; translatable payment-method labels.
- Tooling: PHPStan (larastan) level 8, Pint, and a Laravel 11/12/13 CI matrix
  with a side-by-side checkout of the support package.

[Unreleased]: https://github.com/isap-ou/laravel-cashier-revolut/commits/main
