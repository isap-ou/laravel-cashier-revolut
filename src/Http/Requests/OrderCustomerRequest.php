<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Requests;

/**
 * The customer an order belongs to.
 *
 * An order links to a customer through a nested `customer` object, not a flat
 * `customer_id` — that field does not exist on POST /api/orders and is ignored,
 * which leaves the order (and the card used to pay it) attached to nobody.
 *
 * @see https://developer.revolut.com/docs/api/merchant/operations/create-order.md
 */
class OrderCustomerRequest extends RevolutRequest
{
    public function __construct(
        public string $id,
    ) {}
}
