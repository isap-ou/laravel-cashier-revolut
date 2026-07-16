# isapp/laravel-cashier-revolut

## Purpose

Concrete implementation of `isapp/laravel-cashier-support` contracts for **Revolut Merchant API**.
Analogous to `laravel/cashier-stripe` for Stripe and `laravel/cashier-paddle` for Paddle —
those two are the references this driver is measured against. `mollie/laravel-cashier-mollie`
is a last resort only: it builds its own local subscription engine, which the smart-stub rule
forbids.

The user adds `use \Isapp\CashierSupport\Billable;` to the User model
and works with the standard Cashier API — everything routes through Revolut.

## Revolut API

Primary API: **Revolut Merchant API**. Read the reference as markdown, one file per
operation — `https://developer.revolut.com/docs/api/merchant/operations/<operation>.md`
(curl + a browser User-Agent; `WebFetch` 403s and the HTML is JS-rendered). See
`.claude/rules/sources-of-truth.md`.
Version: `2026-04-20` (header `Revolut-Api-Version`)

Merchant API capabilities:
- **Orders** — create orders (analogous to Stripe PaymentIntent)
- **Payments** — process payments on an order
- **Customers** — create/manage customers, save payment methods
- **Subscription Plans** — plan-based model with variations and phases (trial, monthly, yearly)
- **Subscriptions** — create, list, get, update, cancel, change plan (no native pause/resume endpoints)
- **Billing Cycles** — first-class concept with dedicated endpoints per subscription
- **Webhooks** — **22 documented event types** across Order / Payment / Subscription / Payout /
  Dispute. We map 8; the verified enum and the 14 we drop are in `.claude/rules/revolut-api.md`
- **Checkout Widget** — JS widget for accepting payments (analogous to Stripe Elements)

Authorization: API keys from the Revolut Business dashboard.

## Architecture

```
src/
├── RevolutGateway.php              # implements GatewayProvider — central class
├── CashierRevolutServiceProvider.php
│
├── Http/
│   ├── RevolutConnector.php        # produces the configured PendingRequest (+ Http::revolut() macro)
│   ├── Requests/                   # Request DTOs for API
│   └── Responses/                  # Response mapping
│
├── Concerns/                       # Revolut-specific traits (extend support Concerns)
│   ├── ManagesRevolutCustomer.php
│   ├── ManagesRevolutSubscriptions.php
│   ├── ManagesRevolutPaymentMethods.php
│   ├── ManagesRevolutInvoices.php
│   ├── PerformsRevolutCharges.php
│   └── HandlesRevolutCheckout.php
│
├── Models/                         # Concrete Eloquent models
│   ├── RevolutCustomer.php         # extends abstract Customer from support
│   ├── RevolutSubscription.php     # extends abstract Subscription from support
│   └── RevolutSubscriptionItem.php
│
├── Builders/
│   └── RevolutSubscriptionBuilder.php  # implements SubscriptionBuilder
│
├── Webhooks/                       # No controller, no route: cashier-support owns the
│   │                               # entry point (webhook/cashier/{provider}) and the
│   │                               # ORDER of verify → announce → apply. That order was #24.
│   ├── RevolutIncomingWebhook.php  # implements Support\Contracts\IncomingWebhook
│   ├── RevolutWebhookVerifier.php  # HMAC-SHA256 over v1.{timestamp}.{body}
│   └── RevolutWebhookSynchronizer.php  # applies an event; returns false for the 14 we
│                                       # do not map — and never throws for them
│
│   # No Events/ — this driver defines no event classes. Everything it dispatches is a
│   # Isapp\CashierSupport\Events\* class. (This tree claimed an Events/ that never existed.)
│   # No Commands/ — `php artisan cashier:webhook revolut` lives in support, and takes its
│   # URL from support's named route so it cannot drift from where the webhook is mounted.
│
├── config/
│   └── cashier-revolut.php
│
├── database/
│   └── migrations/
│       ├── create_revolut_customers_table.php
│       ├── create_revolut_subscriptions_table.php
│       └── (none — the customer identity lives in the support package's
│            cashier_customers table, not in a column on the app's users table)
│
└── (no routes/ — cashier-support mounts webhook/cashier/{provider} for every driver)
```

## Mapping Stripe → Revolut

| Stripe Cashier            | Revolut Merchant API                         |
|---------------------------|----------------------------------------------|
| PaymentIntent             | POST /orders → POST /orders/{id}/payments    |
| Customer                  | POST /customers                              |
| Subscription              | Revolut Subscriptions API (plan-based)        |
| SetupIntent               | Save card via Checkout Widget                |
| Checkout Session          | Revolut Checkout Widget / hosted page        |
| Webhook (stripe/webhook)  | POST webhook → ORDER_COMPLETED etc.          |
| Invoice                   | Local generation via cashier-support           |
| PaymentMethod (list/get/delete) | GET/DELETE /customers/{id}/payment-methods |
| PaymentMethod (add)       | Not supported (only via checkout widget)      |
| Refund                    | POST /orders/{id}/refund                     |

## Key differences from Stripe

1. **Subscriptions** — plan-based model (plan → variation → phases) vs Stripe's price-based. No native pause/resume endpoints (`paused` state exists but no API trigger). Swap exists as a separate command (`POST /subscriptions/{id}/change-plan`), but is scheduled at cycle end — never prorated, and it skips a trial on the target variation. Update limited to `external_reference` only. Unsupported operations throw `UnsupportedOperationException`.
2. **Invoices** — Revolut has no invoice API. Generated locally by cashier-support from payment/subscription data.
3. **Checkout** — Revolut Checkout Widget (JS) instead of Stripe Checkout hosted page.
4. **Currency** — Revolut supports 30+ currencies, amounts in minor units (cents).

## Configuration (.env)

```
REVOLUT_SECRET_KEY=sk_xxx
REVOLUT_SANDBOX=false
REVOLUT_WEBHOOK_SECRET=wsk_xxx
REVOLUT_API_VERSION=2026-04-20
CASHIER_CURRENCY=eur
CASHIER_CURRENCY_LOCALE=en_IE
```

## Rules

- `declare(strict_types=1)` everywhere
- HTTP via `Illuminate\Support\Facades\Http` (not Guzzle directly)
- All public methods — PHPDoc
- PSR-12 (Pint), PHPStan level 8 (Larastan)
- Tests: Mockery for HTTP, Testbench for integration, 100% coverage

## Known divergences from the reference drivers (audited 2026-07-14)

Both reference `WebhookController`s were compared against ours method-by-method. **Do not work
around a gap locally, and do not assume the webhook layer is done.** For what is open:

```bash
gh issue list --repo isap-ou/laravel-cashier-revolut   # what is open, right now
```

**That command is the status; this section is not.** It carries the *shape* of the gaps, which
outlives any one issue, and does not restate them — a ticket list copied into a doc drifts
silently, and this file has no test over it. Two shapes matter here:

**The webhook escape hatch is open (#24), and the shape of how it got there is the lesson.**
`WebhookReceived` fires for every *verified* body, above every decision about what the event
means — so the **14 of Revolut's 22 documented events we do not map** reach a listener carrying
the raw body, instead of vanishing behind a 200. They include every `DISPUTE_*`;
`DISPUTE_ACTION_REQUIRED` is the one with a deadline attached. (3DS was always a lesser case than
it looks: `ORDER_PAYMENT_AUTHENTICATION_CHALLENGED` is unmapped too, but an app can *poll*
`GET /orders/{id}` for it — see `.claude/rules/revolut-api.md`. What the gap cost there was push,
not knowledge.)

**It was a four-line reorder that this repo still could not do**, and that is the part worth
keeping. `WebhookReceived` carried a typed `Support\DTO\WebhookPayload` whose `$event` was a
non-nullable 8-case enum, so for an unmapped event the payload could not be *constructed* — there
was nothing to move, and every driver-side route out (mapping it onto a wrong case, subclassing
the DTO, inventing a driver event) was worse than the bug. Support moved first, which is where
`.claude/rules/sources-of-truth.md` puts it: its #42 made the events carry the provider's **raw
decoded body**, as both references always have.

**And then support took the ordering away entirely (#47), which is the real ending.** The reorder
was four lines — but four lines each new driver would get a fresh chance to write wrong, for a
bug whose symptom is silence. So the route, the controller and the sequence live in support now;
this package ships `RevolutIncomingWebhook` and nothing HTTP-shaped. The rule that replaced the
ordering is one sentence on one method: **`pipeline()` returns `false` for an event we do not map,
and never throws.** If you are about to make it throw because that reads more honestly — that
instinct is exactly what #24 was, and `WebhookSyncTest` will stop you.

Whether to *map* any of the other 14 is a separate, open question: it needs "does a payout belong
in a provider-agnostic contract at all" answered first, and it is not what the hatch is for.

**Nothing reconciles the customer record when it changes at the gateway (#25).** We handle no
customer-lifecycle webhooks, so a customer deleted at Revolut leaves a local record claiming an
active subscription against a dead id. Pairs with support#36 (no local → gateway sync): the drift
is bidirectional, so fixing either half alone still leaves the records able to diverge.

Where this driver is deliberately **better** than the references (keep it): signature
verification is mandatory and a missing secret is a hard failure (both references silently skip
verification when the secret is unset), `throttle` on the webhook route, transient-only retry
with exponential backoff, idempotency via `updateOrCreate` + `wasRecentlyCreated` gating.

## Navigating this package — use the graph, not grep

`graphify-out/` is a **local build artifact and is not in git** — a fresh clone has no graph
until you build one. Once it exists, start there instead of reading `src/` file by file — and
note the cross-package graph at `../../../graphify-refs/merged/` (also local, and rebuilt with
`graphify merge-graphs`), which is the only place where "which support contract does
`RevolutGateway` implement" is a single query.

```bash
graphify update .                                   # build/refresh it (AST, seconds)
graphify query "how does the webhook reach the synchronizer"
graphify explain "RevolutWebhookSynchronizer"
graphify path "RevolutGateway" "GatewayProvider"    # needs the merged graph (--graph)
graphify affected "RevolutWebhookEvent"             # what breaks if I add an event
```

`graphify update` builds the AST layer only — no API key, no cost. The semantic layer
(INFERRED edges, hyperedges, community names) needs an LLM: `graphify extract . --mode deep`
with a backend key set (`GEMINI_API_KEY`, `ANTHROPIC_API_KEY`, …); without one graphify leaves
`Community N` placeholders. Everything works without it; the queries are just blunter.

The last semantically-built graph is still in git history if you want it back rather than
rebuilt: `git show 978a970:graphify-out/graph.json > graphify-out/graph.json`, then
`graphify update .` to bring the AST layer forward (it was committed once and never refreshed,
so its AST layer is as of that commit).

Do not commit `graphify-out/`. It used to be tracked so a clone could inherit the semantic
layer; the post-commit hook rebuilds without a key and the rebuild is lossy, so tracking eroded
that layer commit by commit while adding ~27k lines of diff to unrelated PRs.
