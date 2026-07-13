<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Concerns;

use Illuminate\Database\Eloquent\Model;
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
            metadata: $request->metadata === [] ? null : $request->metadata,
        );
    }
}
