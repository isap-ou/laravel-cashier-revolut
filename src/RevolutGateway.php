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
use Isapp\CashierSupport\Contracts\GatewayProvider;
use Isapp\CashierSupport\Contracts\RegistersWebhooks;
use Isapp\CashierSupport\DTO\WebhookRegistration;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\CashierException;
use Isapp\CashierSupport\Gateway\ManagesCustomerRecords;
use Isapp\CashierSupport\Gateway\ManagesLocalInvoices;
use Isapp\CashierSupport\Invoice\InvoiceRenderer;

/**
 * The Revolut Merchant API implementation of the cashier-support
 * GatewayProvider contract. Registered as the "revolut" driver.
 *
 * Invoices use cashier-support's local-invoice implementation: records are
 * written by the webhook synchronizer from completed orders and rendered to
 * PDF locally (Revolut has no invoice API).
 */
class RevolutGateway implements GatewayProvider, RegistersWebhooks
{
    use HandlesRevolutCheckout;
    use HandlesRevolutWebhooks;
    use InteractsWithRevolut;
    use ManagesCustomerRecords;
    use ManagesLocalInvoices;
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
        InvoiceRenderer $invoiceRenderer,
    ) {
        $this->connector = $connector;
        $this->webhookVerifier = $webhookVerifier;
        // The synchronizer arrives here for the first time: until support#47, only the
        // driver's own controller held both it and the verifier, which is precisely why
        // the sequencing between them was this package's to get wrong.
        $this->webhookSynchronizer = $webhookSynchronizer;
        $this->invoiceRenderer = $invoiceRenderer;
    }

    /**
     * {@inheritDoc}
     */
    public function registerWebhook(string $url, array $events): WebhookRegistration
    {
        $events = $events === [] ? array_column(RevolutWebhookEvent::cases(), 'value') : $events;

        foreach ($events as $event) {
            if (RevolutWebhookEvent::tryFrom($event) === null) {
                // Refused before the call: Revolut would accept an unknown name and
                // subscribe the endpoint to nothing, which succeeds and is discovered
                // much later, by the webhooks that never arrive.
                throw new CashierException(
                    "Unknown webhook event [{$event}]. Known events: "
                    .implode(', ', array_column(RevolutWebhookEvent::cases(), 'value'))
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
     * {@inheritDoc}
     */
    protected function driverName(): string
    {
        return self::DRIVER;
    }

    /**
     * The capabilities Revolut actually supports.
     *
     * Immediate cancellation, pause and resume of subscriptions, adding a
     * payment method server-side, and taxes are intentionally absent — those
     * operations throw UnsupportedOperationException. Swap is supported, but
     * it is scheduled at the end of the billing cycle rather than immediate.
     *
     * {@inheritDoc}
     */
    public function capabilities(): array
    {
        return [
            Capability::Charges,
            Capability::Refunds,
            Capability::Customers,
            Capability::Subscriptions,
            Capability::SubscriptionTrials,
            Capability::SubscriptionSwapAtPeriodEnd,
            Capability::PaymentMethodsList,
            Capability::PaymentMethodsDelete,
            Capability::CheckoutAmount,
            Capability::Invoices,
            Capability::Webhooks,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function supports(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }
}
