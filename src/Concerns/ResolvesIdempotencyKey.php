<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Concerns;

use InvalidArgumentException;

/**
 * The caller's idempotency key for an operation.
 *
 * Revolut deduplicates on this header, and it "can accept any unique string value
 * the merchant uses" — which means it identifies the OPERATION, and only the caller
 * knows what that is. A key minted inside the driver protects the transport and
 * nothing above it: a queued job that retries after the API call already succeeded
 * arrives with a fresh key, and Revolut charges or refunds the customer again.
 *
 * @see https://developer.revolut.com/docs/api/merchant/operations/refund-order.md
 */
trait ResolvesIdempotencyKey
{
    /**
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidArgumentException When it is present but unusable.
     */
    protected function idempotencyKey(array $options): ?string
    {
        $key = $options['idempotency_key'] ?? null;

        if ($key === null) {
            return null;
        }

        if (! is_string($key) || $key === '') {
            throw new InvalidArgumentException('An idempotency key must be a non-empty string.');
        }

        return $key;
    }
}
