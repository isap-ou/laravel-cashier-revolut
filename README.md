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
php artisan vendor:publish --tag=cashier-revolut-migrations
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
$user->cancelSubscription('default');

$session = $user->checkout('plan', ['amount' => 1500, 'currency' => 'eur']);
return $session; // Responsable — redirects to the hosted checkout
```

Money is always **integer minor units** (cents).

## What Revolut supports

| Capability | Supported |
|---|---|
| Charges, Refunds, Customers | ✅ |
| Subscriptions (create, cancel, trials) | ✅ (native Subscriptions API) |
| Payment methods (list, delete) | ✅ |
| Checkout (widget) | ✅ |
| Webhooks | ✅ |
| Subscription pause / resume / swap | ❌ `UnsupportedOperationException` |
| Add payment method server-side | ❌ (only via the checkout widget) |

Unsupported operations throw `UnsupportedOperationException` rather than being
faked — check `Cashier::supports(Capability::…)` before calling.

## Checkout Widget

`checkout()` creates a Revolut order and returns a `RevolutCheckoutSession`
carrying the order `token` for the [Revolut Checkout Widget](https://developer.revolut.com/docs/sdks/merchant-web-sdk/initialize-widget/revolut-checkout)
and the hosted `url`. The session is `Responsable`, so you can `return` it
from a controller to redirect to the hosted page.

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
