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
use Isapp\CashierSupport\Events\SubscriptionCanceled;
use Isapp\CashierSupport\Events\SubscriptionCreated;
use Isapp\CashierSupport\Events\SubscriptionUpdated;
use Isapp\CashierSupport\Facades\Cashier;

/**
 * Who announces a subscription's life, and exactly once.
 *
 * The gateway methods wrote the local status themselves and dispatched nothing,
 * while the webhook synchronizer dispatched only on a status TRANSITION. So an
 * app-initiated cancellation — the common one — wrote Canceled, and the later
 * SUBSCRIPTION_CANCELLED webhook found the status already Canceled and returned
 * early: `SubscriptionCanceled` never fired at all. Everything hung off it
 * (revoking entitlements, dunning, analytics) silently never ran.
 *
 * The reverse mistake is just as easy: announce from both places and a listener
 * provisions twice. The webhook path can only ever see subscriptions this app
 * created — syncSubscription() returns early when there is no local record — so
 * its SUBSCRIPTION_INITIATED is, by construction, never a first sighting.
 */
class SubscriptionEventsTest extends TestCase
{
    private function subscribed(): User
    {
        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

        RevolutSubscription::query()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'type' => 'default',
            'provider' => 'revolut',
            'provider_id' => RevolutApi::SUBSCRIPTION_ID,
            'status' => 'active',
        ]);

        return $user;
    }

    private function syncSubscriptionWebhook(string $event): void
    {
        $payload = RevolutApi::webhookEvent($event);

        $this->app->make(RevolutWebhookSynchronizer::class)->handle($payload);
    }

    public function test_a_subscription_awaiting_its_setup_payment_is_not_announced_yet(): void
    {
        // Revolut creates a subscription `pending`: the customer still has to pay
        // its setup order in the Checkout Widget. Announcing THAT would hand a
        // listener a subscription nobody has paid for — and if the customer closes
        // the widget, Revolut sends no webhook, so nothing would ever take the
        // access back.
        Event::fake([SubscriptionCreated::class]);
        RevolutApi::fake();

        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

        Cashier::provider()->newSubscription($user, 'default', '850e8400-e29b-41d4-a716-446655440003')->create();

        Event::assertNotDispatched(SubscriptionCreated::class);
    }

    public function test_a_subscription_that_is_live_at_creation_is_announced_there(): void
    {
        // No setup order to wait for (a trial, a plan that bills later), so no
        // status transition is coming — the synchronizer's transition guard would
        // swallow it, and it would be announced nowhere at all.
        Event::fake([SubscriptionCreated::class]);
        RevolutApi::fake([
            '*/subscriptions' => Http::response(RevolutApi::subscription(['state' => 'active'])),
        ]);

        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

        Cashier::provider()->newSubscription($user, 'default', '850e8400-e29b-41d4-a716-446655440003')->create();

        Event::assertDispatchedTimes(SubscriptionCreated::class, 1);
    }

    public function test_an_app_initiated_cancellation_announces_itself(): void
    {
        // The whole point: it used to write Canceled and say nothing, and the
        // webhook that followed found nothing to announce.
        Event::fake([SubscriptionCanceled::class]);
        RevolutApi::fake([
            '*/subscriptions/'.RevolutApi::SUBSCRIPTION_ID => Http::response(RevolutApi::subscription([
                'state' => 'cancelled',
            ])),
        ]);

        $user = $this->subscribed();

        $user->subscription('default')->cancel();

        Event::assertDispatchedTimes(SubscriptionCanceled::class, 1);
    }

    public function test_the_webhook_that_follows_an_app_initiated_cancellation_does_not_announce_it_twice(): void
    {
        RevolutApi::fake([
            '*/subscriptions/'.RevolutApi::SUBSCRIPTION_ID => Http::response(RevolutApi::subscription([
                'state' => 'cancelled',
            ])),
        ]);

        $user = $this->subscribed();
        $user->subscription('default')->cancel();

        Event::fake([SubscriptionCanceled::class]);

        $this->syncSubscriptionWebhook('SUBSCRIPTION_CANCELLED');

        Event::assertNotDispatched(SubscriptionCanceled::class);
    }

    public function test_a_cancellation_made_in_the_revolut_dashboard_still_announces_itself(): void
    {
        // The app never asked for it, so the webhook is the only way it can learn.
        Event::fake([SubscriptionCanceled::class]);
        RevolutApi::fake([
            '*/subscriptions/'.RevolutApi::SUBSCRIPTION_ID => Http::response(RevolutApi::subscription([
                'state' => 'cancelled',
            ])),
        ]);

        $this->subscribed();

        $this->syncSubscriptionWebhook('SUBSCRIPTION_CANCELLED');

        Event::assertDispatched(SubscriptionCanceled::class);
    }

    public function test_the_setup_payment_landing_is_what_announces_the_subscription(): void
    {
        // pending → active means the customer paid the setup order. That is the
        // birth worth announcing, and it is announced exactly once.
        Event::fake([SubscriptionCreated::class, SubscriptionUpdated::class]);
        RevolutApi::fake([
            '*/subscriptions/'.RevolutApi::SUBSCRIPTION_ID => Http::response(RevolutApi::subscription([
                'state' => 'active',
            ])),
        ]);

        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

        RevolutSubscription::query()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'type' => 'default',
            'provider' => 'revolut',
            'provider_id' => RevolutApi::SUBSCRIPTION_ID,
            'status' => 'incomplete',
        ]);

        $this->syncSubscriptionWebhook('SUBSCRIPTION_INITIATED');

        Event::assertDispatchedTimes(SubscriptionCreated::class, 1);
        // Not SubscriptionUpdated: that event means a plan change landed, and
        // overloading it is what earlier work went out of its way to undo.
        Event::assertNotDispatched(SubscriptionUpdated::class);
    }

    public function test_cancelling_an_already_cancelled_subscription_is_a_no_op(): void
    {
        // Revolut refuses to cancel a subscription that is already `cancelled` or
        // `finished` (cancel-subscription.md), so without a local short-circuit a
        // repeat click would come back as a 4xx — an exception in the customer's
        // face for asking twice for something already done. And it announces
        // nothing: the cancellation has already been announced.
        RevolutApi::fake([
            '*/subscriptions/'.RevolutApi::SUBSCRIPTION_ID => Http::response(RevolutApi::subscription([
                'state' => 'cancelled',
            ])),
        ]);

        $user = $this->subscribed();
        $user->subscription('default')->cancel();

        Event::fake([SubscriptionCanceled::class]);
        Http::fake();

        $user->subscription('default')->cancel();

        Event::assertNotDispatched(SubscriptionCanceled::class);
        // And it does not even ask: the API would refuse.
        Http::assertNothingSent();
    }

    public function test_cancelling_a_subscription_that_was_never_announced_announces_nothing(): void
    {
        // It never paid its setup order, so SubscriptionCreated deliberately never
        // fired for it. Announcing its cancellation would hand a listener the end of
        // a life it never saw begin — a "your subscription is cancelled" email for a
        // subscription nobody ever paid for.
        Event::fake([SubscriptionCanceled::class]);
        RevolutApi::fake([
            '*/subscriptions/'.RevolutApi::SUBSCRIPTION_ID => Http::response(RevolutApi::subscription([
                'state' => 'cancelled',
            ])),
        ]);

        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

        RevolutSubscription::query()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'type' => 'default',
            'provider' => 'revolut',
            'provider_id' => RevolutApi::SUBSCRIPTION_ID,
            'status' => 'incomplete',
        ]);

        $user->subscription('default')->cancel();

        Event::assertNotDispatched(SubscriptionCanceled::class);
    }

    public function test_a_subscription_created_paused_or_overdue_is_not_announced_as_new(): void
    {
        // "Not pending" is not "live": overdue, paused and cancelled all pass that
        // test, and none of them is a subscription to announce as freshly created.
        Event::fake([SubscriptionCreated::class]);
        RevolutApi::fake([
            '*/subscriptions' => Http::response(RevolutApi::subscription(['state' => 'overdue'])),
        ]);

        $user = User::asRevolutCustomer(RevolutApi::CUSTOMER_ID);

        Cashier::provider()->newSubscription($user, 'default', '850e8400-e29b-41d4-a716-446655440003')->create();

        Event::assertNotDispatched(SubscriptionCreated::class);
    }
}
