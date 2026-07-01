<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Requests;

use Spatie\LaravelData\Data;

/**
 * Base for Revolut request bodies. Concrete requests declare their fields as
 * spatie/laravel-data properties (with SnakeCaseMapper output mapping); this
 * base serialises them to a request array with null values omitted.
 */
abstract class RevolutRequest extends Data
{
    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return array_filter($this->toArray(), static fn (mixed $value): bool => $value !== null);
    }
}
