<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Exceptions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Isapp\CashierSupport\Exceptions\CashierException;
use Throwable;

/**
 * Thrown when the Revolut Merchant API returns an error response.
 */
class RevolutApiException extends CashierException
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly array $body = [],
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response): self
    {
        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        $message = is_string($body['message'] ?? null)
            ? $body['message']
            : "Revolut API request failed with status {$response->status()}.";

        return new self($message, $response->status(), $body);
    }

    /**
     * Wrap a transport-level failure (DNS, TLS, timeout after retries).
     */
    public static function connectionFailed(ConnectionException $exception): self
    {
        return new self('Could not reach the Revolut API: '.$exception->getMessage());
    }

    /**
     * Wrap a 2xx response whose body does not match the expected schema.
     */
    public static function unexpectedPayload(Throwable $exception): self
    {
        return new self('Unexpected Revolut API response payload: '.$exception->getMessage());
    }

    /**
     * A currency this package's Currency enum does not cover. Money records
     * must never fall back to a default currency.
     */
    public static function unsupportedCurrency(string $currency): self
    {
        return new self("Unsupported currency [{$currency}] — extend Isapp\\CashierSupport\\Enums\\Currency.");
    }
}
