<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Concerns;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Isapp\CashierRevolut\Checkout\RevolutCheckoutSession;
use Isapp\CashierRevolut\Http\Requests\CreateOrderRequest;
use Isapp\CashierRevolut\Http\Requests\OrderCustomerRequest;
use Isapp\CashierRevolut\Http\Responses\OrderResponse;
use Isapp\CashierSupport\Contracts\CheckoutSession;
use Isapp\CashierSupport\DTO\CheckoutRequest;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\CheckoutMode;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;

/**
 * Hosted checkout via a Revolut order + the Checkout Widget.
 *
 * Revolut checks out an amount, not a catalogue of price identifiers — which is
 * what Capability::CheckoutAmount says, so a price-shaped request is refused by
 * the support package before it ever reaches this trait. The amount is a typed
 * field of the request now, not an undocumented key in an options bag.
 *
 * Revolut's order carries a single `redirect_url` and no cancel destination, so
 * the request's cancelUrl has no counterpart and is not sent. An order is always
 * a one-off payment, so any mode other than CheckoutMode::Payment is refused
 * rather than quietly downgraded.
 *
 * It does throw for a request Revolut itself would reject — a metadata pair that
 * breaks the API's restrictions — but as an InvalidArgumentException naming the
 * violation, the way the reference Cashier packages report a malformed argument.
 */
trait HandlesRevolutCheckout
{
    /**
     * {@inheritDoc}
     */
    public function checkout(Model $billable, CheckoutRequest $request): CheckoutSession
    {
        // The support gate already refuses a price-shaped request for this
        // driver. This second check is what keeps a direct provider call
        // (bypassing Billable) from POSTing an order with no amount — and
        // capability() is also where a request built through Data::from(),
        // which skips the named constructors, gets validated.
        if ($request->capability() !== Capability::CheckoutAmount) {
            throw UnsupportedOperationException::forCapability(Capability::CheckoutPrices);
        }

        // An order is a one-off payment: POST /orders has no mode of any kind,
        // and a Revolut subscription is created through the subscriptions API,
        // not by checking out in a different mode. Downgrading the mode silently
        // would hand the app a session that says Subscription over an order that
        // will never renew.
        if ($request->mode !== CheckoutMode::Payment) {
            throw new UnsupportedOperationException(
                "Revolut's checkout creates a payment order; the [{$request->mode->value}] mode is not supported.",
            );
        }

        $order = $this->guardConnection(
            fn (): OrderResponse => OrderResponse::from(
                $this->revolut()->post('/orders', $this->orderFor($billable, $request)->payload())->json() ?? [],
            ),
        );

        return new RevolutCheckoutSession(
            id: $order->id,
            token: $order->token,
            mode: $request->mode,
            url: $order->checkoutUrl,
        );
    }

    private function orderFor(Model $billable, CheckoutRequest $request): CreateOrderRequest
    {
        $customerId = $this->customerIdOrNull($billable);
        $currency = $request->currency;

        if ($currency === null) {
            throw new InvalidArgumentException(
                'A checkout request carrying an amount must carry the currency it is in.',
            );
        }

        return new CreateOrderRequest(
            // Amount is guaranteed non-null and positive by capability().
            amount: (int) $request->amount,
            currency: $currency->value,
            customer: $customerId !== null ? new OrderCustomerRequest($customerId) : null,
            redirectUrl: $request->successUrl,
            description: $request->description,
            metadata: $this->orderMetadata($request->metadata),
        );
    }

    /**
     * Metadata Revolut will actually accept, or a clear failure before the call.
     *
     * The API constrains it far more tightly than a PHP array: string keys and
     * string values only, no nulls, at most 50 pairs, values up to 500 chars, and
     * keys matching ^[a-zA-Z][a-zA-Z\d_]{0,39}$. Forwarding e.g. an int user id
     * would come back as an opaque 400 wrapped in a generic API failure, so the
     * violation is named here, before any HTTP.
     *
     * @see https://developer.revolut.com/docs/api/merchant/operations/create-order.md
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, string>|null
     *
     * @throws InvalidArgumentException When a pair breaks a documented restriction.
     */
    private function orderMetadata(array $metadata): ?array
    {
        if ($metadata === []) {
            return null;
        }

        if (count($metadata) > 50) {
            throw new InvalidArgumentException(
                'Revolut accepts at most 50 metadata pairs on an order; got ['.count($metadata).'].',
            );
        }

        $accepted = [];

        foreach ($metadata as $key => $value) {
            // The D modifier matters: without it `$` also matches before a
            // trailing newline, so "user_id\n" would sail through and 400.
            if (preg_match('/^[a-zA-Z][a-zA-Z\d_]{0,39}$/D', (string) $key) !== 1) {
                throw new InvalidArgumentException(
                    "Revolut metadata key [{$key}] must start with a letter and contain only letters, digits and underscores, up to 40 characters.",
                );
            }

            if (! is_string($value)) {
                throw new InvalidArgumentException(
                    "Revolut metadata value for [{$key}] must be a string; got [".get_debug_type($value).'].',
                );
            }

            if (mb_strlen($value) > 500) {
                throw new InvalidArgumentException(
                    "Revolut metadata value for [{$key}] must be at most 500 characters.",
                );
            }

            $accepted[(string) $key] = $value;
        }

        return $accepted;
    }
}
