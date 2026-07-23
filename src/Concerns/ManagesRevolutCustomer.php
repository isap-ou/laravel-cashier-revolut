<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Concerns;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierRevolut\Http\Requests\CreateCustomerRequest;
use Isapp\CashierRevolut\Http\Requests\UpdateCustomerRequest;
use Isapp\CashierRevolut\Http\Responses\CustomerResponse;
use Isapp\CashierSupport\DTO\Customer;
use Isapp\CashierSupport\DTO\CustomerDetails;

/**
 * Customer operations against the Revolut Merchant API (POST/GET/PATCH /api/customers).
 *
 * @internal Composed into RevolutGateway, which is what Cashier::driver('revolut') returns — an app reaches this behaviour through the gateway, never by naming the trait. Not public surface: outside the backward-compatibility promise in README.
 */
trait ManagesRevolutCustomer
{
    /**
     * {@inheritDoc}
     *
     * $details arrives already resolved by Concerns\ManagesCustomer (an explicit option first,
     * then the model's cashierName()/cashierEmail() hooks) — the driver never reaches into the
     * app's model to guess an attribute. Revolut requires an email on create; a null one is left
     * for Revolut to reject as a catchable CashierException via guardConnection().
     */
    public function createCustomer(Model $billable, CustomerDetails $details): Customer
    {
        $request = new CreateCustomerRequest(
            fullName: $details->name,
            email: $details->email,
            phone: $this->phoneOption($details),
            dateOfBirth: $this->dateOfBirthOption($details),
        );

        $customer = $this->guardConnection(fn (): Customer => CustomerResponse::from(
            $this->revolut()->post('/customers', $request->payload())->json() ?? [],
        )->toCustomer());

        $this->persistCustomerId($billable, $customer->id, $customer->name, $customer->email);

        return $customer;
    }

    /**
     * {@inheritDoc}
     *
     * PATCH /api/customers/{id} with only the fields the caller named: RevolutRequest::payload()
     * omits nulls, so an unmentioned field is left untouched at the gateway — the contract's
     * "null means leave it alone". phone and date_of_birth ride in $details->options, the fields
     * that are Revolut's and not one of support's two typed ones.
     */
    public function updateCustomer(Model $billable, CustomerDetails $details): Customer
    {
        $id = $this->revolutCustomerId($billable);

        $customer = $this->guardConnection(fn (): Customer => CustomerResponse::from(
            $this->revolut()->patch("/customers/{$id}", (new UpdateCustomerRequest(
                fullName: $details->name,
                email: $details->email,
                phone: $this->phoneOption($details),
                dateOfBirth: $this->dateOfBirthOption($details),
            ))->payload())->json() ?? [],
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
     * Revolut's phone field rides in the CustomerDetails options escape hatch, not one of
     * support's two typed fields (name, email).
     */
    private function phoneOption(CustomerDetails $details): ?string
    {
        return is_string($details->options['phone'] ?? null) ? $details->options['phone'] : null;
    }

    /**
     * Revolut's date_of_birth (a documented optional customer field, sent as a plain YYYY-MM-DD
     * string) rides in the same options escape hatch as phone — it is not one of support's two
     * typed fields, and like phone it is request-side only: the neutral Customer DTO carries no
     * slot for it, so it is written but does not round-trip back.
     */
    private function dateOfBirthOption(CustomerDetails $details): ?string
    {
        return is_string($details->options['date_of_birth'] ?? null) ? $details->options['date_of_birth'] : null;
    }
}
