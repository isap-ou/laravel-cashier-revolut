# Changelog

All notable changes to `isapp/laravel-cashier-revolut` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
