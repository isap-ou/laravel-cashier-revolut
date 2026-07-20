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
        ?Throwable $previous = null,
    ) {
        // Carried through rather than dropped: for a wrapped local failure the cause IS the
        // diagnosis, and a message alone leaves whoever debugs it without the stack that
        // names the actual query.
        parent::__construct($message, previous: $previous);
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
     * The subscription exists at Revolut and we failed to record it.
     *
     * The one failure in this driver that cannot be undone by retrying or by waiting: the
     * gateway has already accepted `POST /subscriptions`, so the customer is on a billing
     * schedule, and Revolut offers no undo. Nor does the webhook layer repair it — every
     * later `SUBSCRIPTION_*` finds no local record, `pipeline()` returns false, the controller
     * answers 200, and Revolut never redelivers.
     *
     * So the only useful thing left is to tell the caller precisely what is orphaned, in an
     * exception they already catch. A bare QueryException saying "database error" reads like
     * nothing happened, which is the opposite of the truth.
     */
    public static function subscriptionCreatedButNotRecorded(string $subscriptionId, Throwable $previous): self
    {
        return new self(
            "Revolut subscription [{$subscriptionId}] was created and is billing the customer, "
            .'but it could not be recorded locally: '.$previous->getMessage()
            .' Nothing will repair this on its own — cancel it in the Revolut dashboard, or '
            .'reconcile the record by hand.',
            previous: $previous,
        );
    }

    /**
     * A currency that is not a known ISO-4217 code (validated by support's CurrencyCast against
     * moneyphp's ISOCurrencies). Money records must never fall back to a default currency.
     */
    public static function unsupportedCurrency(string $currency): self
    {
        return new self("Unsupported currency [{$currency}] — not a known ISO 4217 currency code.");
    }
}
