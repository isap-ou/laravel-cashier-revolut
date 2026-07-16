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

    public function test_it_maps_eight_of_the_twenty_two_and_the_gap_is_deliberate(): void
    {
        // Not a target to grow towards: whether to map a payout or a dispute needs "does
        // this belong in a provider-agnostic contract at all" answered first. The 14 are
        // not lost meanwhile — support dispatches WebhookReceived with the raw body for
        // every verified delivery, which is #24's fix and the reason this gap is tolerable.
        $this->assertCount(8, RevolutWebhookEvent::cases());

        $unmapped = array_diff(self::DOCUMENTED, array_column(RevolutWebhookEvent::cases(), 'value'));

        $this->assertCount(14, $unmapped);
        // Every DISPUTE_* is among them, and DISPUTE_ACTION_REQUIRED is the one with a
        // deadline attached — the concrete cost of the gap, and why it is documented
        // rather than shrugged at.
        $this->assertContains('DISPUTE_ACTION_REQUIRED', $unmapped);
    }
}
