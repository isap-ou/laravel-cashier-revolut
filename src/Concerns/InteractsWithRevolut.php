<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Isapp\CashierRevolut\Exceptions\RevolutApiException;
use Isapp\CashierRevolut\Http\RevolutConnector;
use Isapp\CashierSupport\Casts\CurrencyCast;
use Isapp\CashierSupport\Exceptions\CustomerNotFoundException;
use Spatie\LaravelData\Exceptions\CannotCastDate;
use Spatie\LaravelData\Exceptions\CannotCreateData;
use TypeError;

/**
 * Shared helpers for the Revolut gateway concerns.
 *
 * Composed into RevolutGateway, which receives the connector via its
 * constructor.
 *
 * @internal Composed into RevolutGateway, which is what Cashier::driver('revolut') returns — an app reaches this behaviour through the gateway, never by naming the trait. Not public surface: outside the backward-compatibility promise in README.
 */
trait InteractsWithRevolut
{
    use ResolvesIdempotencyKey;

    protected RevolutConnector $connector;

    /**
     * A preconfigured Revolut Merchant API request.
     *
     * The same connector backs the application-facing Http::revolut() macro.
     */
    protected function revolut(?string $idempotencyKey = null): PendingRequest
    {
        return $this->connector->request($idempotencyKey);
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
        } catch (CannotCreateData|CannotCastDate|TypeError $exception) {
            throw RevolutApiException::unexpectedPayload($exception);
        }
    }

    /**
     * The Revolut customer identifier for the billable.
     *
     * Stored in cashier_customers, not on the billable's own table: a
     * driver-named column would need a second one for every further gateway, and
     * could never be reverse-looked-up across billable types.
     *
     * @throws CustomerNotFoundException When the model is not a Revolut customer yet.
     */
    protected function revolutCustomerId(Model $billable): string
    {
        return $this->customerIdFor($billable);
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
     * Resolve and validate the ISO-4217 currency code from options or config.
     *
     * Validated at the boundary the same way the response side is (Casts\CurrencyCast): a bad
     * caller-supplied code is a programmer error, so it raises SPL InvalidArgumentException here
     * rather than being sent and bounced back as an opaque Revolut 4xx. CurrencyCast::fromCode
     * also normalises case, so the returned code is upper-cased.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws \InvalidArgumentException When the code is not a known ISO-4217 currency.
     */
    protected function currencyFromOptions(array $options): string
    {
        $currency = $options['currency'] ?? config('cashier-support.currency', 'EUR');

        return CurrencyCast::fromCode(is_string($currency) ? $currency : 'EUR')->getCode();
    }
}
