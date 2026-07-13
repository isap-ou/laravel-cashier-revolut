<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Concerns;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierRevolut\Http\Requests\CreateCustomerRequest;
use Isapp\CashierRevolut\Http\Responses\CustomerResponse;
use Isapp\CashierSupport\DTO\Customer;

/**
 * Customer operations against the Revolut Merchant API (POST/GET /api/customers).
 */
trait ManagesRevolutCustomer
{
    /**
     * {@inheritDoc}
     */
    public function createCustomer(Model $billable, array $options = []): Customer
    {
        $request = new CreateCustomerRequest(
            fullName: $this->optionOrAttribute($options, 'name', $billable, 'name'),
            email: $this->optionOrAttribute($options, 'email', $billable, 'email'),
            phone: is_string($options['phone'] ?? null) ? $options['phone'] : null,
        );

        $customer = $this->guardConnection(fn (): Customer => CustomerResponse::from(
            $this->revolut()->post('/customers', $request->payload())->json() ?? [],
        )->toCustomer());

        $this->persistCustomerId($billable, $customer->id, $customer->name, $customer->email);

        return $customer;
    }

    /**
     * {@inheritDoc}
     */
    public function asCustomer(Model $billable): Customer
    {
        $id = $this->revolutCustomerId($billable);

        return $this->guardConnection(
            fn (): Customer => CustomerResponse::from($this->revolut()->get("/customers/{$id}")->json() ?? [])->toCustomer(),
        );
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function optionOrAttribute(array $options, string $optionKey, Model $billable, string $attribute): ?string
    {
        $value = $options[$optionKey] ?? null;

        return is_string($value) ? $value : $this->stringAttribute($billable, $attribute);
    }
}
