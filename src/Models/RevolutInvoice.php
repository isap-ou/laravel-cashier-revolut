<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Models;

use Isapp\CashierSupport\Models\Invoice;

/**
 * Concrete local invoice record for Revolut payments, written by the webhook
 * synchronizer from completed orders.
 */
class RevolutInvoice extends Invoice {}
