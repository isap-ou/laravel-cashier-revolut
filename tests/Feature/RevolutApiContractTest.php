<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Isapp\CashierRevolut\Enums\RevolutPaymentMethodType;
use Isapp\CashierRevolut\Exceptions\RevolutApiException;
use Isapp\CashierRevolut\Http\Responses\OrderResponse;
use Isapp\CashierRevolut\Http\Responses\SubscriptionResponse;
use Isapp\CashierRevolut\Tests\Fixtures\RevolutApi;
use Isapp\CashierRevolut\Tests\Fixtures\User;
use Isapp\CashierRevolut\Tests\TestCase;
use Isapp\CashierRevolut\Webhooks\RevolutWebhookHandler;
use Isapp\CashierSupport\Contracts\GatewayProvider;
use Isapp\CashierSupport\Enums\Currency;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Enums\WebhookEvent;
use Isapp\CashierSupport\Exceptions\PaymentFailedException;
use Isapp\CashierSupport\Facades\Cashier;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Contract tests: the real gateway consuming response payloads copied from
 * the official Revolut OpenAPI specification (see RevolutApi fixture).
 */
class RevolutApiContractTest extends TestCase
{
    private const WEBHOOK_SECRET = 'wsk_test_secret';

    private function gateway(): GatewayProvider
    {
        return Cashier::provider();
    }

    private function customer(): User
    {
        return User::asRevolutCustomer(RevolutApi::CUSTOMER_ID, [
            'name' => 'Example Customer',
            'email' => 'example.customer@example.com',
        ]);
    }

    public function test_a_completed_documented_order_maps_to_a_succeeded_payment(): void
    {
        RevolutApi::fake([
            '*/orders/'.RevolutApi::ORDER_ID => Http::response(RevolutApi::order([
                'state' => 'completed',
                'created_at' => '2023-09-29T14:58:36Z',
                'updated_at' => '2023-09-29T14:58:36Z',
                'outstanding_amount' => 0,
            ])),
        ]);

        $payment = $this->gateway()->charge($this->customer(), 500, RevolutApi::PAYMENT_METHOD_ID, ['currency' => 'GBP']);

        $this->assertSame(RevolutApi::ORDER_ID, $payment->id);
        $this->assertSame(500, $payment->amount);
        $this->assertSame(Currency::GBP, $payment->currency);
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame('2023-09-29T14:58:36+00:00', $payment->createdAt?->toIso8601String());
    }

    /**
     * @return array<string, array{string, PaymentStatus}>
     */
    public static function orderStateProvider(): array
    {
        return [
            'pending' => ['pending', PaymentStatus::Pending],
            'processing' => ['processing', PaymentStatus::Processing],
            'authorised' => ['authorised', PaymentStatus::Processing],
            'completed' => ['completed', PaymentStatus::Succeeded],
            'cancelled' => ['cancelled', PaymentStatus::Canceled],
        ];
    }

    #[DataProvider('orderStateProvider')]
    public function test_every_documented_order_state_maps_to_a_payment_status(string $state, PaymentStatus $expected): void
    {
        RevolutApi::fake([
            '*/orders/'.RevolutApi::ORDER_ID => Http::response(RevolutApi::order([
                'state' => $state,
                'created_at' => '2023-09-29T14:58:36Z',
                'updated_at' => '2023-09-29T14:58:36Z',
            ])),
        ]);

        $payment = $this->gateway()->charge($this->customer(), 500, RevolutApi::PAYMENT_METHOD_ID, ['currency' => 'GBP']);

        $this->assertSame($expected, $payment->status);
    }

    public function test_a_failed_documented_order_raises_a_payment_failure(): void
    {
        RevolutApi::fake([
            '*/orders/'.RevolutApi::ORDER_ID => Http::response(RevolutApi::order([
                'state' => 'failed',
                'created_at' => '2023-09-29T14:58:36Z',
                'updated_at' => '2023-09-29T14:58:36Z',
            ])),
        ]);

        $this->expectException(PaymentFailedException::class);

        $this->gateway()->charge($this->customer(), 500, RevolutApi::PAYMENT_METHOD_ID, ['currency' => 'GBP']);
    }

    public function test_the_documented_customer_payload_maps_to_a_customer_dto(): void
    {
        RevolutApi::fake();
        $user = User::query()->create(['name' => 'Example Customer', 'email' => 'example.customer@example.com']);

        $customer = $this->gateway()->createCustomer($user);

        $this->assertSame(RevolutApi::CUSTOMER_ID, $customer->id);
        $this->assertSame('Example Customer', $customer->name);
        $this->assertSame('example.customer@example.com', $customer->email);
        $this->assertSame(RevolutApi::CUSTOMER_ID, $user->customerId());
    }

    /**
     * @return array<string, array{string, SubscriptionStatus}>
     */
    public static function subscriptionStateProvider(): array
    {
        return [
            'pending' => ['pending', SubscriptionStatus::Incomplete],
            'active' => ['active', SubscriptionStatus::Active],
            'overdue' => ['overdue', SubscriptionStatus::PastDue],
            'paused' => ['paused', SubscriptionStatus::Paused],
            'cancelled' => ['cancelled', SubscriptionStatus::Canceled],
            'finished' => ['finished', SubscriptionStatus::Canceled],
        ];
    }

    #[DataProvider('subscriptionStateProvider')]
    public function test_every_documented_subscription_state_maps_to_a_subscription_status(string $state, SubscriptionStatus $expected): void
    {
        RevolutApi::fake([
            '*/subscriptions' => Http::response(RevolutApi::subscription([
                'state' => $state,
                'created_at' => '2025-06-05T21:00:00Z',
                'updated_at' => '2025-06-05T21:00:00Z',
            ]), 201),
        ]);

        $subscription = $this->gateway()
            ->newSubscription($this->customer(), 'default', '850e8400-e29b-41d4-a716-446655440003')
            ->create(RevolutApi::PAYMENT_METHOD_ID);

        $this->assertSame(RevolutApi::SUBSCRIPTION_ID, $subscription->id);
        $this->assertSame($expected, $subscription->status);
    }

    public function test_the_documented_trial_end_date_maps_onto_the_subscription_trial(): void
    {
        RevolutApi::fake([
            '*/subscriptions' => Http::response(RevolutApi::subscription([
                'state' => 'active',
                'start_date' => '2025-06-05T21:00:00Z',
                'trial_duration' => 'P14D',
                'trial_end_date' => '2025-06-19T21:00:00Z',
                'created_at' => '2025-06-05T21:00:00Z',
                'updated_at' => '2025-06-05T21:00:00Z',
            ]), 201),
        ]);

        $subscription = $this->gateway()
            ->newSubscription($this->customer(), 'default', '850e8400-e29b-41d4-a716-446655440003')
            ->trialDays(14)
            ->create(RevolutApi::PAYMENT_METHOD_ID);

        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame('2025-06-19T21:00:00+00:00', $subscription->trialEndsAt?->toIso8601String());
    }

    public function test_cancelling_a_subscription_consumes_the_documented_204_and_refetches_state(): void
    {
        // POST /api/subscriptions/{id}/cancel returns 204 No Content in API
        // version 2025-12-04; the driver must not hydrate from that body and
        // instead refetches the subscription for its actual state.
        RevolutApi::fake([
            '*/subscriptions/'.RevolutApi::SUBSCRIPTION_ID.'/cancel' => Http::response(null, 204),
            '*/subscriptions/'.RevolutApi::SUBSCRIPTION_ID => Http::response(RevolutApi::subscription([
                'state' => 'cancelled',
                'created_at' => '2025-06-05T21:00:00Z',
                'updated_at' => '2025-06-05T21:00:00Z',
            ])),
        ]);

        $user = $this->customer();
        $this->gateway()->newSubscription($user, 'default', '850e8400-e29b-41d4-a716-446655440003')->create();

        $subscription = $this->gateway()->cancelSubscription($user, 'default');

        $this->assertSame(RevolutApi::SUBSCRIPTION_ID, $subscription->id);
        $this->assertSame(SubscriptionStatus::Canceled, $subscription->status);
    }

    public function test_changing_a_plan_sends_the_documented_body_and_consumes_the_204(): void
    {
        // POST /api/subscriptions/{id}/change-plan returns 204 No Content, so
        // the driver refetches for state. `scheduled` is a required enum whose
        // only accepted value is at_cycle_end, and `reason` is an enum, not
        // free text.
        // https://developer.revolut.com/docs/api/merchant/operations/change-subscription-plan
        RevolutApi::fake([
            '*/subscriptions/'.RevolutApi::SUBSCRIPTION_ID.'/change-plan' => Http::response(null, 204),
            '*/subscriptions/'.RevolutApi::SUBSCRIPTION_ID => Http::response(RevolutApi::subscription([
                'state' => 'active',
            ])),
        ]);

        $user = $this->customer();
        $this->gateway()->newSubscription($user, 'default', '850e8400-e29b-41d4-a716-446655440003')->create();

        $subscription = $this->gateway()->swapSubscription($user, 'default', '950e8400-e29b-41d4-a716-446655440004', [
            'plan_variation_phase_id' => 'a60e8400-e29b-41d4-a716-446655440006',
            'reason' => 'merchant_request',
        ]);

        $this->assertSame(RevolutApi::SUBSCRIPTION_ID, $subscription->id);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/change-plan')) {
                return false;
            }

            return $request->data() === [
                'plan_variation_id' => '950e8400-e29b-41d4-a716-446655440004',
                'plan_variation_phase_id' => 'a60e8400-e29b-41d4-a716-446655440006',
                'scheduled' => 'at_cycle_end',
                'reason' => 'merchant_request',
            ];
        });
    }

    public function test_the_documented_payment_methods_payload_maps_to_payment_method_dtos(): void
    {
        RevolutApi::fake();

        $methods = $this->gateway()->paymentMethods($this->customer());

        $this->assertCount(2, $methods);

        $this->assertSame('648334a8-9546-a983-a81a-efc6d5bdd0be', $methods[0]->id);
        $this->assertSame(RevolutPaymentMethodType::RevolutPay, $methods[0]->type);

        $this->assertSame(RevolutApi::PAYMENT_METHOD_ID, $methods[1]->id);
        $this->assertSame(RevolutPaymentMethodType::Card, $methods[1]->type);
        $this->assertSame('visa', $methods[1]->brand);
        $this->assertSame('6896', $methods[1]->last4);
    }

    public function test_the_driver_requests_the_modern_payment_methods_endpoint(): void
    {
        // The modern 2025-12-04 endpoint lives at /api/customers/{id}/payment-methods
        // (top-level brand/last_four); the legacy /api/1.0 variant documents a
        // different shape that PaymentMethodResponse does not map.
        RevolutApi::fake();

        $this->gateway()->paymentMethods($this->customer());

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/api/customers/'.RevolutApi::CUSTOMER_ID.'/payment-methods')
                && ! str_contains($request->url(), '/1.0/');
        });
    }

    public function test_the_documented_refund_payload_maps_to_a_refund_dto(): void
    {
        RevolutApi::fake([
            '*/orders/*/refund' => Http::response(RevolutApi::refund([
                'created_at' => '2025-06-18T16:30:30Z',
                'updated_at' => '2025-06-18T16:30:31Z',
            ]), 201),
        ]);

        $originalOrderId = '6852e963-d6a9-a5a4-9609-50b3addc5425';

        $refund = $this->gateway()->refund($this->customer(), $originalOrderId, ['amount' => 100, 'currency' => 'GBP']);

        $this->assertSame(RevolutApi::REFUND_ID, $refund->id);
        $this->assertSame($originalOrderId, $refund->paymentId);
        $this->assertSame(100, $refund->amount);
        $this->assertSame(Currency::GBP, $refund->currency);
        $this->assertSame('2025-06-18T16:30:30+00:00', $refund->createdAt?->toIso8601String());
    }

    public function test_the_documented_checkout_fields_map_to_a_checkout_session(): void
    {
        RevolutApi::fake();

        $session = $this->gateway()->checkout($this->customer(), '850e8400-e29b-41d4-a716-446655440003', [
            'amount' => 500,
            'currency' => 'GBP',
        ]);

        $this->assertSame(RevolutApi::ORDER_ID, $session->id());
        $this->assertSame(RevolutApi::ORDER_TOKEN, $session->token());
        $this->assertSame('https://checkout.revolut.com/payment-link/'.RevolutApi::ORDER_TOKEN, $session->url());
    }

    public function test_the_documented_error_body_maps_to_a_revolut_api_exception(): void
    {
        $message = 'Order with id abfa5bbd-a20c-4a0e-be66-30e733454518 was not found';

        Http::fake([
            '*/customers/'.RevolutApi::CUSTOMER_ID => Http::response(RevolutApi::error(404, $message), 404),
        ]);

        try {
            $this->gateway()->asCustomer($this->customer());
            $this->fail('Expected RevolutApiException.');
        } catch (RevolutApiException $exception) {
            $this->assertSame(404, $exception->statusCode);
            $this->assertSame($message, $exception->getMessage());
            $this->assertSame('not_found', $exception->body['code']);
        }
    }

    public function test_the_documented_microsecond_timestamps_are_cast_by_the_response_dtos(): void
    {
        // Documented example timestamps carry microsecond precision
        // (e.g. "2023-09-29T14:58:36.079398Z" in Res-Order-Min); the response
        // DTOs declare explicit date formats so verbatim payloads hydrate.
        $order = OrderResponse::from(RevolutApi::order());

        $this->assertSame('2023-09-29T14:58:36+00:00', $order->createdAt?->toIso8601String());
        $this->assertSame(79398, $order->createdAt->micro);

        $subscription = SubscriptionResponse::from(RevolutApi::subscription());

        $this->assertNotNull($subscription->createdAt);
    }

    /**
     * @return array<string, array{string, WebhookEvent, string}>
     */
    public static function webhookEventProvider(): array
    {
        return [
            'ORDER_COMPLETED' => ['ORDER_COMPLETED', WebhookEvent::PaymentSucceeded, RevolutApi::ORDER_ID],
            'ORDER_PAYMENT_FAILED' => ['ORDER_PAYMENT_FAILED', WebhookEvent::PaymentFailed, RevolutApi::ORDER_ID],
            'ORDER_PAYMENT_DECLINED' => ['ORDER_PAYMENT_DECLINED', WebhookEvent::PaymentFailed, RevolutApi::ORDER_ID],
            'SUBSCRIPTION_INITIATED' => ['SUBSCRIPTION_INITIATED', WebhookEvent::SubscriptionCreated, RevolutApi::SUBSCRIPTION_ID],
            'SUBSCRIPTION_CANCELLED' => ['SUBSCRIPTION_CANCELLED', WebhookEvent::SubscriptionCanceled, RevolutApi::SUBSCRIPTION_ID],
            // A failed payment is past-due, not "the subscription was updated" —
            // that mapping was overloading SubscriptionUpdated a second time.
            'SUBSCRIPTION_OVERDUE' => ['SUBSCRIPTION_OVERDUE', WebhookEvent::SubscriptionPastDue, RevolutApi::SUBSCRIPTION_ID],
        ];
    }

    #[DataProvider('webhookEventProvider')]
    public function test_documented_webhook_events_map_to_support_events_and_resource_ids(
        string $event,
        WebhookEvent $expected,
        string $resourceId,
    ): void {
        $handler = new RevolutWebhookHandler(self::WEBHOOK_SECRET);

        $payload = json_encode(RevolutApi::webhookEvent($event), JSON_THROW_ON_ERROR);

        $parsed = $handler->parseWebhook($payload, []);

        $this->assertSame($expected, $parsed->event);
        $this->assertSame($resourceId, $parsed->id);
    }

    public function test_a_signed_documented_webhook_event_passes_verification_and_parses(): void
    {
        $handler = new RevolutWebhookHandler(self::WEBHOOK_SECRET);

        $payload = json_encode(RevolutApi::webhookEvent('ORDER_COMPLETED'), JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = 'v1='.hash_hmac('sha256', "v1.{$timestamp}.{$payload}", self::WEBHOOK_SECRET);

        $handler->verifyWebhook($payload, [
            'Revolut-Request-Timestamp' => (string) $timestamp,
            'Revolut-Signature' => $signature,
        ]);

        $parsed = $handler->parseWebhook($payload, []);

        $this->assertSame(WebhookEvent::PaymentSucceeded, $parsed->event);
        $this->assertSame(RevolutApi::ORDER_ID, $parsed->id);
        $this->assertSame('Test #3928', $parsed->data['merchant_order_ext_ref']);
    }
}
