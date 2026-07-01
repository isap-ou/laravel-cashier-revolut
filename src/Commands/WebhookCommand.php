<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Commands;

use Illuminate\Console\Command;
use Isapp\CashierRevolut\Enums\RevolutWebhookEvent;
use Isapp\CashierRevolut\Http\RevolutConnector;
use Isapp\CashierSupport\Exceptions\CashierException;

/**
 * Registers a webhook endpoint with Revolut and prints its signing secret.
 */
class WebhookCommand extends Command
{
    protected $signature = 'cashier-revolut:webhook {url? : The publicly reachable webhook URL} {--events=* : Event types to subscribe to}';

    protected $description = 'Register a webhook endpoint with Revolut and print its signing secret.';

    public function __construct(private readonly RevolutConnector $connector)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $urlArgument = $this->argument('url');
        $url = is_string($urlArgument) && $urlArgument !== ''
            ? $urlArgument
            : url((string) config('cashier-revolut.webhook.path', 'webhook/revolut'));

        $events = $this->option('events');
        $events = is_array($events) && $events !== []
            ? array_values(array_map('strval', $events))
            : array_column(RevolutWebhookEvent::cases(), 'value');

        foreach ($events as $event) {
            if (RevolutWebhookEvent::tryFrom($event) === null) {
                $this->error("Unknown webhook event [{$event}]. Known events: ".implode(', ', array_column(RevolutWebhookEvent::cases(), 'value')));

                return self::FAILURE;
            }
        }

        try {
            $response = $this->connector->request()->post('/webhooks', [
                'url' => $url,
                'events' => $events,
            ]);
        } catch (CashierException $exception) {
            $this->error("Webhook registration failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Webhook registered: {$url}");

        $secret = $response->json('signing_secret');

        if (! is_string($secret)) {
            $this->warn('Revolut did not return a signing_secret — webhook signature verification will fail until REVOLUT_WEBHOOK_SECRET is set.');

            return self::FAILURE;
        }

        $this->warn('The signing secret is shown once and will appear in console/CI logs:');
        $this->line("Signing secret (set REVOLUT_WEBHOOK_SECRET): {$secret}");

        return self::SUCCESS;
    }
}
