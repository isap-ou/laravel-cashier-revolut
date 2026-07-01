<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Exceptions;

use Isapp\CashierSupport\Exceptions\CashierException;

/**
 * Thrown when Revolut sends a webhook event this driver does not subscribe to
 * or cannot translate. The webhook controller acknowledges such events without
 * dispatching anything, so they are never misclassified.
 */
class UnexpectedWebhookEventException extends CashierException
{
    public static function forEvent(string $event): self
    {
        return new self("Unexpected Revolut webhook event [{$event}].");
    }
}
