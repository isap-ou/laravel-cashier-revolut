<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Concerns;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierRevolut\Http\Responses\PaymentMethodResponse;
use Isapp\CashierSupport\DTO\PaymentMethod;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;

/**
 * Saved payment method operations. Revolut cannot create a payment method
 * server-side (only via the checkout widget with a save flag), so
 * addPaymentMethod throws.
 */
trait ManagesRevolutPaymentMethods
{
    /**
     * {@inheritDoc}
     */
    public function paymentMethods(Model $billable): array
    {
        $id = $this->revolutCustomerId($billable);
        $response = $this->guardConnection(fn () => $this->revolut()->get("/customers/{$id}/payment-methods"));

        $rows = $response->json('payment_methods') ?? $response->json();

        $methods = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $methods[] = PaymentMethodResponse::from($row)->toPaymentMethod();
            }
        }

        return $methods;
    }

    /**
     * {@inheritDoc}
     */
    public function defaultPaymentMethod(Model $billable): ?PaymentMethod
    {
        return $this->paymentMethods($billable)[0] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function addPaymentMethod(Model $billable, string $paymentMethod): PaymentMethod
    {
        throw UnsupportedOperationException::forCapability(Capability::PaymentMethodsAdd);
    }

    /**
     * {@inheritDoc}
     */
    public function deletePaymentMethod(Model $billable, string $paymentMethodId): void
    {
        $id = $this->revolutCustomerId($billable);

        $this->guardConnection(fn () => $this->revolut()->delete("/customers/{$id}/payment-methods/{$paymentMethodId}"));
    }
}
