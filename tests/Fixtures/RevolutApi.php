<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Fixtures;

use Illuminate\Support\Facades\Http;

/**
 * Realistic Revolut Merchant API response fixtures.
 *
 * Every payload is copied verbatim from the official Revolut OpenAPI
 * specifications published at https://github.com/revolut-engineering/revolut-openapi
 * (files json/merchant-2025-12-04.json — the API version this driver pins via
 * the Revolut-Api-Version header — and json/merchant-1.0.json for the legacy
 * /api/1.0 endpoints). Those specs are the source rendered at
 * https://developer.revolut.com/docs/merchant/merchant-api.
 *
 * Timestamps are kept verbatim from the documented examples (microsecond
 * precision, e.g. "2023-09-29T14:58:36.079398Z"); the response DTOs declare
 * explicit date formats to parse them.
 */
final class RevolutApi
{
    /** Order id from the documented Res-Order-Min example. */
    public const ORDER_ID = '6516e61c-d279-a454-a837-bc52ce55ed49';

    /** Order token from the documented Res-Order-Min example. */
    public const ORDER_TOKEN = '0adc0e3c-ab44-4f33-bcc0-534ded7354ce';

    /** Customer id from the documented created_customer example. */
    public const CUSTOMER_ID = '6c7c97a8-cfc1-4cf3-8b38-26a74fdf1fae';

    /** Subscription id from the documented with_setup_order example. */
    public const SUBSCRIPTION_ID = '550e8400-e29b-41d4-a716-446655440000';

    /** Saved card payment method id from the documented list_of_payment_methods example. */
    public const PAYMENT_METHOD_ID = 'edef3ba4-60a0-4df3-8f12-e5fc858c2420';

    /** Refund order id from the documented refund_with_minimal_params example. */
    public const REFUND_ID = '6852e9a6-5cf0-ac65-8182-6a9c251011ce';

    /** Payment id from the documented successful_payment_card example. */
    public const PAYMENT_ID = '63c55e04-4208-a43d-9c96-eaee848ffbaf';

    /** Webhook signing secret from the documented created_webhook example. */
    public const SIGNING_SECRET = 'wsk_4jETWMz1g1b37gCONjNp84t2KSSIT7dK';

    /**
     * An order resource, POST /api/orders (201) and GET /api/orders/{order_id} (200).
     *
     * Verbatim Res-Order-Min example from merchant-2025-12-04.json.
     * Documented order state enum: pending, processing, authorised, completed,
     * cancelled, failed.
     *
     * Source: https://developer.revolut.com/docs/merchant/create-order and
     * https://developer.revolut.com/docs/merchant/retrieve-order
     * (spec: revolut-openapi json/merchant-2025-12-04.json, Order-v6 / Res-Order-Min).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function order(array $overrides = []): array
    {
        return array_replace([
            'id' => self::ORDER_ID,
            'token' => self::ORDER_TOKEN,
            'type' => 'payment',
            'state' => 'pending',
            'created_at' => '2023-09-29T14:58:36.079398Z',
            'updated_at' => '2023-09-29T14:58:36.079398Z',
            'amount' => 500,
            'currency' => 'GBP',
            'outstanding_amount' => 500,
            'capture_mode' => 'automatic',
            'authorisation_type' => 'final',
            'checkout_url' => 'https://checkout.revolut.com/payment-link/0adc0e3c-ab44-4f33-bcc0-534ded7354ce',
            'enforce_challenge' => 'automatic',
        ], $overrides);
    }

    /**
     * A payment resource, POST /api/orders/{order_id}/payments (200).
     *
     * Verbatim successful_payment_card example. Note the payment state enum
     * (authorisation_passed, authorised, captured, completed, declined, failed, ...)
     * is distinct from the order state enum.
     *
     * Source: https://developer.revolut.com/docs/merchant/pay-order
     * (spec: revolut-openapi json/merchant-2025-12-04.json).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function payment(array $overrides = []): array
    {
        return array_replace([
            'id' => self::PAYMENT_ID,
            'order_id' => '63c55df6-1461-a886-b90f-f49d3c370253',
            'payment_method' => [
                'type' => 'card',
                'id' => '2b83c23a-650e-40c3-8989-00ee24478738',
                'brand' => 'mastercard_credit',
                'last_four' => '1234',
            ],
            'state' => 'authorisation_passed',
        ], $overrides);
    }

    /**
     * A refund resource, POST /api/orders/{order_id}/refund (201).
     *
     * Trimmed from the verbatim refund_with_minimal_params example — a refund
     * is an order of type "refund" whose related_order_id links the original
     * order.
     *
     * Source: https://developer.revolut.com/docs/merchant/refund-order
     * (spec: revolut-openapi json/merchant-2025-12-04.json).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function refund(array $overrides = []): array
    {
        return array_replace([
            'id' => self::REFUND_ID,
            'type' => 'refund',
            'state' => 'processing',
            'created_at' => '2025-06-18T16:30:30.792962Z',
            'updated_at' => '2025-06-18T16:30:30.954966Z',
            'amount' => 100,
            'currency' => 'GBP',
            'outstanding_amount' => 100,
            'capture_mode' => 'automatic',
            'authorisation_type' => 'final',
            'enforce_challenge' => 'automatic',
            'related_order_id' => '6852e963-d6a9-a5a4-9609-50b3addc5425',
        ], $overrides);
    }

    /**
     * A customer resource, POST /api/customers (201) and GET /api/customers/{customer_id} (200).
     *
     * Verbatim created_customer example.
     *
     * Source: https://developer.revolut.com/docs/merchant/create-customer and
     * https://developer.revolut.com/docs/merchant/retrieve-customer
     * (spec: revolut-openapi json/merchant-2025-12-04.json).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function customer(array $overrides = []): array
    {
        return array_replace([
            'id' => self::CUSTOMER_ID,
            'full_name' => 'Example Customer',
            'email' => 'example.customer@example.com',
            'phone' => '+441234567890',
            'created_at' => '2020-06-24T12:03:39.979397Z',
            'updated_at' => '2020-06-24T12:03:39.979397Z',
        ], $overrides);
    }

    /**
     * A subscription resource, POST /api/subscriptions (201).
     *
     * Verbatim with_setup_order example. Documented subscription state enum:
     * pending, active, overdue, paused, cancelled, finished. Trial fields are
     * trial_duration (ISO 8601 duration) and trial_end_date; the schema has no
     * cancelled_at field. POST /api/subscriptions/{id}/cancel returns
     * 204 No Content.
     *
     * Source: https://developer.revolut.com/docs/merchant/create-subscription and
     * https://developer.revolut.com/docs/merchant/cancel-subscription
     * (spec: revolut-openapi json/merchant-2025-12-04.json, Subscription schema).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function subscription(array $overrides = []): array
    {
        return array_replace([
            'id' => self::SUBSCRIPTION_ID,
            'external_reference' => 'ext-ref-12345',
            'state' => 'pending',
            'customer_id' => '650e8400-e29b-41d4-a716-446655440001',
            'plan_id' => '750e8400-e29b-41d4-a716-446655440002',
            'plan_variation_id' => '850e8400-e29b-41d4-a716-446655440003',
            'payment_method_type' => 'automatic',
            'created_at' => '2025-06-05T21:00:00.036001Z',
            'updated_at' => '2025-06-05T21:00:00.036001Z',
            'setup_order_id' => 'a50e8400-e29b-41d4-a716-446655440005',
            'current_cycle_id' => 'a31627fb-b037-4566-8d7b-f380c1f44653',
        ], $overrides);
    }

    /**
     * The payment method list, GET /api/customers/{customer_id}/payment-methods (200)
     * as documented for API version 2025-12-04: wrapped in a "payment_methods"
     * key, lower-case type values, and card details (brand, last_four,
     * expiry_month, ...) at the top level of each entry.
     *
     * Verbatim (trimmed) list_of_payment_methods example.
     *
     * Beware: the legacy /api/1.0 endpoint the driver actually calls documents
     * a different shape — see paymentMethodsLegacy().
     *
     * Source: https://developer.revolut.com/docs/merchant/retrieve-all-payment-methods
     * (spec: revolut-openapi json/merchant-2025-12-04.json).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function paymentMethods(array $overrides = []): array
    {
        return array_replace([
            'payment_methods' => [
                [
                    'id' => '648334a8-9546-a983-a81a-efc6d5bdd0be',
                    'type' => 'revolut_pay',
                    'saved_for' => 'merchant',
                    'created_at' => '2023-06-09T14:18:16.577888Z',
                ],
                [
                    'id' => self::PAYMENT_METHOD_ID,
                    'type' => 'card',
                    'saved_for' => 'customer',
                    'created_at' => '2023-03-24T14:15:22Z',
                    'bin' => '459678',
                    'last_four' => '6896',
                    'expiry_month' => 3,
                    'expiry_year' => 2025,
                    'cardholder_name' => 'Example Customer',
                    'brand' => 'visa',
                    'funding' => 'debit',
                    'issuer' => 'EXAMPLE ISSUER',
                    'issuer_country' => 'GB',
                ],
            ],
        ], $overrides);
    }

    /**
     * The payment method list as documented for the LEGACY endpoint
     * GET /api/1.0/customers/{customer_id}/payment-methods (200) — the path
     * this driver calls: a bare JSON array, UPPER-CASE type values, and card
     * details nested under "method_details" with "last4" (not "last_four").
     *
     * Verbatim (trimmed) list_of_payment_methods example.
     *
     * Source: revolut-openapi json/merchant-1.0.json,
     * GET /api/1.0/customers/{customer_id}/payment-methods.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function paymentMethodsLegacy(): array
    {
        return [
            [
                'id' => '648334a8-9546-a983-a81a-efc6d5bdd0be',
                'type' => 'REVOLUT_PAY',
                'saved_for' => 'MERCHANT',
                'method_details' => [
                    'created_at' => '2023-06-09T14:18:16.577888Z',
                ],
            ],
            [
                'id' => self::PAYMENT_METHOD_ID,
                'type' => 'CARD',
                'saved_for' => 'CUSTOMER',
                'method_details' => [
                    'bin' => '459678',
                    'last4' => '6896',
                    'expiry_month' => 3,
                    'expiry_year' => 2025,
                    'cardholder_name' => 'Example Customer',
                    'brand' => 'VISA',
                    'funding' => 'DEBIT',
                    'issuer' => 'EXAMPLE ISSUER',
                    'issuer_country' => 'GB',
                    'created_at' => '2023-03-24T14:15:22Z',
                ],
            ],
        ];
    }

    /**
     * A created webhook, POST /api/webhooks and POST /api/1.0/webhooks (200).
     *
     * Verbatim created_webhook example — identical in the 2025-12-04 and
     * legacy 1.0 specs, including the signing_secret ("wsk_...").
     *
     * Source: https://developer.revolut.com/docs/merchant/create-webhook
     * (spec: revolut-openapi json/merchant-2025-12-04.json and json/merchant-1.0.json).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function webhookCreated(array $overrides = []): array
    {
        return array_replace([
            'id' => 'c6b981f4-53b3-47d5-9b24-4f87af1160eb',
            'url' => 'https://example.com/webhooks',
            'events' => ['ORDER_AUTHORISED', 'ORDER_COMPLETED'],
            'signing_secret' => self::SIGNING_SECRET,
        ], $overrides);
    }

    /**
     * A webhook event body as POSTed by Revolut to the merchant's webhook URL.
     *
     * ORDER_* events carry {event, order_id, merchant_order_ext_ref} and
     * SUBSCRIPTION_* events carry {event, subscription_id, external_reference},
     * per the Webhook-Order-Event and Webhook-Subscription-Event schemas.
     *
     * Source: https://developer.revolut.com/docs/merchant/webhooks and
     * https://developer.revolut.com/docs/guides/accept-payments/tutorials/work-with-webhooks/using-webhooks
     * (spec: revolut-openapi json/merchant-2025-12-04.json).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function webhookEvent(string $event, array $overrides = []): array
    {
        $base = str_starts_with($event, 'SUBSCRIPTION_')
            ? [
                'event' => $event,
                'subscription_id' => self::SUBSCRIPTION_ID,
                'external_reference' => 'ext-ref-12345',
            ]
            : [
                'event' => $event,
                'order_id' => self::ORDER_ID,
                'merchant_order_ext_ref' => 'Test #3928',
            ];

        return array_replace($base, $overrides);
    }

    /**
     * An error body (Error-v2 schema): {code, message, timestamp}.
     *
     * Codes for 400 (bad_request), 401 (unauthenticated) and 404 (not_found)
     * come from the documented examples. Codes for other statuses are
     * UNVERIFIED — the spec only guarantees the Error-v2 shape.
     *
     * Source: revolut-openapi json/merchant-2025-12-04.json,
     * components/schemas/Error-v2 and per-endpoint error examples
     * (e.g. https://developer.revolut.com/docs/merchant/retrieve-order).
     *
     * @return array<string, mixed>
     */
    public static function error(int $status, string $message): array
    {
        return [
            'code' => match ($status) {
                400 => 'bad_request',
                401 => 'unauthenticated',
                404 => 'not_found',
                default => 'error',
            },
            'message' => $message,
            'timestamp' => 1721049596461,
        ];
    }

    /**
     * Register a complete Http::fake() route map for the whole Merchant API
     * surface this driver uses, backed by the documented payloads above.
     *
     * Order timestamps are overridden to the (also documented) second-precision
     * variant because the driver cannot cast the microsecond variant yet.
     * The subscription cancel route returns the documented 204 No Content —
     * note that RevolutGateway::cancelSubscription() currently cannot consume
     * that response (see RevolutApiContractTest).
     *
     * Pass more specific patterns via $overrides; they take priority over the
     * defaults.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function fake(array $overrides = []): void
    {
        Http::fake($overrides + [
            '*/orders/*/payments' => Http::response(self::payment()),
            '*/orders/*/refund' => Http::response(self::refund(), 201),
            '*/orders/'.self::ORDER_ID => Http::response(self::order()),
            '*/orders' => Http::response(self::order(), 201),
            '*/customers/*/payment-methods/*' => Http::response(null, 204),
            '*/customers/*/payment-methods' => Http::response(self::paymentMethods()),
            '*/customers/'.self::CUSTOMER_ID => Http::response(self::customer()),
            '*/customers' => Http::response(self::customer(), 201),
            '*/subscriptions/*/cycles/*' => Http::response(['id' => 'cyc-0001', 'state' => 'active', 'start_date' => '2025-06-05T21:00:00Z', 'end_date' => '2025-07-05T21:00:00Z']),
            '*/subscriptions/*/cancel' => Http::response(null, 204),
            '*/subscriptions' => Http::response(self::subscription(), 201),
            '*/webhooks' => Http::response(self::webhookCreated()),
        ]);
    }
}
