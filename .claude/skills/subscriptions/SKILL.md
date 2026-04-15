---
description: Work with Revolut Subscriptions API — plans, subscriptions, billing cycles
---

# Subscriptions

Revolut has a native Subscriptions API with a plan-based model.

## Endpoints

- **Plans**: `POST/GET /api/subscription-plans` — plan contains variations (e.g. monthly/yearly), each with phases
- **Subscriptions**: `POST/GET/PATCH /api/subscriptions`, `POST .../cancel`
- **Billing Cycles**: `GET /api/subscriptions/{id}/cycles`

## Key differences from Stripe

- Plan-based (plan → variation → phases) vs Stripe's price-based model
- No native pause/resume endpoints (`paused` state exists but no API trigger)
- No swap — implement as cancel + create
- `PATCH` update limited to `external_reference` only
- Trial via `trial_duration` (ISO 8601, e.g. `P14D`) on plan or subscription level

## Webhook events

`SUBSCRIPTION_INITIATED`, `SUBSCRIPTION_FINISHED`, `SUBSCRIPTION_CANCELLED`, `SUBSCRIPTION_OVERDUE`

## Statuses

`pending`, `active`, `overdue`, `paused`, `cancelled`, `finished`