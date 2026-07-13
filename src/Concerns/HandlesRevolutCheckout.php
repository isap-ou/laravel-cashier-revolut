<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Concerns;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Isapp\CashierRevolut\Checkout\RevolutCheckoutSession;
use Isapp\CashierRevolut\Http\Requests\CreateOrderRequest;
use Isapp\CashierRevolut\Http\Responses\OrderResponse;
use Isapp\CashierSupport\Contracts\CheckoutSession;
use Isapp\CashierSupport\Enums\CheckoutMode;

/**
 * Hosted checkout via a Revolut order + the Checkout Widget.
 *
 * Revolut has no price-identifier catalog for checkout, so $items is unused;
 * the order amount MUST be supplied via options['amount'] (minor units). The
 * returned session carries the order token for the widget and the hosted
 * checkout URL.
 */
trait HandlesRevolutCheckout
{
    /**
     * {@inheritDoc}
     *
     * @throws InvalidArgumentException When options['amount'] is missing or not positive.
     */
    public function checkout(Model $billable, array|string $items, array $options = []): CheckoutSession
    {
        $amount = (int) ($options['amount'] ?? 0);

        if ($amount <= 0) {
            throw new InvalidArgumentException(
                'Revolut checkout requires a positive options["amount"] in minor units; price identifiers are not supported.',
            );
        }

        $redirect = $options['redirect_url'] ?? $options['success_url'] ?? null;

        $request = new CreateOrderRequest(
            amount: $amount,
            currency: $this->currencyFromOptions($options),
            customerId: $this->customerIdOrNull($billable),
            redirectUrl: is_string($redirect) ? $redirect : null,
        );

        $order = $this->guardConnection(
            fn (): OrderResponse => OrderResponse::from($this->revolut()->post('/orders', $request->payload())->json() ?? []),
        );

        return new RevolutCheckoutSession(
            id: $order->id,
            token: $order->token,
            mode: $this->checkoutMode($options),
            url: $order->checkoutUrl,
        );
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function checkoutMode(array $options): CheckoutMode
    {
        $mode = $options['mode'] ?? null;

        if ($mode instanceof CheckoutMode) {
            return $mode;
        }

        return CheckoutMode::tryFrom(is_string($mode) ? $mode : 'payment') ?? CheckoutMode::Payment;
    }
}
