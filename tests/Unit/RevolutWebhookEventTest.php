<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Unit;

use Isapp\CashierRevolut\Enums\RevolutWebhookEvent;
use PHPUnit\Framework\TestCase;

/**
 * This enum used to be tested for its mapping onto a provider-agnostic WebhookEvent.
 * Both the mapping and that enum are gone (support#47) — the agnostic vocabulary was
 * eight closed cases that no gateway's catalogue is a subset of, and nothing ever read
 * the result.
 *
 * What is worth testing is the thing nothing tested before: that these strings are real.
 * A typo in a case value fails no type check, subscribes the webhook to nothing at
 * Revolut, and is discovered by the events that never arrive.
 *
 * Since the enum became Revolut's CATALOGUE rather than our coverage, that is the whole
 * of its contract, and it is tested in both directions here. Which of the 22 the driver
 * applies is not this file's business — it is the match in RevolutWebhookSynchronizer.
 *
 * That an unapplied event still reaches a listener is proved by WebhookDeliveryTest; that it
 * ARRIVES to be able to — the actual fix — is proved by WebhookRegistrationTest, because a
 * delivery test cannot distinguish an event we chose not to apply from one we never had a
 * case for. Both take a `return false` and look identical from outside.
 */
class RevolutWebhookEventTest extends TestCase
{
    /**
     * Revolut's full documented catalogue: 22 types in 5 groups.
     *
     * Verified against the `events` enum of create-webhook / update-webhook and
     * cross-checked against the embedded JSON of /docs/api-reference/merchant/ — see
     * .claude/rules/revolut-api.md, which records that an earlier count of 18 came from
     * grepping for ORDER_|SUBSCRIPTION_|PAYOUT and never seeing the Dispute row. So:
     * count the rows.
     */
    private const DOCUMENTED = [
        // Order
        'ORDER_COMPLETED', 'ORDER_AUTHORISED', 'ORDER_CANCELLED', 'ORDER_FAILED',
        'ORDER_INCREMENTAL_AUTHORISATION_AUTHORISED', 'ORDER_INCREMENTAL_AUTHORISATION_DECLINED',
        'ORDER_INCREMENTAL_AUTHORISATION_FAILED',
        // Payment
        'ORDER_PAYMENT_AUTHENTICATION_CHALLENGED', 'ORDER_PAYMENT_AUTHENTICATED',
        'ORDER_PAYMENT_DECLINED', 'ORDER_PAYMENT_FAILED',
        // Subscription
        'SUBSCRIPTION_INITIATED', 'SUBSCRIPTION_FINISHED', 'SUBSCRIPTION_CANCELLED',
        'SUBSCRIPTION_OVERDUE',
        // Payout
        'PAYOUT_INITIATED', 'PAYOUT_COMPLETED', 'PAYOUT_FAILED',
        // Dispute
        'DISPUTE_ACTION_REQUIRED', 'DISPUTE_UNDER_REVIEW', 'DISPUTE_WON', 'DISPUTE_LOST',
    ];

    public function test_the_catalogue_this_is_measured_against_is_the_documented_one(): void
    {
        // The list above is a claim, so it is checked before it is used as a yardstick.
        $this->assertCount(22, self::DOCUMENTED);
        $this->assertSame(self::DOCUMENTED, array_unique(self::DOCUMENTED));
    }

    public function test_every_case_is_an_event_revolut_actually_sends(): void
    {
        foreach (RevolutWebhookEvent::cases() as $case) {
            $this->assertContains(
                $case->value,
                self::DOCUMENTED,
                "[{$case->value}] is not one of Revolut's documented event types. Subscribing to it "
                .'succeeds and delivers nothing, so nothing would have failed until the events did.',
            );
        }
    }

    public function test_every_event_revolut_sends_has_a_case(): void
    {
        // The other direction, and the one that carries the fix. While this enum held only
        // the 8 the synchronizer applies, registration read its cases and so subscribed the
        // endpoint to 8 of 22 — the other 14 were never DELIVERED, and WebhookReceived could
        // not fire for the very events it exists for. A missing case is that bug returning.
        $missing = array_diff(self::DOCUMENTED, RevolutWebhookEvent::values()->all());

        $this->assertSame(
            [],
            $missing,
            'Documented Revolut events with no case: '.implode(', ', $missing).'. Registration '
            .'subscribes to this catalogue, so anything absent here is never delivered at all — '
            .'not to the synchronizer, and not to a WebhookReceived listener.',
        );
    }

    public function test_the_enum_is_exactly_the_catalogue(): void
    {
        $this->assertCount(22, RevolutWebhookEvent::cases());
        // Every DISPUTE_* is present, and DISPUTE_ACTION_REQUIRED is the one with a deadline
        // attached — the event whose absence had a concrete cost.
        $this->assertContains('DISPUTE_ACTION_REQUIRED', RevolutWebhookEvent::values()->all());
    }
}
