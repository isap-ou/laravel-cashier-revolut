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
- **Do NOT** build local payment method list/delete if Revolut API doesn't expose it

Unsupported Revolut operations:
- `subscription.pause`, `subscription.resume`, `subscription.swap`
- `invoices`, `invoice.download`
- `payment_methods.list`, `payment_methods.delete`
