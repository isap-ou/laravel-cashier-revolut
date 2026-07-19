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

// Lifecycle mutations live on the subscription model (support #39), not on the billable.
$user->subscription('default')->swap($newPlanVariationId, SwapTiming::AtPeriodEnd);
$user->subscription('default')->cancel();

// Currency is a moneyphp Money\Currency (support #32), no longer an enum.
$session = $user->checkout(CheckoutRequest::forAmount(1500, new Currency('EUR')));
return $session; // Responsable — redirects to the hosted checkout
```

Money is always **integer minor units** (cents).

## What Revolut supports

Every capability `isapp/laravel-cashier-support` defines (`Enums\Capability`), and whether this
driver backs it. The gateway extends `Gateway\BaseGateway`, so support is **derived from the
code** — a capability holds only when the method(s) behind it are actually implemented — and this
table cannot drift from what `Cashier::supports(...)` returns.

| `Capability` | Revolut | Notes |
|---|:---:|---|
| `Charges` | ✅ | `POST /orders` → `POST /orders/{id}/payments` |
| `Refunds` | ✅ | `POST /orders/{id}/refund` (full or partial) |
| `Customers` | ✅ | `POST` / `GET /customers` |
| `CustomersUpdate` | ✅ | `PATCH /customers/{id}` |
| `Subscriptions` | ✅ | native Subscriptions API (create, cancel) |
| `SubscriptionTrials` | ✅ | `trial_duration` at creation |
| `SubscriptionSwapAtPeriodEnd` | ✅ | `POST /subscriptions/{id}/change-plan` — see below |
| `PaymentMethodsList` | ✅ | `GET /customers/{id}/payment-methods` |
| `PaymentMethodsDelete` | ✅ | `DELETE /customers/{id}/payment-methods/{id}` |
| `CheckoutAmount` | ✅ | Checkout Widget / hosted page (an amount) |
| `Webhooks` | ✅ | verified + handled — 8 of Revolut's 22 events mapped (see [Webhooks](#webhooks)) |
| `Invoices` | ⏸️ | Revolut has no invoice API; local rendering is **deferred** — engine + layout are an open question |
| `SubscriptionCancelNow` | ❌ | cancel stops future billing but keeps the paid cycle; no distinct immediate terminate |
| `SubscriptionPauseImmediate` | ❌ | no pause endpoint (`paused` is a state with no trigger) |
| `SubscriptionResume` | ❌ | no resume endpoint |
| `SubscriptionSwapImmediate` | ❌ | `change-plan` is `at_cycle_end` only |
| `SubscriptionQuantity` | ❌ | a subscription carries no quantity field |
| `SubscriptionQuantityUpdate` | ❌ | — |
| `SubscriptionMetadata` | ❌ | no metadata map; correlation is `external_reference` |
| `SubscriptionNoProration` | ❌ | a change lands at cycle end and never prorates (open support question) |
| `PaymentMethodsAdd` | ❌ | no API to add a method — only via the checkout widget |
| `CheckoutPrices` | ❌ | no checkout price catalogue |
| `Taxes` | ❌ | no tax API |
| `Discounts` | ❌ | no invoice discount |

✅ backed · ⏸️ deferred (tracked as its own issue) · ❌ not offered by the Revolut API — the
operation throws `UnsupportedOperationException` rather than being faked. Check
`Cashier::supports(Capability::…)` before calling.

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

The scheduled change is not lost: Revolut reports it back as `scheduled_action`,
and the driver records it as the subscription's **pending price** — the gateway's
own fact, not our memory of what was requested:

```php
$subscription = $user->subscription('default');

$subscription->hasPendingPriceChange();  // true
$subscription->pendingPrice();           // the plan variation it moves to
$subscription->pendingPriceStartsAt();   // the end of the current cycle
```

`SubscriptionPriceChangeScheduled` fires when the change is scheduled;
`SubscriptionUpdated` fires when it **lands**, on the paid renewal. A listener
that provisions entitlements must use the second one — the first describes a
state that is not true yet.

Every sync path writes the local item row, so `subscribedToPrice()` works for any
subscription the driver sees — including one it did not create.

**Revolut stores no metadata map on a subscription.** `POST /api/subscriptions`
accepts five fields and `metadata` is not one of them, so `withMetadata()` throws
`UnsupportedOperationException` rather than sending a field the API ignores — which
is what it used to do, silently dropping the app's correlation data. Revolut's whole
correlation surface is a single string, and the driver exposes it as such:

`externalReference()` is Revolut-specific, so it is not on the `SubscriptionBuilder`
contract and `$user->newSubscription()` (which returns the support package's guarded
builder) does not expose it. Reach the driver's builder through the provider:

```php
use Isapp\CashierSupport\Facades\Cashier;

Cashier::provider()
    ->newSubscription($user, 'default', $planVariationId)
    ->externalReference('order_7')
    ->create();

// And read it back:
Cashier::provider()->subscriptionExternalReference($user, 'default'); // 'order_7'
```

`external_reference` is writable on create, returned on read, and the only field a
subscription update accepts.

`$options` on `create()` is **not** an escape hatch for arbitrary fields: it accepts
only what the create body documents and the builder does not already set
(`setup_order_redirect_url`, `external_reference`, `trial_duration`). Anything else
throws — it would otherwise be merged over the typed body (overwriting `customer_id`
or `plan_variation_id`, so the Revolut subscription and the local row would describe
different things) or travel to the API to be ignored.

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

`SubscriptionPriceChangeScheduled` is dispatched on a successful swap — the change
is scheduled, not applied. `SubscriptionUpdated` follows later, when the paid
renewal lands it.

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

An order is a one-off payment: `POST /orders` carries no mode, and a Revolut
subscription is created through the subscriptions API. Any `CheckoutMode` other
than `Payment` therefore raises `UnsupportedOperationException` rather than
quietly becoming an order that never renews. Order `metadata` is validated
against Revolut's restrictions before the call (string values only, at most 50
pairs, values up to 500 characters, keys `^[a-zA-Z][a-zA-Z\d_]{0,39}$`) — a
violation is named as an `InvalidArgumentException` instead of coming back as an
opaque 400.

`checkout()` creates a Revolut order and returns a `RevolutCheckoutSession`
carrying the order `token` for the [Revolut Checkout Widget](https://developer.revolut.com/docs/sdks/merchant-web-sdk/initialize-widget/revolut-checkout)
and the hosted `url`. The token is also what `clientSecret()` returns — the
contract's provider-neutral name for it. The session is `Responsable`, so you
can `return` it from a controller to redirect to the hosted page.

## Idempotency — retries must not charge twice

The connector minted a random `Idempotency-Key` per request, which protects the
transport (its `->retry()` re-sends the *same* request, so a transient failure keeps
its key) and **nothing above it**. A queued job that retries after the API call
already succeeded — a mailer timeout, a deadlock in a listener — re-enters the method
with a brand-new key, and Revolut sees a brand-new operation: the customer is charged,
or refunded, twice.

Only the caller knows what the operation *is*, so the caller names it:

```php
$user->charge(1500, $paymentMethodId, ['idempotency_key' => "charge:{$job->uuid}"]);
$user->refund($orderId, ['idempotency_key' => "refund:{$refundRecord->id}"]);

Cashier::provider()->newSubscription($user, 'default', $planVariationId)
    ->create(null, ['idempotency_key' => "subscribe:{$job->uuid}"]);
```

**How each one is actually made safe** — Revolut accepts the `Idempotency-Key` header
on exactly three operations (the refund, the subscription create, and usage records),
and on nothing else:

| Operation | Mechanism |
|---|---|
| `refund()` | `Idempotency-Key` header — Revolut deduplicates it |
| `newSubscription()->create()` | `Idempotency-Key` header — Revolut deduplicates it |
| `charge()` | **Not** the header: `POST /orders` does not accept one. The order carries your key as its `merchant_order_data.reference`, and a retry looks the order up by that reference (`GET /orders?merchant_order_data_reference=`) instead of creating a second one. An order already paid is returned as-is; one left unpaid by a half-finished attempt is paid, not duplicated. |

**What is still not idempotent, and cannot be:** `createCustomer()` — `POST /customers`
accepts no key, and Revolut's customer list cannot be filtered by email, so a retry
that lost its response creates a second customer. The local `cashier_customers` row is
the only guard, and it is written after the call. `checkout()` likewise creates a fresh
order per call; no money moves for an abandoned one, but the spare order is real.

The default key stays random on purpose: a *deterministic* default would be worse than
none, because Revolut allows several partial refunds of one order and would silently
swallow the second legitimate one.

## Webhooks

Register a webhook with Revolut (prints the signing secret to store in
`REVOLUT_WEBHOOK_SECRET`):

```bash
php artisan cashier:webhook revolut
```

No URL argument: the command reads it from the route cashier-support mounts —
`webhook/cashier/revolut` by default, configurable there via
`cashier-support.webhook.prefix`. Passing one by hand is what let a registered webhook
drift from the route it was supposed to reach, which 404s on every delivery in silence.
Use `--url=` only for a proxy or tunnel that genuinely differs.

Incoming webhooks arrive at **cashier-support's** route and controller — this driver
ships neither. It supplies the delivery behind one contract method: verification
(HMAC-SHA256 over `v1.{timestamp}.{body}`) and what applying an event does. Support
owns the order the two happen in, which is the whole of #24.

They are dispatched as the support package's `WebhookReceived` / `WebhookHandled`
events, carrying `$event->provider` (`'revolut'`) and Revolut's **raw decoded body**.

For gateway activity in provider-neutral terms, listen to the **typed** events
instead — `PaymentSucceeded`, `SubscriptionCreated`, `SubscriptionCanceled`,
`SubscriptionRenewed`, `SubscriptionPastDue` and the rest. They carry the billable
and a real DTO, and they are what the driver dispatches once a webhook has been
applied.

`WebhookReceived` is the escape hatch, and it fires for **every** verified event —
including the 14 of Revolut's 22 documented types this driver does not map (every
`DISPUTE_*`, the `PAYOUT_*`, `ORDER_AUTHORISED`, the 3DS challenge, …). Nothing is
applied to local state for those and `WebhookHandled` does **not** fire, but your
listener sees them:

```php
Event::listen(function (WebhookReceived $event) {
    if (($event->payload['event'] ?? null) === 'DISPUTE_ACTION_REQUIRED') {
        // ... this one has a deadline attached.
    }
});
```

The payload is Revolut-shaped on purpose: an event nobody mapped has no
provider-neutral meaning to render, and inventing one would be a lie. Use the typed
events for meaning, this one for reach.

**Who announces what, and exactly once.**

`SubscriptionCanceled` is dispatched by `cancelSubscription()` itself. It used to be
dispatched nowhere: the method wrote `status = Canceled`, and the
`SUBSCRIPTION_CANCELLED` webhook that followed saw the status already Canceled and
short-circuited on its "announce only a real transition" guard. So the *common* case —
the customer cancelling in the app — ran no listener at all. A cancellation made in the
Revolut dashboard is still announced by the webhook, because the app has no other way
to learn of it.

Cancelling an already-cancelled subscription is a no-op that talks to nobody: Revolut
refuses to cancel one that is `cancelled` or `finished`, so a repeat click would
otherwise come back as an exception. And cancelling one that never paid its setup order
announces nothing — it was never announced as created, and a listener should not be
handed the end of a life it never saw begin.

`SubscriptionCreated` is dispatched when the subscription is actually **live**, which is
usually *not* at creation: Revolut creates a subscription `pending`, with a setup order
the customer still has to pay in the Checkout Widget. Announcing that would grant access
to a customer who may close the widget and never pay — and an abandoned setup produces no
webhook, so nothing would take it back. It is announced when `SUBSCRIPTION_INITIATED`
reports the setup payment has landed. A subscription that comes back live at creation (a
trial, say) has no such transition coming, so the builder announces it there instead —
either way, exactly one event.

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
