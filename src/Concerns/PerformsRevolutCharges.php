<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Concerns;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierRevolut\Enums\RevolutOrderState;
use Isapp\CashierRevolut\Http\Requests\CreateOrderRequest;
use Isapp\CashierRevolut\Http\Requests\MerchantOrderDataRequest;
use Isapp\CashierRevolut\Http\Requests\OrderCustomerRequest;
use Isapp\CashierRevolut\Http\Requests\PayOrderRequest;
use Isapp\CashierRevolut\Http\Requests\RefundOrderRequest;
use Isapp\CashierRevolut\Http\Responses\OrderResponse;
use Isapp\CashierRevolut\Http\Responses\PaymentResponse;
use Isapp\CashierRevolut\Http\Responses\RefundResponse;
use Isapp\CashierSupport\DTO\Payment;
use Isapp\CashierSupport\DTO\Refund;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Events\RefundProcessed;
use Isapp\CashierSupport\Exceptions\PaymentFailedException;

/**
 * One-off charges and refunds via the Revolut Orders API.
 *
 * A charge creates an order and pays it with a saved payment method
 * (merchant-initiated). Refunds are issued against the order.
 *
 * @internal Composed into RevolutGateway, which is what Cashier::driver('revolut') returns — an app reaches this behaviour through the gateway, never by naming the trait. Not public surface: outside the backward-compatibility promise in README.
 */
trait PerformsRevolutCharges
{
    /**
     * {@inheritDoc}
     */
    public function charge(Model $billable, int $amount, string $paymentMethod, array $options = []): Payment
    {
        // Paying with a saved method requires the order to belong to the
        // customer — fail fast instead of surfacing a downstream API error.
        $customerId = $this->revolutCustomerId($billable);
        $key = $this->idempotencyKey($options);

        return $this->guardConnection(function () use ($amount, $paymentMethod, $options, $customerId, $key): Payment {
            // POST /orders does not accept an Idempotency-Key — the header is
            // documented on the refund and the subscription create, and nowhere
            // else — so Revolut will not deduplicate a charge for us. It will help
            // us deduplicate it ourselves: the order carries the caller's operation
            // key as its own reference, and an order already carrying that reference
            // is THIS operation, already done.
            $existing = $key === null ? null : $this->orderForReference($key);

            if ($existing !== null && $existing->orderState() !== RevolutOrderState::Pending) {
                // Already paid on an earlier attempt. Paying it again is the double
                // charge this whole method exists to prevent.
                return $this->settledPayment($existing->id);
            }

            $order = $existing ?? OrderResponse::from($this->revolut()->post('/orders', (new CreateOrderRequest(
                amount: $amount,
                currency: $this->currencyFromOptions($options),
                customer: new OrderCustomerRequest($customerId),
                merchantOrderData: $key === null ? null : new MerchantOrderDataRequest($key),
            ))->payload())->json() ?? []);

            $type = is_string($options['payment_method_type'] ?? null) ? $options['payment_method_type'] : null;

            $payment = PaymentResponse::from($this->revolut()->post(
                "/orders/{$order->id}/payments",
                (new PayOrderRequest($paymentMethod, $type))->payload(),
            )->json() ?? []);

            if ($payment->requiresAction()) {
                // 3DS/SCA: the customer must finish the challenge in the Checkout Widget with the
                // order token before this settles. Returned as INCOMPLETE data — a Payment with
                // RequiresAction status carrying that token as the client secret — never a throw:
                // support's Concerns\PerformsCharges turns a requires-action Payment into a
                // catchable IncompletePaymentException (ChargeOperations::charge docblock).
                return new Payment(
                    id: $order->id,
                    amount: $order->amount,
                    currency: $order->currencyEnum(),
                    status: PaymentStatus::RequiresAction,
                    clientSecret: $order->token,
                    createdAt: $order->createdAt,
                );
            }

            return $this->settledPayment($order->id);
        });
    }

    /**
     * The order this operation already created, if it did.
     *
     * Revolut lists orders by the reference we set on them, so a retry of the same
     * operation finds its own order instead of creating a second one.
     */
    private function orderForReference(string $reference): ?OrderResponse
    {
        $orders = $this->revolut()
            ->get('/orders', ['merchant_order_data_reference' => $reference, 'limit' => 1])
            ->json();

        $first = is_array($orders) ? ($orders['orders'][0] ?? $orders[0] ?? null) : null;

        return is_array($first) ? OrderResponse::from($first) : null;
    }

    /**
     * The order's payment, refetched, with a declined one raised as a failure.
     */
    private function settledPayment(string $orderId): Payment
    {
        $payment = OrderResponse::from($this->revolut()->get("/orders/{$orderId}")->json() ?? [])->toPayment();

        if ($payment->status === PaymentStatus::Failed) {
            throw PaymentFailedException::forPayment($payment->id);
        }

        return $payment;
    }

    /**
     * {@inheritDoc}
     *
     * Currency is only sent alongside an explicit partial-refund amount (or
     * when the caller provides it); a full refund omits both and lets Revolut
     * refund the original order amount in its own currency.
     *
     * Dispatches RefundProcessed once Revolut has accepted the refund, and
     * throws PaymentFailedException when it rejects it — the refund is an order
     * in its own right, so a 2xx can still come back failed or cancelled
     * (mirrors the state guard in charge()).
     *
     * "Accepted" is as far as this can go: the response is 201 "Refund order
     * successfully created", and Revolut's webhook catalogue carries no refund
     * event (Order, Payment, Subscription, Payout and Dispute only). Final
     * settlement is therefore not observable, and neither is a refund issued
     * from the Revolut dashboard.
     *
     * @throws PaymentFailedException When Revolut rejects the refund.
     * @throws \InvalidArgumentException When a supplied currency code is not a known ISO-4217 code.
     *
     * @see https://developer.revolut.com/docs/api/merchant/operations/refund-order
     * @see https://developer.revolut.com/docs/api/merchant/operations/create-webhook
     */
    public function refund(Model $billable, string $paymentId, array $options = []): Refund
    {
        $amount = is_int($options['amount'] ?? null) ? $options['amount'] : null;
        $currency = $amount !== null || isset($options['currency'])
            ? $this->currencyFromOptions($options)
            : null;

        $key = $this->idempotencyKey($options);

        $refund = $this->guardConnection(function () use ($paymentId, $amount, $currency, $key): Refund {
            $response = RefundResponse::from($this->revolut($key)->post(
                "/orders/{$paymentId}/refund",
                (new RefundOrderRequest($currency, $amount))->payload(),
            )->json() ?? []);

            if ($response->failed()) {
                throw PaymentFailedException::forPayment($response->id, 'The refund failed.');
            }

            return $response->toRefund($paymentId);
        });

        event(new RefundProcessed($billable, $refund));

        return $refund;
    }
}
