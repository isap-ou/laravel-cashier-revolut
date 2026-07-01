<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Unit;

use Isapp\CashierRevolut\Enums\RevolutWebhookEvent;
use Isapp\CashierSupport\Enums\WebhookEvent;
use PHPUnit\Framework\TestCase;

class RevolutWebhookEventTest extends TestCase
{
    public function test_every_case_maps_to_a_support_event(): void
    {
        foreach (RevolutWebhookEvent::cases() as $case) {
            $this->assertInstanceOf(WebhookEvent::class, $case->toWebhookEvent());
        }
    }

    public function test_key_mappings(): void
    {
        $this->assertSame(WebhookEvent::PaymentSucceeded, RevolutWebhookEvent::OrderCompleted->toWebhookEvent());
        $this->assertSame(WebhookEvent::PaymentFailed, RevolutWebhookEvent::OrderPaymentFailed->toWebhookEvent());
        $this->assertSame(WebhookEvent::SubscriptionCreated, RevolutWebhookEvent::SubscriptionInitiated->toWebhookEvent());
        $this->assertSame(WebhookEvent::SubscriptionCanceled, RevolutWebhookEvent::SubscriptionCancelled->toWebhookEvent());
    }
}
