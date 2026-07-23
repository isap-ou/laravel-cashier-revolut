<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Sandbox;

use Illuminate\Foundation\Application;
use Isapp\CashierRevolut\Tests\TestCase;
use Isapp\CashierSupport\Contracts\GatewayProvider;
use Isapp\CashierSupport\Facades\Cashier;

/**
 * Base for the LIVE sandbox workflow tests — the ones that actually hit
 * https://sandbox-merchant.revolut.com and exercise the driver end-to-end as a real app would,
 * rather than against Http::fake. This is the empirical counterpart to the fake-based Feature
 * suite: the fakes prove the driver consumes the documented shapes, these prove those shapes are
 * still what Revolut sends.
 *
 * Not part of the default `composer test` run: `tests/Sandbox` is deliberately NOT registered as a
 * <testsuite> in phpunit.xml, so `phpunit` (no path) never reaches it. Run it on demand with
 * `composer test:sandbox`, which points phpunit at this directory.
 *
 * Two things must both hold or every test here self-skips (so CI, which injects no Revolut secret,
 * stays green):
 *   - REVOLUT_SECRET_KEY is set (a real sandbox key), and
 *   - REVOLUT_SANDBOX is truthy — the second gate is what guarantees a live run can only ever be
 *     pointed at the sandbox host, never production.
 * The key is read from the environment and is never written to a fixture or printed.
 */
abstract class SandboxTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (! self::sandboxConfigured()) {
            $this->markTestSkipped(
                'Live sandbox test skipped: set REVOLUT_SECRET_KEY (a sandbox key) and REVOLUT_SANDBOX=1 to run.',
            );
        }

        parent::setUp();
    }

    /**
     * The live "revolut" gateway, resolved the way an app resolves it.
     */
    protected function gateway(): GatewayProvider
    {
        return Cashier::provider();
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Point the connector at the sandbox host with a real key, overriding the fake creds the
        // parent sets for the offline suite.
        $app['config']->set('cashier-revolut.sandbox', true);
        $app['config']->set('cashier-revolut.secret_key', (string) getenv('REVOLUT_SECRET_KEY'));

        $apiVersion = getenv('REVOLUT_API_VERSION');
        if (is_string($apiVersion) && $apiVersion !== '') {
            $app['config']->set('cashier-revolut.api_version', $apiVersion);
        }
    }

    /**
     * A real sandbox key present AND the sandbox switch explicitly on — the second is what keeps a
     * live run off the production host.
     */
    private static function sandboxConfigured(): bool
    {
        $key = getenv('REVOLUT_SECRET_KEY');

        return is_string($key) && $key !== ''
            && filter_var(getenv('REVOLUT_SANDBOX'), FILTER_VALIDATE_BOOLEAN);
    }
}
