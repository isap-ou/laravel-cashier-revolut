<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Unit;

use Isapp\CashierRevolut\Webhooks\RevolutIncomingWebhook;
use Isapp\CashierRevolut\Webhooks\RevolutWebhookSynchronizer;
use Isapp\CashierRevolut\Webhooks\RevolutWebhookVerifier;
use Isapp\CashierSupport\Exceptions\UnexpectedWebhookEventException;
use Isapp\CashierSupport\Exceptions\WebhookVerificationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The two rules support#47 put on a driver, at the unit level.
 *
 * Both are inversions of what this package used to do, which is why they get their own
 * tests rather than being left to the end-to-end suite: an inverted rule that nobody
 * pins gets quietly re-inverted by the next person who finds the old behaviour more
 * intuitive. It WAS more intuitive. It was also #24.
 */
class RevolutIncomingWebhookTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_passes_the_synchronizer_s_answer_through_without_embellishing_it(): void
    {
        // Named for what it actually proves. The RULE — an unmapped event returns false
        // and never throws — lives in the synchronizer, and a test that mocks the
        // synchronizer cannot test it: the mock would answer false against any
        // implementation, including one that throws. WebhookSyncTest owns that rule
        // (test_an_unmapped_event_is_not_handled_and_is_not_an_error) and the end-to-end
        // WebhookDeliveryTest owns the consequence. This owns the wiring between them.
        $synchronizer = Mockery::mock(RevolutWebhookSynchronizer::class);
        $synchronizer->shouldReceive('handle')->once()->andReturnFalse();

        $webhook = new RevolutIncomingWebhook(
            '{"event":"PAYOUT_INITIATED","id":"po_1"}',
            [],
            $this->verifier(),
            $synchronizer,
        );

        $this->assertFalse($webhook->pipeline());
    }

    public function test_the_body_is_verified_even_when_only_pipeline_is_called(): void
    {
        // Support calls parse() first and always. This pins the case where it does not:
        // verification lives in this package, so "the controller happens to call them in
        // that order" is not something this package may rely on. It is the half of the
        // guarantee support explicitly cannot make.
        $verifier = Mockery::mock(RevolutWebhookVerifier::class);
        $verifier->shouldReceive('verify')->once()->andThrow(WebhookVerificationException::invalidSignature());

        $synchronizer = Mockery::mock(RevolutWebhookSynchronizer::class);
        $synchronizer->shouldNotReceive('handle');

        $webhook = new RevolutIncomingWebhook('{"event":"ORDER_COMPLETED"}', [], $verifier, $synchronizer);

        $this->expectException(WebhookVerificationException::class);

        $webhook->pipeline();
    }

    public function test_the_body_is_verified_exactly_once_across_both_calls(): void
    {
        // Memoized, not re-verified: an HMAC per call would be waste, and a verifier with
        // a time window could plausibly disagree with itself between the two.
        $verifier = Mockery::mock(RevolutWebhookVerifier::class);
        $verifier->shouldReceive('verify')->once();

        $synchronizer = Mockery::mock(RevolutWebhookSynchronizer::class);
        $synchronizer->shouldReceive('handle')->once()->andReturnTrue();

        $webhook = new RevolutIncomingWebhook('{"event":"ORDER_COMPLETED"}', [], $verifier, $synchronizer);

        $this->assertSame(['event' => 'ORDER_COMPLETED'], $webhook->parse());
        $this->assertSame(['event' => 'ORDER_COMPLETED'], $webhook->parse());
        $this->assertTrue($webhook->pipeline());
    }

    /**
     * @param  string  $body  A verified body that is not a JSON object.
     */
    #[DataProvider('unreadableBodies')]
    public function test_a_body_that_is_not_a_json_object_throws_rather_than_flattening(string $body): void
    {
        // parse() promises array<string, mixed> and PHP will not hold it to that:
        // json_decode('[1,2,3]', true) is an array, so returning it type-checks while
        // handing every listener an int-keyed list where an event belongs. The empty array
        // is worse — indistinguishable from a real unmapped event.
        $synchronizer = Mockery::mock(RevolutWebhookSynchronizer::class);
        $synchronizer->shouldNotReceive('handle');

        $webhook = new RevolutIncomingWebhook($body, [], $this->verifier(), $synchronizer);

        $this->expectException(UnexpectedWebhookEventException::class);

        $webhook->parse();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unreadableBodies(): array
    {
        return [
            'not json at all' => ['not json'],
            'a json scalar' => ['"5"'],
            'json null' => ['null'],
            'an empty body' => [''],
            'a json list' => ['[1,2,3]'],
            // A valid but EMPTY object. json_decode makes it [], which array_is_list()
            // calls a list — so it takes the same branch, and it should: a body with no
            // event name is nothing to act on and nothing to hand a listener.
            'an empty json object' => ['{}'],
        ];
    }

    private function verifier(): RevolutWebhookVerifier
    {
        $verifier = Mockery::mock(RevolutWebhookVerifier::class);
        $verifier->shouldReceive('verify')->andReturnNull();

        /** @var RevolutWebhookVerifier $verifier */
        return $verifier;
    }
}
