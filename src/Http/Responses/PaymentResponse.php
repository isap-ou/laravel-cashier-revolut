<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Responses;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * A Revolut payment resource (POST /orders/{id}/payments, GET /payments/{id}).
 *
 * Only the fields the driver reasons about are mapped. `state` is the PAYMENT lifecycle, which
 * is distinct from the order state: a payment routed to 3DS/SCA reports `authentication_challenge`
 * while its order is still merely `pending`/`processing`, so this state — not the order's — is the
 * only place a requires-action charge is visible.
 *
 * @see https://developer.revolut.com/docs/api/merchant/operations/pay-order
 *
 * @internal The shape of a Revolut response, which is Revolut's to change and not ours to freeze — a new API version may add, rename or drop fields within a minor release. Reached only through RevolutGateway. Not public surface: outside the backward-compatibility promise in README.
 */
#[MapInputName(SnakeCaseMapper::class)]
class PaymentResponse extends Data
{
    public function __construct(
        public ?string $id = null,
        public ?string $state = null,
    ) {}

    /**
     * Whether the payment is waiting on a customer 3DS/SCA challenge before it can complete.
     *
     * Maps to PaymentStatus::RequiresAction — the charge is returned as incomplete DATA carrying
     * the order token, and support's Concerns\PerformsCharges turns it into a catchable
     * IncompletePaymentException.
     */
    public function requiresAction(): bool
    {
        return $this->state === 'authentication_challenge';
    }
}
