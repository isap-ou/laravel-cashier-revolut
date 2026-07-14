# Sources of Truth (priority order)

1. `isapp/laravel-cashier-support` — contracts (STRICTLY follow)
2. **Revolut Merchant API reference, as markdown** (see below) — the primary source
3. `vendor/laravel/cashier` (Stripe) and `vendor/laravel/cashier-paddle` — how a Cashier
   driver is supposed to behave. See "Reference packages" below.
4. https://laravel.com/docs/12.x/billing — the Stripe Cashier docs

## Reference packages, in order

Read them from disk at the monorepo root — do not guess at their behaviour:

1. **`vendor/laravel/cashier`** (Stripe) — the primary reference. Method names, argument
   order, which failures are typed and which are `InvalidArgumentException`, what an event
   means: match it unless there is a stated reason not to.
2. **`vendor/laravel/cashier-paddle`** — the second opinion, and the useful one whenever
   Stripe's answer is Stripe-specific. Where Stripe and Paddle agree, that is the shape of
   the abstraction.
3. `vendor/mollie/laravel-cashier-mollie` — last resort, and **not** a design authority. It
   builds its own subscription engine (local cycles, scheduled order items), which is
   exactly what this package's smart-stub rule forbids. Consult it only for a question
   Stripe and Paddle genuinely cannot answer — e.g. how a gateway with no native deferred
   swap names the thing — and say explicitly that you are doing so, and why.

## Fetch the Revolut docs as markdown

Every operation of the reference is published as a plain markdown file:

```
https://developer.revolut.com/docs/api/merchant/operations/<operation>.md
```

e.g. `create-order.md`, `refund-order.md`, `pay-order.md`, `retrieve-order-list.md`,
`create-subscription.md`, `retrieve-subscription.md`, `cancel-subscription.md`,
`change-subscription-plan.md`, `update-subscription.md`, `retrieve-customer-list.md`.

These files list the exact request-body fields, their constraints, the response schema
and the error responses — which the rendered pages do not give you in a form you can
grep.

**How to fetch them.** `WebFetch` gets a 403 from developer.revolut.com, and the HTML
pages are JS-rendered (curl returns an 800 KB shell with no field names in it). Use
curl with a browser User-Agent:

```bash
curl -s -A "Mozilla/5.0 Chrome/131" -L \
  "https://developer.revolut.com/docs/api/merchant/operations/create-order.md" -o /tmp/create-order.md
```

Note the path is `/docs/api/merchant/...` — `/docs/api-reference/...` and `/docs/merchant/...`
return the SPA shell, not the markdown.

For anything the per-operation files do not cover (a discriminated union's variants, a
header parameter), the full reference page carries the whole schema as embedded JSON:
fetch `https://developer.revolut.com/docs/api-reference/merchant/` and grep the payload.

## Things this project got wrong by NOT reading the spec

Each of these shipped, or nearly shipped, on an assumption:

- An order links its customer through a **nested** `customer: {id}` object. A flat
  `customer_id` is not a field of `POST /api/orders`; Revolut ignored it, so no order was
  ever attached to its customer.
- `Idempotency-Key` is accepted on **three** operations only — the refund, the subscription
  create, and usage records. `POST /orders` and `POST /orders/{id}/payments` accept none, so
  a charge cannot be deduplicated by the header (it is deduplicated via
  `merchant_order_data.reference` + `GET /orders?merchant_order_data_reference=`).
- A subscription's `metadata` does not exist. The create body takes five fields, and
  correlation lives in the single `external_reference` string.
- An order's `metadata` DOES exist, but string values only, ≤50 pairs, ≤500 chars, keys
  `^[a-zA-Z][a-zA-Z\d_]{0,39}$`.
- A subscription reports what it has scheduled but not yet applied, as `scheduled_action`
  (a union: `cancel` | `change_plan_variation`).
- Cancelling a subscription that is already `cancelled` or `finished` is refused.
- A webhook delivery is acknowledged by any 200-399; a 4XX is retried three times, ten
  minutes apart.
