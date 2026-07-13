<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Concerns;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Isapp\CashierRevolut\Checkout\RevolutCheckoutSession;
use Isapp\CashierRevolut\Http\Requests\CreateOrderRequest;
use Isapp\CashierRevolut\Http\Responses\OrderResponse;
use Isapp\CashierSupport\Contracts\CheckoutSession;
use Isapp\CashierSupport\DTO\CheckoutRequest;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;

/**
 * Hosted checkout via a Revolut order + the Checkout Widget.
 *
 * Revolut checks out an amount, not a catalogue of price identifiers — which is
 * what Capability::CheckoutAmount says, so a price-shaped request is refused by
 * the support package before it ever reaches this trait. The amount is a typed
 * field of the request now, not an undocumented key in an options bag, and this
 * trait no longer throws an exception of its own.
 *
 * Revolut's order carries a single `redirect_url` and no cancel destination, so
 * the request's cancelUrl has no counterpart and is not sent; the mode travels
 * on the returned session, not on the order.
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
        $currency = $request->currency;

        return new CreateOrderRequest(
            // Guaranteed non-null and positive by the capability() check above.
            amount: (int) $request->amount,
            // A request built through Data::from() can carry an amount with no
            // currency; fall back to the configured one, as a charge does.
            currency: $currency !== null ? $currency->value : $this->currencyFromOptions($request->options),
            customerId: $this->customerIdOrNull($billable),
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
            if (preg_match('/^[a-zA-Z][a-zA-Z\d_]{0,39}$/', (string) $key) !== 1) {
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
