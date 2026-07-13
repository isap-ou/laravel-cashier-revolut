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
use Isapp\CashierRevolut\Http\RevolutConnector;
use Isapp\CashierRevolut\Webhooks\RevolutWebhookHandler;
use Isapp\CashierSupport\Contracts\GatewayProvider;
use Isapp\CashierSupport\Enums\Capability;
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
class RevolutGateway implements GatewayProvider
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
        RevolutWebhookHandler $webhooks,
        InvoiceRenderer $invoiceRenderer,
    ) {
        $this->connector = $connector;
        $this->webhooks = $webhooks;
        $this->invoiceRenderer = $invoiceRenderer;
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
