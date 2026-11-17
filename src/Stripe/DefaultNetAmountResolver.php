<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Stripe;

final class DefaultNetAmountResolver implements NetAmountResolverInterface
{
    public function fromInvoice(object|array $invoice): int
    {
        return StripeAmounts::netFromInvoice($invoice);
    }

    public function fromCheckoutSession(object|array $session): int
    {
        return StripeAmounts::netFromCheckoutSession($session);
    }
}
