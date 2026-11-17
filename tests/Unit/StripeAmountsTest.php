<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Tests\Unit;

use AffiWave\Bundle\Stripe\DefaultNetAmountResolver;
use AffiWave\Bundle\Stripe\StripeAmounts;
use PHPUnit\Framework\TestCase;
use Stripe\Checkout\Session;
use Stripe\Invoice;

final class StripeAmountsTest extends TestCase
{
    public function testInvoiceNetPrefersTotalExcludingTax(): void
    {
        self::assertSame(8130, StripeAmounts::netFromInvoice(['total_excluding_tax' => 8130, 'total' => 10000, 'amount_paid' => 10000]));
        self::assertSame(0, StripeAmounts::netFromInvoice(['total_excluding_tax' => 0, 'total' => 0]));
    }

    public function testInvoiceNetFallsBackToTotalMinusTaxes(): void
    {
        self::assertSame(8130, StripeAmounts::netFromInvoice(['total' => 10000, 'total_taxes' => [['amount' => 1870], ['amount' => 0]]]));
        self::assertSame(8130, StripeAmounts::netFromInvoice(['total' => 10000, 'total_tax_amounts' => [['amount' => 1870, 'inclusive' => false]]]));
        self::assertSame(8130, StripeAmounts::netFromInvoice(['total' => 10000, 'tax' => 1870]));
        self::assertSame(10000, StripeAmounts::netFromInvoice(['total' => 10000]));
        self::assertSame(9000, StripeAmounts::netFromInvoice(['amount_paid' => 9000]));
    }

    public function testInvoiceNetNeverNegative(): void
    {
        self::assertSame(0, StripeAmounts::netFromInvoice(['total' => 100, 'tax' => 500]));
    }

    public function testCheckoutSessionNet(): void
    {
        self::assertSame(8130, StripeAmounts::netFromCheckoutSession(['amount_total' => 10000, 'total_details' => ['amount_tax' => 1870]]));
        self::assertSame(10000, StripeAmounts::netFromCheckoutSession(['amount_total' => 10000]));
        self::assertSame(0, StripeAmounts::netFromCheckoutSession(['amount_total' => 0, 'total_details' => ['amount_tax' => 0]]));
    }

    public function testAcceptsStripeObjects(): void
    {
        $invoice = Invoice::constructFrom(['id' => 'in_1', 'total_excluding_tax' => 4200]);
        $session = Session::constructFrom(['id' => 'cs_1', 'amount_total' => 12300, 'total_details' => ['amount_tax' => 2300]]);

        self::assertSame(4200, StripeAmounts::netFromInvoice($invoice));
        self::assertSame(10000, StripeAmounts::netFromCheckoutSession($session));
        self::assertSame(4200, (new DefaultNetAmountResolver())->fromInvoice($invoice));
        self::assertSame(10000, (new DefaultNetAmountResolver())->fromCheckoutSession($session));
    }

    public function testRejectsForeignObjects(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StripeAmounts::netFromInvoice(new \stdClass());
    }
}
