<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierRevolut\Models\RevolutCustomer;
use Isapp\CashierRevolut\RevolutGateway;
use Isapp\CashierSupport\Billable;

class User extends Model
{
    use Billable;

    protected $guarded = [];

    /**
     * Make this user a Revolut customer.
     *
     * The identity lives in cashier_customers, not in a column here — so a test
     * records it the same way the driver does.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function asRevolutCustomer(string $customerId, array $attributes = []): self
    {
        /** @var self $user */
        $user = static::query()->create($attributes + ['name' => 'Ada', 'email' => 'ada@example.com']);

        RevolutCustomer::query()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'provider' => RevolutGateway::DRIVER,
            'provider_id' => $customerId,
        ]);

        return $user;
    }
}
