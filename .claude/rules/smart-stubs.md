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
- **Do NOT** simulate subscription pause/resume/swap with cancel+create

## Supported Revolut operations (verified via OpenAPI spec 2025-12-04)

- `charges` — POST /orders + POST /orders/{id}/payments
- `refunds` — POST /orders/{id}/refund
- `customers` — full CRUD: POST/GET/PATCH/DELETE /api/customers
- `subscriptions` — create, list, get, cancel (POST /api/subscriptions/{id}/cancel)
- `subscription.trials` — `trial_duration` at subscription creation
- `payment_methods.list` — GET /api/customers/{id}/payment-methods
- `payment_methods.get` — GET /api/customers/{id}/payment-methods/{id}
- `payment_methods.delete` — DELETE /api/customers/{id}/payment-methods/{id} (204)
- `checkout` — Revolut Checkout Widget
- `webhooks` — ORDER_COMPLETED, SUBSCRIPTION_* events

## Provider-independent (handled by cashier-support, not Revolut API)

- `invoices` — built from local payment/subscription data by cashier-support
- `invoice.download` — PDF rendered by cashier-support (InvoiceRenderer)

## Unsupported Revolut operations → UnsupportedOperationException

- `subscription.pause` — state `paused` exists but no API endpoint to trigger
- `subscription.resume` — no endpoint
- `subscription.swap` — PATCH only supports `external_reference`, no plan change
- `payment_methods.add` — no direct API, only via checkout widget flow
