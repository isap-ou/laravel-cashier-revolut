<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Feature;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Isapp\CashierRevolut\Builders\RevolutSubscriptionBuilder;
use Isapp\CashierRevolut\Models\RevolutSubscription;
use Isapp\CashierRevolut\RevolutGateway;
use Isapp\CashierRevolut\Tests\Fixtures\User;
use Isapp\CashierRevolut\Tests\TestCase;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Facades\Cashier;

/**
 * A subscription's correlation data — and where Revolut actually keeps it.
 *
 * `POST /api/subscriptions` accepts five fields, and `metadata` is not one of
 * them; no subscription endpoint returns it either. The driver sent it anyway, so
 * an app that used withMetadata() to tie a Revolut subscription to its own records
 * had that data quietly thrown away by the API.
 *
 * What Revolut offers instead is a single `external_reference` string. That is not
 * a metadata map, and pretending it is one — by, say, accepting a single-entry
 * array and rejecting a two-entry one — would make the same call work or fail
 * depending on how much the caller happened to put in it.
 */
class SubscriptionMetadataTest extends TestCase
{
    private function fakeRevolut(): void
    {
        Http::fake([
            '*/subscriptions' => Http::response([
                'id' => 'sub_1',
                'state' => 'active',
                'plan_variation_id' => 'plan_var_1',
                'external_reference' => 'order_7',
            ]),
        ]);
    }

    public function test_revolut_does_not_declare_a_subscription_metadata_capability(): void
    {
        $this->assertFalse(Cashier::supports(Capability::SubscriptionMetadata));
    }

    public function test_metadata_is_refused_rather_than_silently_dropped(): void
    {
        $this->fakeRevolut();

        $this->expectException(UnsupportedOperationException::class);
        User::asRevolutCustomer('cus_1')
            ->newSubscription('default', 'plan_var_1')
            ->withMetadata(['order_id' => '7']);
    }

    public function test_the_options_bag_cannot_smuggle_it_back_in(): void
    {
        // create() merges $options into the request body, so without this guard
        // the throw above is decoration — exactly the back door the quantity fix
        // had to close.
        $this->fakeRevolut();

        $this->expectException(UnsupportedOperationException::class);
        User::asRevolutCustomer('cus_1')
            ->newSubscription('default', 'plan_var_1')
            ->create(null, ['metadata' => ['order_id' => '7']]);
    }

    public function test_a_direct_builder_call_is_refused_too(): void
    {
        // The support gate is bypassed when the driver's builder is used directly.
        $this->fakeRevolut();

        $builder = Cashier::provider()->newSubscription(
            User::asRevolutCustomer('cus_1'),
            'default',
            'plan_var_1',
        );

        $this->assertInstanceOf(RevolutSubscriptionBuilder::class, $builder);

        $this->expectException(UnsupportedOperationException::class);
        $builder->withMetadata(['order_id' => '7']);
    }

    public function test_external_reference_is_the_correlation_field_revolut_actually_has(): void
    {
        $this->fakeRevolut();

        $builder = Cashier::provider()->newSubscription(
            User::asRevolutCustomer('cus_1'),
            'default',
            'plan_var_1',
        );

        $this->assertInstanceOf(RevolutSubscriptionBuilder::class, $builder);

        $builder->externalReference('order_7')->create();

        Http::assertSent(function ($request): bool {
            // Not "true for everything that is not the create call" — assertSent
            // passes on ANY matching request, so a permissive early return makes
            // the assertion pass on the customer lookup and prove nothing.
            if (! str_ends_with($request->url(), '/subscriptions')) {
                return false;
            }

            $body = $request->data();

            return ($body['external_reference'] ?? null) === 'order_7'
                && ! array_key_exists('metadata', $body);
        });
    }

    public function test_the_external_reference_can_be_read_back(): void
    {
        // Write-only would just move the silent drop to the read side.
        Http::fake([
            '*/subscriptions/sub_1' => Http::response([
                'id' => 'sub_1',
                'state' => 'active',
                'external_reference' => 'order_7',
            ]),
        ]);

        $user = User::asRevolutCustomer('cus_1');

        RevolutSubscription::query()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'type' => 'default',
            'provider' => 'revolut',
            'provider_id' => 'sub_1',
            'status' => 'active',
        ]);

        $gateway = Cashier::provider();
        $this->assertInstanceOf(RevolutGateway::class, $gateway);

        $this->assertSame('order_7', $gateway->subscriptionExternalReference($user));
    }

    public function test_options_cannot_overwrite_the_body_the_builder_owns(): void
    {
        // $options is merged OVER the typed body, so an unchecked key could create
        // the subscription against a different customer or plan than the local row
        // records — and any undocumented key would travel to the API to be ignored,
        // which is the same silent drop under a different name.
        $this->fakeRevolut();

        $this->expectException(InvalidArgumentException::class);
        User::asRevolutCustomer('cus_1')
            ->newSubscription('default', 'plan_var_1')
            ->create(null, ['customer_id' => 'cus_someone_else']);
    }
}
