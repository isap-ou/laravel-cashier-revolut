<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Models;

use Isapp\CashierSupport\Models\Subscription;

/**
 * Concrete local record of a Revolut subscription.
 *
 * Relations resolve to the Revolut item model via the cashier-support
 * `models` config, bound by CashierRevolutServiceProvider.
 */
class RevolutSubscription extends Subscription {}
