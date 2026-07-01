# isapp/laravel-cashier-revolut

## Purpose

Concrete implementation of `isapp/laravel-cashier-support` contracts for **Revolut Merchant API**.
Analogous to `laravel/cashier-stripe` for Stripe and `mollie/laravel-cashier-mollie` for Mollie.

The user adds `use \Isapp\CashierSupport\Billable;` to the User model
and works with the standard Cashier API — everything routes through Revolut.

## Revolut API

Primary API: **Revolut Merchant API** (https://developer.revolut.com/docs/merchant/merchant-api)
Version: `2026-04-20` (header `Revolut-Api-Version`)

Merchant API capabilities:
- **Orders** — create orders (analogous to Stripe PaymentIntent)
- **Payments** — process payments on an order
- **Customers** — create/manage customers, save payment methods
- **Subscription Plans** — plan-based model with variations and phases (trial, monthly, yearly)
- **Subscriptions** — create, list, get, update, cancel (no native pause/resume or swap endpoints)
- **Billing Cycles** — first-class concept with dedicated endpoints per subscription
- **Webhooks** — ORDER_COMPLETED, ORDER_PAYMENT_DECLINED, ORDER_PAYMENT_FAILED, SUBSCRIPTION_INITIATED, SUBSCRIPTION_FINISHED, SUBSCRIPTION_CANCELLED, SUBSCRIPTION_OVERDUE
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
│   ├── RevolutSubscription.php     # extends abstract Subscription from support
│   └── RevolutSubscriptionItem.php
│
├── Builders/
│   └── RevolutSubscriptionBuilder.php  # implements SubscriptionBuilder
│
├── Webhooks/
│   ├── RevolutWebhookHandler.php   # implements WebhookHandler
│   └── RevolutWebhookController.php
│
├── Events/                         # Revolut-specific events
│
├── Commands/
│   └── WebhookCommand.php          # php artisan cashier-revolut:webhook
│
├── config/
│   └── cashier-revolut.php
│
├── database/
│   └── migrations/
│       ├── create_revolut_customers_table.php
│       ├── create_revolut_subscriptions_table.php
│       └── add_revolut_columns_to_users_table.php
│
└── routes/
    └── webhook.php
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

1. **Subscriptions** — plan-based model (plan → variation → phases) vs Stripe's price-based. No native pause/resume endpoints (`paused` state exists but no API trigger). No swap endpoint. Update limited to `external_reference` only. Unsupported operations throw `UnsupportedOperationException`.
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
