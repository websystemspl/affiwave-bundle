<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Stripe;

use AffiWave\Bundle\Coupon\PromotionCodes;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Finds the AffiWave coupon code used for a Stripe payment, to pass as `couponCode` of a conversion.
 *
 * Only promotion codes that come from AffiWave count (a row in `affiwave_coupon`, or `affiwave_coupon_id` in the
 * promotion code's metadata) - a coupon you created by hand is never reported as an AffiWave one. Webhook payloads
 * carry discount ids only; those are resolved locally first and through the Stripe gateway when it is available.
 */
class DiscountCodeResolver
{
    public function __construct(
        private readonly PromotionCodes $promotionCodes,
        private readonly ?StripeGatewayInterface $gateway = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /** @param object|array<string, mixed> $invoice Stripe Invoice (object or decoded payload) */
    public function couponCodeFromInvoice(object|array $invoice): ?string
    {
        $data = StripeAmounts::toArray($invoice);
        $discounts = $data['discounts'] ?? [];
        if (!\is_array($discounts) || [] === $discounts) {
            return null;
        }

        // Ids only (`di_...`): fetch the invoice with discounts expanded.
        if (!\is_array(reset($discounts)) && null !== $this->gateway && \is_string($data['id'] ?? null)) {
            try {
                $discounts = $this->gateway->retrieveInvoice($data['id'])['discounts'] ?? [];
            } catch (\Throwable $e) {
                $this->logger->error('[AffiWave] reading invoice discounts failed', ['invoice' => $data['id'], 'error' => $e->getMessage()]);

                return null;
            }
        }

        return $this->codeFromDiscounts(\is_array($discounts) ? $discounts : []);
    }

    /** @param object|array<string, mixed> $session Stripe Checkout Session (object or decoded payload) */
    public function couponCodeFromSession(object|array $session): ?string
    {
        $data = StripeAmounts::toArray($session);

        $code = $this->codeFromDiscounts($this->sessionDiscounts($data));
        if (null !== $code) {
            return $code;
        }

        // Webhook payloads leave `total_details.breakdown` out: fetch it when the session has any discount.
        $discounted = (int) ($data['total_details']['amount_discount'] ?? 0) > 0;
        if ($discounted && null !== $this->gateway && \is_string($data['id'] ?? null) && !isset($data['total_details']['breakdown'])) {
            try {
                return $this->codeFromDiscounts($this->sessionDiscounts($this->gateway->retrieveCheckoutSession($data['id'])));
            } catch (\Throwable $e) {
                $this->logger->error('[AffiWave] reading session discounts failed', ['session' => $data['id'], 'error' => $e->getMessage()]);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $session
     *
     * @return array<mixed>
     */
    private function sessionDiscounts(array $session): array
    {
        $discounts = [];
        $breakdown = $session['total_details']['breakdown']['discounts'] ?? [];
        foreach (\is_array($breakdown) ? $breakdown : [] as $entry) {
            if (\is_array($entry) && isset($entry['discount'])) {
                $discounts[] = $entry['discount'];
            }
        }
        $own = $session['discounts'] ?? [];

        return array_merge($discounts, \is_array($own) ? $own : []);
    }

    /**
     * @param array<mixed> $discounts
     */
    private function codeFromDiscounts(array $discounts): ?string
    {
        foreach ($discounts as $discount) {
            if (!\is_array($discount)) {
                continue;
            }
            $code = $this->codeFromPromotionCode($discount['promotion_code'] ?? null);
            if (null !== $code) {
                return $code;
            }
        }

        return null;
    }

    private function codeFromPromotionCode(mixed $promotionCode): ?string
    {
        if (\is_array($promotionCode)) {
            $ours = self::codeFromPromotionCodeObject($promotionCode);
            if (null !== $ours) {
                return $ours;
            }
            $promotionCode = $promotionCode['id'] ?? null;
        }
        if (!\is_string($promotionCode) || '' === $promotionCode) {
            return null;
        }

        $local = $this->promotionCodes->codeForStripePromotionCodeId($promotionCode);
        if (null !== $local || null === $this->gateway) {
            return $local;
        }

        try {
            return self::codeFromPromotionCodeObject($this->gateway->retrievePromotionCode($promotionCode));
        } catch (\Throwable $e) {
            $this->logger->error('[AffiWave] reading promotion code failed', ['promotion_code' => $promotionCode, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** @param array<mixed> $promotionCode */
    private static function codeFromPromotionCodeObject(array $promotionCode): ?string
    {
        $metadata = $promotionCode['metadata'] ?? null;
        $code = $promotionCode['code'] ?? null;

        return \is_array($metadata) && isset($metadata['affiwave_coupon_id']) && \is_string($code) && '' !== $code ? $code : null;
    }
}
