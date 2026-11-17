<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Stripe;

/**
 * How the application turns a Stripe payment into the NET commission base (minor units).
 *
 * The default ({@see DefaultNetAmountResolver}) trusts Stripe's own tax figures. Implement the interface and alias it
 * when your prices are gross without Stripe Tax and the VAT has to be computed by the application.
 */
interface NetAmountResolverInterface
{
    /** @param object|array<string, mixed> $invoice Stripe Invoice (object or decoded payload) */
    public function fromInvoice(object|array $invoice): int;

    /** @param object|array<string, mixed> $session Stripe Checkout Session (object or decoded payload) */
    public function fromCheckoutSession(object|array $session): int;
}
