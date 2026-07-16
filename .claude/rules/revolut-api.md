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
  The catalogue is **22 event types in 5 groups**, verified against the `events` enum of
  `create-webhook` / `update-webhook` (identical in both) and cross-checked against the
  embedded JSON of `/docs/api-reference/merchant/`, which yields the same 22 and no others.
  There is no refund event, no renewal event and no plan-change event — but do not read that
  as "the catalogue is small", and **count the table rows before quoting a number**: the
  first draft of this block said 18 because its author grepped for `ORDER_|SUBSCRIPTION_|
  PAYOUT` and never saw the `Dispute` row.

  | Group | Events |
  |---|---|
  | Order | `ORDER_COMPLETED`, `ORDER_AUTHORISED`, `ORDER_CANCELLED`, `ORDER_FAILED`, `ORDER_INCREMENTAL_AUTHORISATION_AUTHORISED`, `ORDER_INCREMENTAL_AUTHORISATION_DECLINED`, `ORDER_INCREMENTAL_AUTHORISATION_FAILED` |
  | Payment | `ORDER_PAYMENT_AUTHENTICATION_CHALLENGED`, `ORDER_PAYMENT_AUTHENTICATED`, `ORDER_PAYMENT_DECLINED`, `ORDER_PAYMENT_FAILED` |
  | Subscription | `SUBSCRIPTION_INITIATED`, `SUBSCRIPTION_FINISHED`, `SUBSCRIPTION_CANCELLED`, `SUBSCRIPTION_OVERDUE` |
  | Payout | `PAYOUT_INITIATED`, `PAYOUT_COMPLETED`, `PAYOUT_FAILED` |
  | Dispute | `DISPUTE_ACTION_REQUIRED`, `DISPUTE_UNDER_REVIEW`, `DISPUTE_WON`, `DISPUTE_LOST` |

  `RevolutWebhookEvent` maps **8 of those 22**. Every case it maps is real; the other 14 are
  received and dropped with a 200 (that is the driver half of #24). Nothing here is a
  hypothetical future event — they are documented today, and the dropped set includes every
  `DISPUTE_*`, i.e. a customer disputing a charge is invisible to the app.

- A payment going to 3DS is **not** only visible via `ORDER_PAYMENT_AUTHENTICATION_CHALLENGED`.
  An app can also pull it: `GET /orders/{id}` returns `payments[].state` =
  `authentication_challenge` plus a `payments[].authentication_challenge` object carrying the
  challenge details, and `POST /orders/{id}/payments` returns that `state` inline. After the
  fact, `decline_reason` carries `3ds_challenge_abandoned` / `3ds_challenge_failed_manually`.
  The unmapped webhook costs an app the *push* signal, not the information — say "must poll",
  not "cannot know".
