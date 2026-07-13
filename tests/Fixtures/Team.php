<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierRevolut\Models\RevolutCustomer;
use Isapp\CashierRevolut\RevolutGateway;
use Isapp\CashierSupport\Billable;

/**
 * A second billable type.
 *
 * Order webhooks used to resolve their owner through a single configured
 * billable class, so a Team could never be billed alongside a User.
 */
class Team extends Model
{
    use Billable;

    protected $guarded = [];

    public static function asRevolutCustomer(string $customerId): self
    {
        /** @var self $team */
        $team = static::query()->create(['name' => 'Acme']);

        RevolutCustomer::query()->create([
            'owner_type' => $team->getMorphClass(),
            'owner_id' => $team->getKey(),
            'provider' => RevolutGateway::DRIVER,
            'provider_id' => $customerId,
        ]);

        return $team;
    }
}
