<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Concerns;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierRevolut\Http\Requests\CreateOrderRequest;
use Isapp\CashierRevolut\Http\Requests\OrderCustomerRequest;
use Isapp\CashierRevolut\Http\Requests\PayOrderRequest;
use Isapp\CashierRevolut\Http\Requests\RefundOrderRequest;
use Isapp\CashierRevolut\Http\Responses\OrderResponse;
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

        return $this->guardConnection(function () use ($amount, $paymentMethod, $options, $customerId): Payment {
            $order = OrderResponse::from($this->revolut()->post('/orders', (new CreateOrderRequest(
                amount: $amount,
                currency: $this->currencyFromOptions($options),
                customer: new OrderCustomerRequest($customerId),
            ))->payload())->json() ?? []);

            $type = is_string($options['payment_method_type'] ?? null) ? $options['payment_method_type'] : null;

            $this->revolut()->post(
                "/orders/{$order->id}/payments",
                (new PayOrderRequest($paymentMethod, $type))->payload(),
            );

            $result = OrderResponse::from($this->revolut()->get("/orders/{$order->id}")->json() ?? [])->toPayment();

            if ($result->status === PaymentStatus::Failed) {
                throw PaymentFailedException::forPayment($result->id);
            }

            return $result;
        });
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

        $refund = $this->guardConnection(function () use ($paymentId, $amount, $currency): Refund {
            $response = RefundResponse::from($this->revolut()->post(
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
