---
paths:
  - "src/**/*.php"
  - "tests/**/*.php"
---

# Smart Stubs — No Custom Workarounds

When Revolut API does not natively support a feature:

- **DO** throw `UnsupportedOperationException` from cashier-support
- **DO** declare honest `capabilities()` in `RevolutGateway` — only what Revolut actually supports

- **Invoices are DEFERRED.** support#33 moved invoice *rendering* to the driver
  (`Contracts\InvoiceRenderer` + `Contracts\RendersInvoices`); support still assembles the
  invoice *data* (`Gateway\ManagesLocalInvoices`). The renderer's engine, layout and contents
  are an open design question, so this driver does **not** declare `Invoices` yet — it is left
  to `BaseGateway`'s `RefusesInvoices` and reports unsupported. When wiring a renderer, the PDF
  engine must be MIT-licensed per `licensing.md`: dompdf (LGPL), mpdf (GPL) and tcpdf (LGPL) are
  copyleft and forbidden; `spatie/laravel-pdf` (MIT) needs headless Chrome; `setasign/fpdf`
  (MIT) is pure-PHP.
- **Do NOT** simulate subscription pause/resume with cancel+create

## Supported Revolut operations (verified via OpenAPI spec 2025-12-04)

- `charges` — POST /orders + POST /orders/{id}/payments
- `refunds` — POST /orders/{id}/refund
- `customers` / `customers.update` — create, get, update (POST/GET/PATCH /api/customers).
  `updateCustomer()` (PATCH) sends only the named fields; `deleteCustomer` has no support contract.
- `subscriptions` — create, list, get, cancel (POST /api/subscriptions/{id}/cancel)
- `subscription.trials` — `trial_duration` at subscription creation
- `subscription.swap.at_period_end` — POST /api/subscriptions/{id}/change-plan (204). Scheduled
  `at_cycle_end` only — no proration, and a trial on the target variation is
  skipped. NOT a field on the PATCH update endpoint.
- `payment_methods.list` — GET /api/customers/{id}/payment-methods
- `payment_methods.get` — GET /api/customers/{id}/payment-methods/{id}
- `payment_methods.delete` — DELETE /api/customers/{id}/payment-methods/{id} (204)
- `checkout.amount` — Revolut Checkout Widget (an order amount; Revolut has no checkout price catalogue, so `checkout.prices` is NOT declared)
- `webhooks` — we map 8 of the 22 documented event types (Order/Payment/Subscription/Payout/
  Dispute); see `revolut-api.md` for the verified enum and which 14 we drop

## Invoices — DEFERRED (see the note above)

Revolut has no invoice API. support assembles invoice DATA locally
(`Gateway\ManagesLocalInvoices`, from stored payment/subscription records); RENDERING is the
driver's since support#33 (`Contracts\InvoiceRenderer`). This driver has not shipped a renderer
yet, so `Invoices` is unsupported for now and tracked as its own issue. The webhook synchronizer
still writes the local invoice rows, so no data is lost while the renderer decision is pending.

## Unsupported Revolut operations → UnsupportedOperationException

- `subscription.metadata` — the create body accepts five fields and metadata is not
  one of them; no subscription endpoint returns it. Correlation lives in the single
  `external_reference` string (RevolutSubscriptionBuilder::externalReference())
- `subscription.pause` — state `paused` exists but no API endpoint to trigger
- `subscription.resume` — no endpoint
- `payment_methods.add` — no direct API, only via checkout widget flow
- `subscription.swap.immediate` — change-plan is `at_cycle_end` only
- `checkout.prices` — no checkout price catalogue; checkout takes an amount
