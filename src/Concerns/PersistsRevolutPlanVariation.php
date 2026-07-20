<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Concerns;

use Illuminate\Support\Facades\DB;
use Isapp\CashierRevolut\RevolutGateway;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Models\Subscription;
use Isapp\CashierSupport\Models\SubscriptionItem;

/**
 * Mirrors the plan variation a Revolut subscription is billed on into the
 * local cashier_subscription_items row.
 *
 * A Revolut subscription always runs on exactly one plan variation, so it maps
 * to exactly one item row. The stored price is never inferred locally — it is
 * whatever Revolut reports as the subscription's plan_variation_id. That keeps
 * the record honest across a scheduled plan change: the change only takes
 * effect at the end of the current cycle, and until Revolut reports the new
 * variation, the local row still names the one the customer is paying for.
 *
 * Quantity is stored as null, because Revolut has no per-subscription quantity
 * to report: it lives on the plan variation's items, fixed when the plan is
 * created. Null means "not applicable", which is the truth — and now a
 * writable one, so every path may create the row. It previously could not:
 * with a NOT NULL column the only options were to invent a 1 (billing a
 * five-seat plan as one seat) or to write nothing, which left
 * subscribedToPrice() false forever for any subscription the builder had not
 * created.
 *
 * @internal Composed into RevolutGateway, which is what Cashier::driver('revolut') returns — an app reaches this behaviour through the gateway, never by naming the trait. Not public surface: outside the backward-compatibility promise in README.
 */
trait PersistsRevolutPlanVariation
{
    /**
     * Mirror the plan variation onto the subscription's single local item,
     * creating the row when it does not exist yet.
     */
    protected function persistPlanVariation(Subscription $record, ?string $planVariationId): void
    {
        if ($planVariationId === null || $planVariationId === '') {
            return;
        }

        DB::transaction(function () use ($record, $planVariationId): void {
            // Serialize writers on the parent row before the read-modify-write
            // below. A webhook delivery can race a swap (or the initial
            // create), and cashier_subscription_items carries no unique index
            // on subscription_id — it stays multi-item for other drivers — so
            // two writers that both miss the row would both insert one.
            //
            // support's unique(subscription_id, price) does not remove the need
            // for this lock, and is not what it protects against: it would turn
            // the racing insert of the SAME variation into a constraint
            // violation rather than a duplicate, but a race between two
            // DIFFERENT variations — the swap case — writes two distinct pairs,
            // which the constraint permits and only the lock orders.
            $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->first();

            $model = Cashier::subscriptionItemModel(RevolutGateway::DRIVER);

            $item = $this->planItem($record) ?? new $model;

            $item->forceFill([
                'subscription_id' => $record->getKey(),
                'provider' => RevolutGateway::DRIVER,
                'price' => $planVariationId,
                // Not a default: Revolut has no per-subscription quantity to
                // report, and "unknown" is the truth. Writing 1 here would bill
                // a five-seat plan as one seat.
                'quantity' => null,
            ])->save();
        });
    }

    /**
     * The plan variation the local record currently names, if any.
     */
    protected function currentPlanVariation(Subscription $record): ?string
    {
        return $this->planItem($record)?->price;
    }

    private function planItem(Subscription $record): ?SubscriptionItem
    {
        $model = Cashier::subscriptionItemModel(RevolutGateway::DRIVER);

        /** @var SubscriptionItem|null */
        return $model::query()
            ->where('subscription_id', $record->getKey())
            ->oldest()
            ->first();
    }
}
