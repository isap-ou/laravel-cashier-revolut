<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Enums\Concerns;

use IsapOu\EnumHelpers\Concerns\HasLabel;

/**
 * Wires isap-ou/laravel-enum-helpers label translation to this package's own
 * translation namespace, independent of the host application's enum-helpers
 * configuration.
 *
 * Resolves labels from the key: cashier-revolut::enums.{ShortClassName}.{CaseName}
 */
trait HasRevolutLabel
{
    use HasLabel;

    protected function getPrefix(): ?string
    {
        return 'enums';
    }

    protected function getNamespace(): ?string
    {
        return 'cashier-revolut';
    }
}
