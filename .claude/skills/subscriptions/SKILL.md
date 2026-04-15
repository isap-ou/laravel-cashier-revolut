---
description: Work on the subscription engine (Revolut has no built-in Subscription API)
---

# Subscriptions Engine

Revolut does NOT have a Subscription API — we implement our own (like mollie/cashier-mollie):

1. A scheduled job (daily) checks due subscriptions
2. Charge via saved payment method → `POST /orders` + `POST /orders/{id}/payments`
3. Subscription statuses stored in `revolut_subscriptions` table