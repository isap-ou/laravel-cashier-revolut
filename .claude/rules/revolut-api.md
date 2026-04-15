---
paths:
  - "src/Http/**/*.php"
  - "src/Webhooks/**/*.php"
---

# Revolut API Rules

- Always send `Revolut-Api-Version: 2025-12-04` header
- Amounts in minor units (100 = €1.00)
- Idempotency key for POST requests (header `Revolut-Request-Id`)
- Webhook signature verification via HMAC-SHA256