---
paths:
  - "src/**/*.php"
  - "tests/**/*.php"
---

# Smart Stubs — No Custom Workarounds

When Revolut API does not natively support a feature:

- **DO** throw `UnsupportedOperationException` from cashier-support
- **DO** declare honest `capabilities()` in `RevolutGateway` — only what Revolut actually supports

- **Do NOT** build local invoice generation (dompdf, spatie-pdf)
- **Do NOT** simulate subscription pause/resume with cancel+create

## Supported Revolut operations (verified via OpenAPI spec 2025-12-04)

- `charges` — POST /orders + POST /orders/{id}/payments
- `refunds` — POST /orders/{id}/refund
- `customers` — full CRUD: POST/GET/PATCH/DELETE /api/customers
- `subscriptions` — create, list, get, cancel (POST /api/subscriptions/{id}/cancel)
- `subscription.trials` — `trial_duration` at subscription creation
- `subscription.swap.at_period_end` — POST /api/subscriptions/{id}/change-plan (204). Scheduled
  `at_cycle_end` only — no proration, and a trial on the target variation is
  skipped. NOT a field on the PATCH update endpoint.
- `payment_methods.list` — GET /api/customers/{id}/payment-methods
- `payment_methods.get` — GET /api/customers/{id}/payment-methods/{id}
- `payment_methods.delete` — DELETE /api/customers/{id}/payment-methods/{id} (204)
- `checkout.amount` — Revolut Checkout Widget (an order amount; Revolut has no checkout price catalogue, so `checkout.prices` is NOT declared)
- `webhooks` — we map 8 of the 18 documented event types (Order/Payment/Subscription/Payout);
  see `revolut-api.md` for the verified enum and which 10 we drop

## Provider-independent (handled by cashier-support, not Revolut API)

- `invoices` — built from local payment/subscription data by cashier-support
- `invoice.download` — PDF rendered by cashier-support (InvoiceRenderer)

## Unsupported Revolut operations → UnsupportedOperationException

- `subscription.metadata` — the create body accepts five fields and metadata is not
  one of them; no subscription endpoint returns it. Correlation lives in the single
  `external_reference` string (RevolutSubscriptionBuilder::externalReference())
- `subscription.pause` — state `paused` exists but no API endpoint to trigger
- `subscription.resume` — no endpoint
- `payment_methods.add` — no direct API, only via checkout widget flow
- `subscription.swap.immediate` — change-plan is `at_cycle_end` only
- `checkout.prices` — no checkout price catalogue; checkout takes an amount
