<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\Billable;

/**
 * @property string|null $revolut_customer_id
 */
class User extends Model
{
    use Billable;

    protected $guarded = [];
}
