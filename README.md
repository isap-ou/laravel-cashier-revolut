# isapp/laravel-cashier-revolut

Revolut Merchant API driver for [`isapp/laravel-cashier-support`](https://github.com/isap-ou/laravel-cashier-support).
Add the support package's `Billable` trait to your model and use the standard
Cashier API — everything routes through Revolut.

## Requirements

PHP `^8.2`, Laravel **11, 12 or 13**, and `isapp/laravel-cashier-support`.

## Installation

```bash
composer require isapp/laravel-cashier-revolut
php artisan vendor:publish --tag=cashier-revolut-config
php artisan vendor:publish --tag=cashier-support-migrations
php artisan migrate
```

Set the driver as the default and configure your keys:

```php
// config/cashier-support.php
'default' => env('CASHIER_DRIVER', 'revolut'),
```

```dotenv
REVOLUT_SECRET_KEY=sk_...
REVOLUT_SANDBOX=true
REVOLUT_WEBHOOK_SECRET=wsk_...
```

## Usage

```php
use Isapp\CashierSupport\Billable;

class User extends Illuminate\Database\Eloquent\Model
{
    use Billable;
}
```

```php
$user->createAsCustomer();
$user->charge(1500, $savedPaymentMethodId, ['currency' => 'eur']);
$user->refund($orderId, ['amount' => 500]);

$user->newSubscription('default', $planVariationId)->trialDays(14)->create($savedPaymentMethodId);
$user->swapSubscription('default', $newPlanVariationId, SwapTiming::AtPeriodEnd);
$user->cancelSubscription('default');

$session = $user->checkout(CheckoutRequest::forAmount(1500, Currency::EUR));
return $session; // Responsable — redirects to the hosted checkout
```

Money is always **integer minor units** (cents).

## What Revolut supports

| Capability | Supported |
|---|---|
| Charges, Refunds, Customers | ✅ |
| Subscriptions (create, cancel, trials) | ✅ (native Subscriptions API) |
| Subscription swap | ✅ (scheduled at cycle end — see below) |
| Payment methods (list, delete) | ✅ |
| Checkout (widget) | ✅ |
| Webhooks | ✅ |
| Subscription pause / resume | ❌ `UnsupportedOperationException` |
| Add payment method server-side | ❌ (only via the checkout widget) |

Unsupported operations throw `UnsupportedOperationException` rather than being
faked — check `Cashier::supports(Capability::…)` before calling.

### Swapping a plan is scheduled, not immediate

Revolut applies a plan change at the **end of the current billing cycle**
(`POST /subscriptions/{id}/change-plan`, `scheduled: at_cycle_end`). This is not
a Stripe-style prorated swap, and the difference is load-bearing:

- **An upgrade does not grant access right away.** The customer finishes the
  current cycle on the old variation, at the old price. Gate your features on
  what the subscription is actually billed on, not on the requested plan.
- **Nothing is prorated.** No credit, no immediate charge.
- **A trial on the target variation is skipped.** Trials only apply when a
  subscription is first created, so swapping "to the trial plan" silently does
  not trial.

Because the change is deferred, the local `cashier_subscription_items` row keeps
naming the variation Revolut still reports — so `subscribedToPrice()` stays
truthful during the remainder of the cycle. Revolut fires no webhook for a plan
change, so the local record catches up when the **renewal is paid**: the
`ORDER_COMPLETED` webhook for the order whose `subscription_data.billing_reason`
is `cycle_billing`. That is the moment the new variation actually takes effect.

**Webhooks must be configured for this to work** — without them the local item
row keeps naming the old variation indefinitely.

`SubscriptionUpdated` is dispatched twice over a swap's life: once when it is
scheduled, and once when it lands on the paid renewal. Distinguish them by the
plan the subscription is actually billed on.

Every sync path writes the local item row, so `subscribedToPrice()` works for any
subscription the driver sees — including one it did not create.

**Revolut has no per-subscription quantity**, so `quantity()` throws
`UnsupportedOperationException` and the stored quantity is always `null`
("not applicable"). Quantity lives on the *plan variation's* items — a `flat`
item is a fixed amount multiplied by its quantity, fixed when the plan is
created. To sell seats, create a plan variation that prices them.

Because the timing is not negotiable, the driver declares
`Capability::SubscriptionSwapAtPeriodEnd` and **not**
`SubscriptionSwapImmediate`. A caller who asks for an immediate swap — including
one who simply omits the timing, since `Immediate` is the default — gets an
`UnsupportedOperationException` instead of a change that quietly lands next
month. Deferral must be asked for:

```php
use Isapp\CashierRevolut\Enums\RevolutChangePlanReason;
use Isapp\CashierSupport\Enums\SwapTiming;

$user->swapSubscription('default', $newPlanVariationId, SwapTiming::AtPeriodEnd, [
    // Optional: which phase of the target variation to start from.
    'plan_variation_phase_id' => $phaseId,
    // Optional: informational only.
    'reason' => RevolutChangePlanReason::CustomerRequest,
]);
```

`SubscriptionUpdated` is dispatched on a successful swap.

## Checkout Widget

Revolut checks out an **amount**, not a catalogue of price identifiers — it has
no checkout price catalogue at all. The driver declares
`Capability::CheckoutAmount` and not `CheckoutPrices`, so a price-shaped request
is refused by cashier-support with `UnsupportedOperationException` before it
reaches the driver.

```php
use Isapp\CashierSupport\DTO\CheckoutRequest;
use Isapp\CashierSupport\Enums\Currency;

$session = $user->checkout(CheckoutRequest::forAmount(
    amount: 1500,
    currency: Currency::EUR,
    description: 'One coffee',
    successUrl: route('checkout.done'),
));
```

`checkout()` creates a Revolut order and returns a `RevolutCheckoutSession`
carrying the order `token` for the [Revolut Checkout Widget](https://developer.revolut.com/docs/sdks/merchant-web-sdk/initialize-widget/revolut-checkout)
and the hosted `url`. The token is also what `clientSecret()` returns — the
contract's provider-neutral name for it. The session is `Responsable`, so you
can `return` it from a controller to redirect to the hosted page.

## Webhooks

Register a webhook with Revolut (prints the signing secret to store in
`REVOLUT_WEBHOOK_SECRET`):

```bash
php artisan cashier-revolut:webhook https://your-app.test/webhook/revolut
```

Incoming webhooks are verified (HMAC-SHA256 over `v1.{timestamp}.{body}`) and
dispatched as the support package's `WebhookReceived` / `WebhookHandled` events
carrying a normalized `WebhookPayload`. Listen to those to react to gateway
activity.

**Refunds are the one gap.** Revolut's webhook catalogue has no refund event —
it covers Order, Payment, Subscription, Payout and Dispute only. `RefundProcessed`
is therefore dispatched from `refund()` itself, and a refund issued from the
Revolut dashboard produces no event at all.

## Architecture

- `RevolutGateway` implements the support `GatewayProvider` contract by composing
  operation concerns; it is registered as the `revolut` driver via `Cashier::extend()`.
- `Http/RevolutConnector` (injected `Http\Client\Factory`, no facades) produces
  the preconfigured `PendingRequest`: version header, idempotency key,
  transient-only retries with backoff, call logging, and failures raised as
  `RevolutApiException`. The same connector backs the app-facing
  `Http::revolut()` macro.
- `Http/Requests` and `Http/Responses` are `spatie/laravel-data` objects that map
  between snake_case Revolut payloads and the support DTOs.

## Extending

**Override the gateway.** `RevolutGateway` is not final and its concern methods
can be overridden. Subclass it and re-register the driver — your application's
service providers boot after this package's, so the registration wins:

```php
// AppServiceProvider::boot()
Cashier::extend('revolut', fn ($app) => $app->make(MyRevolutGateway::class));
```

Or register the subclass side-by-side (`Cashier::extend('revolut-b2b', ...)`)
and select it per model via `cashierDriver()`.

**Swap the building blocks.** `RevolutConnector` and `RevolutWebhookHandler` are
container singletons — decorate or replace them without touching the gateway.
The `Http::revolut()` macro resolves the connector from the container, so a
re-binding changes both the gateway and the macro:

```php
$this->app->extend(RevolutConnector::class, fn ($connector) => new TracedConnector($connector));
```

**Macros.** The `Cashier` facade is macroable (see the cashier-support README)
for app-level helpers that don't warrant a subclass.

## Quality

```bash
composer test      # phpunit (Http::fake)
composer analyse   # phpstan (larastan) level 8
composer format    # laravel pint
```

## License

MIT.
