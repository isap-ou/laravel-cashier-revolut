---
paths:
  - "src/Http/**/*.php"
  - "src/Webhooks/**/*.php"
---

# Revolut API Rules

- Always send `Revolut-Api-Version: 2026-04-20` header
- Amounts in minor units (100 = €1.00)
- Idempotency key for POST requests (header `Idempotency-Key` — verified against the refund-order docs for version 2025-12-04)
- Webhook signature verification via HMAC-SHA256