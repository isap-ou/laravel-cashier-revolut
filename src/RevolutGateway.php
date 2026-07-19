<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut;

use Isapp\CashierRevolut\Concerns\HandlesRevolutCheckout;
use Isapp\CashierRevolut\Concerns\HandlesRevolutWebhooks;
use Isapp\CashierRevolut\Concerns\InteractsWithRevolut;
use Isapp\CashierRevolut\Concerns\ManagesRevolutCustomer;
use Isapp\CashierRevolut\Concerns\ManagesRevolutPaymentMethods;
use Isapp\CashierRevolut\Concerns\ManagesRevolutSubscriptions;
use Isapp\CashierRevolut\Concerns\PerformsRevolutCharges;
use Isapp\CashierRevolut\Enums\RevolutWebhookEvent;
use Isapp\CashierRevolut\Http\RevolutConnector;
use Isapp\CashierRevolut\Webhooks\RevolutWebhookSynchronizer;
use Isapp\CashierRevolut\Webhooks\RevolutWebhookVerifier;
use Isapp\CashierSupport\Contracts\RegistersWebhooks;
use Isapp\CashierSupport\DTO\WebhookRegistration;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\CashierException;
use Isapp\CashierSupport\Gateway\BaseGateway;
use Isapp\CashierSupport\Gateway\ManagesCustomerRecords;

/**
 * The Revolut Merchant API implementation of the cashier-support
 * GatewayProvider contract. Registered as the "revolut" driver.
 *
 * Invoices are deferred (Invoices capability is NOT supported for now). Revolut has no invoice
 * API, so an invoice must be assembled locally (Gateway\ManagesLocalInvoices) and rendered by a
 * driver-supplied Contracts\InvoiceRenderer (support#33). The renderer is a separate issue: its
 * layout and the data it carries are an open design question, so this driver does not mix in
 * ManagesLocalInvoices and BaseGateway's RefusesInvoices reports Invoices unsupported.
 */
class RevolutGateway extends BaseGateway implements RegistersWebhooks
{
    use HandlesRevolutCheckout;
    use HandlesRevolutWebhooks;
    use InteractsWithRevolut;
    use ManagesCustomerRecords;
    use ManagesRevolutCustomer;
    use ManagesRevolutPaymentMethods;
    use ManagesRevolutSubscriptions;
    use PerformsRevolutCharges;

    /**
     * The driver name this gateway registers under.
     */
    public const DRIVER = 'revolut';

    public function __construct(
        RevolutConnector $connector,
        RevolutWebhookVerifier $webhookVerifier,
        RevolutWebhookSynchronizer $webhookSynchronizer,
    ) {
        $this->connector = $connector;
        $this->webhookVerifier = $webhookVerifier;
        // The synchronizer arrives here for the first time: until support#47, only the
        // driver's own controller held both it and the verifier, which is precisely why
        // the sequencing between them was this package's to get wrong.
        $this->webhookSynchronizer = $webhookSynchronizer;
    }

    /**
     * {@inheritDoc}
     */
    public function registerWebhook(string $url, array $events): WebhookRegistration
    {
        $events = $events === [] ? $this->configuredEvents() : $events;

        foreach ($events as $event) {
            // Not assumed to be a string. The signature says array<int, string> and PHPStan
            // believes it, but this is a public entry point reached from an artisan option
            // and from app code — under strict_types a non-string here would be a TypeError
            // out of tryFrom(), and the contract promises CashierException.
            if (! is_string($event) || RevolutWebhookEvent::tryFrom($event) === null) {
                $name = match (true) {
                    is_string($event) => $event,
                    is_scalar($event) => var_export($event, true),
                    default => get_debug_type($event),
                };

                // Refused before the call: Revolut would accept an unknown name and
                // subscribe the endpoint to nothing, which succeeds and is discovered
                // much later, by the webhooks that never arrive.
                //
                // Measured against the whole catalogue, not against the 8 the synchronizer
                // applies. Subscribing to an event we do not apply is the POINT — it is how
                // a dispute reaches Events\WebhookReceived — so refusing DISPUTE_ACTION_REQUIRED
                // here would close the hatch from the one place that can open it.
                throw new CashierException(
                    "Unknown webhook event [{$name}]. Known events: "
                    .RevolutWebhookEvent::values()->implode(', ')
                );
            }
        }

        $response = $this->connector->request()->post('/webhooks', [
            'url' => $url,
            'events' => array_values($events),
        ]);

        $id = $response->json('id');
        $secret = $response->json('signing_secret');

        // Both are Revolut's to send and neither is optional, so a missing one throws
        // rather than being papered over. An empty-string id would be the same meaningless
        // sentinel this whole change removed from the webhook payload — and worse than
        // useless here, because the DTO's id exists so an operator can go and FIND the
        // endpoint, and the endpoint is already live by the time we know.
        if (! is_string($id) || $id === '' || ! is_string($secret) || $secret === '') {
            throw new CashierException(
                'Revolut registered the webhook'.(is_string($id) && $id !== '' ? " (id: {$id})" : '')
                .' but its response was missing '
                .(is_string($secret) && $secret !== '' ? 'the id' : 'the signing_secret')
                .'. Signature verification will fail until REVOLUT_WEBHOOK_SECRET is set — '
                .'find the webhook in the Revolut dashboard and delete it before retrying, '
                .'or you will accumulate duplicates.'
            );
        }

        return new WebhookRegistration(id: $id, secret: $secret);
    }

    /**
     * The events an empty $events means, per config — the whole catalogue unless narrowed.
     *
     * The default lives in config rather than being read off the enum here, so that what an
     * operator subscribes to is an operator's decision they can see and edit, not a constant
     * they would have to override the gateway to change.
     *
     * A missing or unusable config falls back to the catalogue, in BOTH senses: an endpoint
     * subscribed to nothing is the silent failure this whole change exists to remove, so a bad
     * config must not be able to produce one. That fallback is also load-bearing for upgrades —
     * mergeConfigFrom is a shallow array_merge, so an app that published this config before
     * `events` existed has a webhook block with no such key, and it replaces ours wholesale.
     * Do not remove it: every upgrading app relies on it.
     *
     * Anything left that is not a string is passed through as-is for the validation loop to
     * refuse with a CashierException — coercing it here (strval) would fatal on a nested array,
     * which is the likeliest way to get this key wrong.
     *
     * @return array<int, mixed>
     */
    private function configuredEvents(): array
    {
        $configured = config('cashier-revolut.webhook.events');

        if (! is_array($configured) || $configured === []) {
            return RevolutWebhookEvent::values()->all();
        }

        return array_values($configured);
    }

    /**
     * {@inheritDoc}
     */
    protected function driverName(): string
    {
        return self::DRIVER;
    }

    /**
     * The capabilities no GatewayProvider method can express — so BaseGateway cannot read
     * them off the code, and the driver must name them.
     *
     * Everything else Revolut supports is DERIVED from an overridden method (Charges,
     * Refunds, Customers, CustomersUpdate, Subscriptions, PaymentMethodsList,
     * PaymentMethodsDelete, Webhooks); everything it cannot do — and Invoices, deferred until
     * the renderer question is settled — is left to BaseGateway's Refuses* defaults and reported
     * unsupported. Only these three are an
     * intent behind one method and must be declared:
     *  - SubscriptionSwapAtPeriodEnd — swapSubscription() is one method behind two timings,
     *    and Revolut only ever schedules the change for cycle end (never SubscriptionSwapImmediate).
     *  - SubscriptionTrials — a Contracts\SubscriptionBuilder setter, not a method on this object.
     *  - CheckoutAmount — checkout() is one method behind two shapes; Revolut takes an amount,
     *    not a price catalogue (never CheckoutPrices).
     *
     * {@inheritDoc}
     */
    protected function declaredCapabilities(): array
    {
        return [
            Capability::SubscriptionSwapAtPeriodEnd,
            Capability::SubscriptionTrials,
            Capability::CheckoutAmount,
        ];
    }
}
