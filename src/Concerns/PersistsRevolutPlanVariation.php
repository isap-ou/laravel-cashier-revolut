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
 * Only the price is mirrored. The Revolut subscription resource carries no
 * quantity at all, so only the builder — which is handed one by the caller —
 * may create the row. Every other path updates an existing row and never
 * inserts: inventing a quantity of 1 for a subscription that was created with
 * five seats would be worse than having no row.
 */
trait PersistsRevolutPlanVariation
{
    /**
     * Update the plan variation on the subscription's single local item.
     *
     * No-op when the subscription has no item row — see the class docblock.
     */
    protected function persistPlanVariation(Subscription $record, ?string $planVariationId): void
    {
        $this->writePlanVariation($record, $planVariationId, null, createIfMissing: false);
    }

    /**
     * Create (or update) the item row for a subscription being set up, where
     * the caller-supplied quantity is known.
     */
    protected function createPlanVariation(Subscription $record, ?string $planVariationId, int $quantity): void
    {
        $this->writePlanVariation($record, $planVariationId, $quantity, createIfMissing: true);
    }

    /**
     * The plan variation the local record currently names, if any.
     */
    protected function currentPlanVariation(Subscription $record): ?string
    {
        return $this->planItem($record)?->price;
    }

    private function writePlanVariation(Subscription $record, ?string $planVariationId, ?int $quantity, bool $createIfMissing): void
    {
        if ($planVariationId === null || $planVariationId === '') {
            return;
        }

        DB::transaction(function () use ($record, $planVariationId, $quantity, $createIfMissing): void {
            // Serialize writers on the parent row before the read-modify-write
            // below. A webhook delivery can race a swap (or the initial
            // create), and cashier_subscription_items carries no unique index
            // on subscription_id — it stays multi-item for other drivers — so
            // two writers that both miss the row would both insert one.
            $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->first();

            $item = $this->planItem($record);

            if ($item === null) {
                if (! $createIfMissing) {
                    return;
                }

                $model = Cashier::subscriptionItemModel(RevolutGateway::DRIVER);
                $item = new $model;
            }

            $item->forceFill([
                'subscription_id' => $record->getKey(),
                'provider' => RevolutGateway::DRIVER,
                'price' => $planVariationId,
            ]);

            if ($quantity !== null) {
                $item->forceFill(['quantity' => $quantity]);
            }

            $item->save();
        });
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
