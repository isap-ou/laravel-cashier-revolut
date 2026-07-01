<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Isapp\CashierRevolut\Exceptions\RevolutApiException;
use Isapp\CashierRevolut\Http\RevolutConnector;
use Isapp\CashierSupport\Exceptions\CustomerNotFoundException;
use Spatie\LaravelData\Exceptions\CannotCastDate;
use Spatie\LaravelData\Exceptions\CannotCreateData;

/**
 * Shared helpers for the Revolut gateway concerns.
 *
 * Composed into RevolutGateway, which receives the connector via its
 * constructor.
 */
trait InteractsWithRevolut
{
    protected RevolutConnector $connector;

    /**
     * A preconfigured Revolut Merchant API request.
     *
     * The same connector backs the application-facing Http::revolut() macro.
     */
    protected function revolut(): PendingRequest
    {
        return $this->connector->request();
    }

    /**
     * Run an API interaction, wrapping transport failures and malformed 2xx
     * payloads into the support exception hierarchy (retry(throw: false)
     * still rethrows exhausted connection errors as ConnectionException; a
     * schema mismatch surfaces as a spatie/laravel-data exception).
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    protected function guardConnection(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ConnectionException $exception) {
            throw RevolutApiException::connectionFailed($exception);
        } catch (CannotCreateData|CannotCastDate $exception) {
            throw RevolutApiException::unexpectedPayload($exception);
        }
    }

    /**
     * The Revolut customer identifier stored on the billable model.
     *
     * @throws CustomerNotFoundException When the model is not a Revolut customer yet.
     */
    protected function revolutCustomerId(Model $billable): string
    {
        $id = $billable->getAttribute('revolut_customer_id');

        if (! is_string($id) || $id === '') {
            throw CustomerNotFoundException::notCreated();
        }

        return $id;
    }

    /**
     * Read a string attribute from the billable model, or null.
     */
    protected function stringAttribute(Model $billable, string $key): ?string
    {
        $value = $billable->getAttribute($key);

        return is_string($value) ? $value : null;
    }

    /**
     * Persist the Revolut customer id on the billable model.
     */
    protected function persistCustomerId(Model $billable, string $customerId): void
    {
        $billable->forceFill(['revolut_customer_id' => $customerId])->save();
    }

    /**
     * Resolve the upper-case ISO currency code from options or config.
     *
     * @param  array<string, mixed>  $options
     */
    protected function currencyFromOptions(array $options): string
    {
        $currency = $options['currency'] ?? config('cashier-support.currency', 'EUR');

        return strtoupper(is_string($currency) ? $currency : 'EUR');
    }
}
