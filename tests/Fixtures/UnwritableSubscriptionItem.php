<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Fixtures;

use Isapp\CashierRevolut\Models\RevolutSubscriptionItem;

/**
 * A subscription-item model pointed at a table that does not exist.
 *
 * How the persistence-failure tests make the local write fail *for real* — a genuine
 * QueryException off a genuine query — without touching the schema. The first version of
 * those tests called Schema::drop() instead, which passes locally and then breaks Testbench's
 * migration rollback in teardown: a `down()` that ALTERs the table it just dropped explodes,
 * and the test that "passed" takes the whole suite with it on any support revision whose
 * down() is not idempotent.
 *
 * Mocking the model would have proved only that a mock was called. This proves the real path.
 */
class UnwritableSubscriptionItem extends RevolutSubscriptionItem
{
    protected $table = 'no_such_subscription_items_table';
}
