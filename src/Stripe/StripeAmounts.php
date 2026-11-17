<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Stripe;

/**
 * Net amounts (after discounts, without tax) from Stripe objects, in minor units. Accepts stripe-php objects or
 * plain arrays (a decoded webhook payload), so the bundle does not depend on stripe-php for this.
 *
 * The commission base in AffiWave is NET: report these values, not `amount_paid`.
 */
final class StripeAmounts
{
    /**
     * `total_excluding_tax`; without it `total` minus all taxes (`total_taxes`, older API: `total_tax_amounts`, `tax`).
     *
     * @param object|array<string, mixed> $invoice Stripe Invoice
     */
    public static function netFromInvoice(object|array $invoice): int
    {
        $data = self::toArray($invoice);
        if (isset($data['total_excluding_tax']) && is_numeric($data['total_excluding_tax'])) {
            return (int) $data['total_excluding_tax'];
        }

        $total = (int) ($data['total'] ?? $data['amount_paid'] ?? 0);
        $tax = 0;
        $entries = $data['total_taxes'] ?? $data['total_tax_amounts'] ?? null;
        if (\is_array($entries)) {
            foreach ($entries as $entry) {
                $tax += \is_array($entry) ? (int) ($entry['amount'] ?? 0) : 0;
            }
        } elseif (isset($data['tax']) && is_numeric($data['tax'])) {
            $tax = (int) $data['tax'];
        }

        return max(0, $total - $tax);
    }

    /**
     * `amount_total` minus `total_details.amount_tax`.
     *
     * @param object|array<string, mixed> $session Stripe Checkout Session
     */
    public static function netFromCheckoutSession(object|array $session): int
    {
        $data = self::toArray($session);
        $tax = $data['total_details']['amount_tax'] ?? 0;

        return max(0, (int) ($data['amount_total'] ?? 0) - (is_numeric($tax) ? (int) $tax : 0));
    }

    /**
     * @param object|array<string, mixed> $value
     *
     * @return array<string, mixed>
     */
    public static function toArray(object|array $value): array
    {
        if (\is_array($value)) {
            return $value;
        }
        if (method_exists($value, 'toArray')) {
            /** @var array<string, mixed> */
            return $value->toArray();
        }

        throw new \InvalidArgumentException(\sprintf('Expected a Stripe object or an array, got %s.', $value::class));
    }
}
