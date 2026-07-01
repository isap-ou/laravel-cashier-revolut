<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Tests\Unit;

use Isapp\CashierRevolut\Enums\RevolutPaymentMethodType;
use Isapp\CashierRevolut\Tests\TestCase;
use Isapp\CashierSupport\Contracts\PaymentMethodType;

class RevolutPaymentMethodTypeTest extends TestCase
{
    public function test_it_implements_the_support_contract(): void
    {
        $this->assertInstanceOf(PaymentMethodType::class, RevolutPaymentMethodType::Card);
    }

    public function test_labels_resolve_from_package_translations(): void
    {
        $this->assertSame('Card', RevolutPaymentMethodType::Card->getLabel());
        $this->assertSame('Revolut Pay', RevolutPaymentMethodType::RevolutPay->getLabel());
        $this->assertSame('Pay by Bank', RevolutPaymentMethodType::PayByBank->getLabel());
    }

    public function test_from_revolut_maps_known_and_unknown_types(): void
    {
        $this->assertSame(RevolutPaymentMethodType::RevolutPay, RevolutPaymentMethodType::fromRevolut('revolut_pay'));
        $this->assertSame(RevolutPaymentMethodType::Card, RevolutPaymentMethodType::fromRevolut('something_unknown'));
        $this->assertSame(RevolutPaymentMethodType::Card, RevolutPaymentMethodType::fromRevolut(null));
    }
}
