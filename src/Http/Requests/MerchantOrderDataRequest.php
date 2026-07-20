<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Requests;

/**
 * Your own reference on a Revolut order.
 *
 * This is what makes a charge retry-safe. `POST /api/orders` does not accept an
 * Idempotency-Key at all — the header is documented on the refund and the
 * subscription create, and nowhere else — so an order cannot be deduplicated by
 * Revolut. It can be deduplicated by us: the order carries the caller's operation
 * key as its reference, and `GET /api/orders?merchant_order_data_reference=` finds
 * it again before a retry creates a second one.
 *
 * @see https://developer.revolut.com/docs/api/merchant/operations/create-order.md
 * @see https://developer.revolut.com/docs/api/merchant/operations/retrieve-order-list.md
 *
 * @internal The shape of a Revolut request body, which is Revolut's to change and not ours to freeze. Reached only through RevolutGateway. Not public surface: outside the backward-compatibility promise in README.
 */
class MerchantOrderDataRequest extends RevolutRequest
{
    public function __construct(
        public string $reference,
    ) {}
}
