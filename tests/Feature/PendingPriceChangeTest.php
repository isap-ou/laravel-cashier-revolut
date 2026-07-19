<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Isapp\CashierRevolut\Models\RevolutSubscription;
use Isapp\CashierRevolut\Tests\Fixtures\RevolutApi;
use Isapp\CashierRevolut\Tests\Fixtures\User;
use Isapp\CashierRevolut\Tests\TestCase;
use Isapp\CashierRevolut\Webhooks\RevolutWebhookSynchronizer;
use Isapp\CashierSupport\Enums\SwapTiming;
use Isapp\CashierSupport\Events\SubscriptionPriceChangeScheduled;
use Isapp\CashierSupport\Events\SubscriptionUpdated;
use Isapp\CashierSupport\Facades\Cashier;

/**
 * A swap Revolut has scheduled but not applied.
 *
 * The change lands at the end of the billing cycle, so the local item row keeps
 * naming the variation the customer is actually billed on. The requested one
 * used to be discarded outright — the operation's single most important output
 * lived nowhere, so a successful swap looked exactly like no swap.
 *
 * Revolut reports the scheduled change itself, as `scheduled_action`, so the
 * pending price is the gateway's fact, not our memory of what we asked for.
 */
class PendingPriceChangeTest extends TestCase
{
    private function fakeSubscription(?array $scheduledAction = null): void
    {
        Http::fake([
            '*/subscriptions/sub_1/change-plan' => Http::response(null, 204),
            '*/subscriptions/sub_1/cycles/cyc_1' => Http::response([
                'id' => 'cyc_1',
                'state' => 'active',
                'start_date' => '2099-07-01T00:00:00Z',
                'end_date' => '2099-08-01T00:00:00Z',
            ]),
            '*/subscriptions/sub_1' => Http::response([
                'id' => 'sub_1',
                'state' => 'active',
                'current_cycle_id' => 'cyc_1',
                'plan_variation_id' => 'plan_var_1',
                ...($scheduledAction !== null ? ['scheduled_action' => $scheduledAction] : []),
            ]),
        ]);
    }

    private function subscribedUser(): User
    {
        $user = User::asRevolutCustomer('cus_1');

        RevolutSubscription::query()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'type' => 'default',
            'provider' => 'revolut',
            'provider_id' => 'sub_1',
            'status' => 'active',
            'current_period_end' => '2099-08-01T00:00:00Z',
        ]);

        return $user;
    }

    public function test_a_scheduled_swap_records_the_plan_the_customer_will_move_to(): void
    {
        $this->fakeSubscription([
            'type' => 'change_plan_variation',
            'reason' => 'customer_request',
            'plan_variation_id' => 'plan_var_2',
        ]);

        $user = $this->subscribedUser();

        $subscription = Cashier::provider()->swapSubscription($user, 'default', 'plan_var_2', SwapTiming::AtPeriodEnd);

        // The DTO answers the question the app actually has.
        $this->assertSame('plan_var_2', $subscription->pendingPrice);
        $this->assertSame('2099-08-01', $subscription->pendingPriceStartsAt?->toDateString());

        $record = RevolutSubscription::query()->firstOrFail();

        $this->assertTrue($record->hasPendingPriceChange());
        $this->assertSame('plan_var_2', $record->pendingPrice());
        $this->assertSame('2099-08-01', $record->pendingPriceStartsAt()?->toDateString());

        // And the customer is still billed on the old variation until it lands.
        $this->assertTrue($user->subscribedToPrice('plan_var_1', 'default'));
        $this->assertFalse($user->subscribedToPrice('plan_var_2', 'default'));
    }

    public function test_the_pending_price_is_what_revolut_scheduled_not_what_we_asked_for(): void
    {
        // If the gateway scheduled something other than what was requested, the
        // record must show what the customer will actually be moved to.
        $this->fakeSubscription([
            'type' => 'change_plan_variation',
            'reason' => 'merchant_request',
            'plan_variation_id' => 'plan_var_9',
        ]);

        $user = $this->subscribedUser();
        $user->subscription('default')->swap('plan_var_2', SwapTiming::AtPeriodEnd);

        $this->assertSame('plan_var_9', RevolutSubscription::query()->firstOrFail()->pendingPrice());
    }

    public function test_scheduling_announces_a_scheduled_change_not_an_update(): void
    {
        // SubscriptionUpdated at scheduling time would tell a listener that
        // provisions entitlements to grant the new plan a cycle early — nothing
        // the customer is billed on has changed yet.
        Event::fake([SubscriptionPriceChangeScheduled::class, SubscriptionUpdated::class]);

        $this->fakeSubscription([
            'type' => 'change_plan_variation',
            'reason' => 'customer_request',
            'plan_variation_id' => 'plan_var_2',
        ]);

        $this->subscribedUser()->subscription('default')->swap('plan_var_2', SwapTiming::AtPeriodEnd);

        Event::assertDispatched(SubscriptionPriceChangeScheduled::class);
        Event::assertNotDispatched(SubscriptionUpdated::class);
    }

    public function test_a_landed_change_clears_the_pending_price(): void
    {
        // A change lands when Revolut starts reporting the new variation as
        // current and stops reporting a scheduled action. Keeping "you'll move to
        // Pro on 1 Aug" on the record after the customer has already moved is the
        // same class of lie as never recording it.
        RevolutApi::fake([
            '*/subscriptions/'.RevolutApi::SUBSCRIPTION_ID => Http::response(RevolutApi::subscription([
                'state' => 'active',
                'plan_variation_id' => 'plan_var_2',
            ])),
        ]);

        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

        RevolutSubscription::query()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'type' => 'default',
            'provider' => 'revolut',
            'provider_id' => RevolutApi::SUBSCRIPTION_ID,
            'status' => 'active',
            'next_price' => 'plan_var_2',
            'next_price_starts_at' => '2099-08-01T00:00:00Z',
        ]);

        $synchronizer = $this->app->make(RevolutWebhookSynchronizer::class);
        $payload = RevolutApi::webhookEvent('SUBSCRIPTION_INITIATED');

        $synchronizer->handle($payload);

        $this->assertFalse(RevolutSubscription::query()->firstOrFail()->hasPendingPriceChange());
    }

    public function test_a_swap_revolut_has_not_reported_yet_is_still_recorded(): void
    {
        // The 204 is the commit point. If the refetch comes back without a
        // scheduled_action (eventual consistency), that is "not reported yet",
        // never "nothing was scheduled" — recording nothing would make a
        // committed swap invisible all over again.
        $this->fakeSubscription(null);

        $user = $this->subscribedUser();
        $subscription = Cashier::provider()->swapSubscription($user, 'default', 'plan_var_2', SwapTiming::AtPeriodEnd);

        $this->assertSame('plan_var_2', $subscription->pendingPrice);
        $this->assertSame('plan_var_2', RevolutSubscription::query()->firstOrFail()->pendingPrice());
    }

    public function test_a_cycle_that_cannot_be_read_does_not_erase_the_date_we_have(): void
    {
        // The cycle endpoint is tolerated on purpose (a 404 must not fail a
        // committed swap), so its absence must not downgrade "you'll move to Pro
        // on 1 Aug" into "you'll move to Pro at some point".
        Http::fake([
            '*/subscriptions/sub_1/change-plan' => Http::response(null, 204),
            '*/subscriptions/sub_1/cycles/cyc_1' => Http::response(null, 500),
            '*/subscriptions/sub_1' => Http::response([
                'id' => 'sub_1',
                'state' => 'active',
                'current_cycle_id' => 'cyc_1',
                'plan_variation_id' => 'plan_var_1',
                'scheduled_action' => [
                    'type' => 'change_plan_variation',
                    'reason' => 'customer_request',
                    'plan_variation_id' => 'plan_var_2',
                ],
            ]),
        ]);

        $user = $this->subscribedUser();
        $user->subscription('default')->swap('plan_var_2', SwapTiming::AtPeriodEnd);

        $record = RevolutSubscription::query()->firstOrFail();

        $this->assertSame('plan_var_2', $record->pendingPrice());
        // Falls back to the period the record already holds.
        $this->assertSame('2099-08-01', $record->pendingPriceStartsAt()?->toDateString());
    }

    public function test_cancelling_withdraws_a_scheduled_change_that_can_never_land(): void
    {
        // A cancelled subscription does not renew, so a change scheduled for the
        // next cycle can never take effect. Still advertising it would promise a
        // plan the customer will never be moved to.
        Http::fake([
            '*/subscriptions/sub_1/cancel' => Http::response(null, 204),
            '*/subscriptions/sub_1/cycles/cyc_1' => Http::response([
                'id' => 'cyc_1',
                'state' => 'active',
                'start_date' => '2099-07-01T00:00:00Z',
                'end_date' => '2099-08-01T00:00:00Z',
            ]),
            '*/subscriptions/sub_1' => Http::response([
                'id' => 'sub_1',
                'state' => 'cancelled',
                'current_cycle_id' => 'cyc_1',
                'plan_variation_id' => 'plan_var_1',
            ]),
        ]);

        $user = $this->subscribedUser();

        RevolutSubscription::query()->firstOrFail()->forceFill([
            'next_price' => 'plan_var_2',
            'next_price_starts_at' => '2099-08-01T00:00:00Z',
        ])->save();

        $user->subscription('default')->cancel();

        $this->assertFalse(RevolutSubscription::query()->firstOrFail()->hasPendingPriceChange());
    }

    public function test_a_cancellation_scheduled_at_cycle_end_is_not_a_pending_price(): void
    {
        // scheduled_action also carries cancellations. Reading its plan variation
        // blindly would invent a price change out of a cancellation.
        $this->fakeSubscription([
            'type' => 'cancel',
            'reason' => 'customer_request',
        ]);

        $user = $this->subscribedUser();
        $user->subscription('default')->swap('plan_var_2', SwapTiming::AtPeriodEnd);

        $this->assertFalse(RevolutSubscription::query()->firstOrFail()->hasPendingPriceChange());
    }
}
