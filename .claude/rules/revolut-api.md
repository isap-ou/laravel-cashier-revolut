---
paths:
  - "src/Http/**/*.php"
  - "src/Webhooks/**/*.php"
  - "src/Builders/**/*.php"
  - "src/Concerns/**/*.php"
---

# Revolut API Rules

- Always send `Revolut-Api-Version: 2026-04-20`
- Amounts in minor units (100 = €1.00)
- Webhook signature verification via HMAC-SHA256 over `v1.{timestamp}.{body}`

## Read the spec before you send a field

Every operation is published as markdown:
`https://developer.revolut.com/docs/api/merchant/operations/<operation>.md`
(fetch with curl and a browser User-Agent — `WebFetch` 403s and the HTML is JS-rendered;
see `sources-of-truth.md`).

A field the docs do not list is not "probably fine" — Revolut **ignores** it, so the data
is silently dropped and the code looks like it works. Both phantom fields this driver
shipped (`metadata` on a subscription, a flat `customer_id` on an order) were exactly that.

## Idempotency — verified, and narrower than it looks

`Idempotency-Key` is accepted on **three** operations only: the refund, the subscription
create, and usage records. `POST /orders` and `POST /orders/{id}/payments` accept **none**.

- A **refund** and a **subscription create** are made idempotent with the header, carrying
  the caller's `options['idempotency_key']` — never a key minted per request, which
  protects the transport's own retry and nothing above it.
- A **charge** cannot be. It is made idempotent by writing the caller's key as the order's
  `merchant_order_data.reference` and looking it up on retry
  (`GET /orders?merchant_order_data_reference=`): an order already paid is returned, an
  unpaid one is paid rather than duplicated.
- `createCustomer()` and `checkout()` are **not** idempotent and are documented as such.
  Do not imply otherwise.

## Facts worth not rediscovering

- Order → customer link: a nested `customer: {id}` object. There is no `customer_id` field.
- Order `metadata`: string values only, ≤50 pairs, values ≤500 chars, keys
  `^[a-zA-Z][a-zA-Z\d_]{0,39}$`. A subscription has no metadata at all — correlation is the
  single `external_reference` string.
- `POST /subscriptions` takes exactly five fields: `plan_variation_id`, `customer_id`,
  `external_reference`, `setup_order_redirect_url`, `trial_duration`. `PATCH` takes only
  `external_reference`.
- A subscription is created `pending` and becomes active when its **setup order** is paid.
- `scheduled_action` on a subscription reports what is scheduled but not applied — a union
  of `cancel` and `change_plan_variation`. `change-plan` is `at_cycle_end` and nothing else.
- Cancelling an already `cancelled`/`finished` subscription is refused.
- Webhooks: acknowledged by any 200-399; a 4XX is retried 3 more times, 10 minutes apart.
  The catalogue is **18 event types**, verified against the `events` enum of `create-webhook`
  / `update-webhook` (identical in both). There is no refund event, no renewal event and no
  plan-change event — but do not read that as "the catalogue is small":

  | Group | Events |
  |---|---|
  | Order | `ORDER_COMPLETED`, `ORDER_AUTHORISED`, `ORDER_CANCELLED`, `ORDER_FAILED`, `ORDER_INCREMENTAL_AUTHORISATION_AUTHORISED`, `ORDER_INCREMENTAL_AUTHORISATION_DECLINED`, `ORDER_INCREMENTAL_AUTHORISATION_FAILED` |
  | Payment | `ORDER_PAYMENT_AUTHENTICATION_CHALLENGED`, `ORDER_PAYMENT_AUTHENTICATED`, `ORDER_PAYMENT_DECLINED`, `ORDER_PAYMENT_FAILED` |
  | Subscription | `SUBSCRIPTION_INITIATED`, `SUBSCRIPTION_FINISHED`, `SUBSCRIPTION_CANCELLED`, `SUBSCRIPTION_OVERDUE` |
  | Payout | `PAYOUT_INITIATED`, `PAYOUT_COMPLETED`, `PAYOUT_FAILED` |

  `RevolutWebhookEvent` maps **8 of those 18**. Every case it maps is real; the other 10 are
  received and dropped with a 200 (that is the driver half of #24). Nothing here is a
  hypothetical future event — they are documented today.
