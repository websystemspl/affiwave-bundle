<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Coupon;

use AffiWave\Bundle\Config\Settings;
use AffiWave\Bundle\Repository\AffiWaveCouponRepository;

/**
 * Read side of the synchronised coupons.
 *
 * Use {@see hasActive()} to decide on `allow_promotion_codes` in Stripe Checkout, so the code field shows up only
 * while at least one AffiWave coupon is usable.
 */
class PromotionCodes
{
    public function __construct(
        private readonly AffiWaveCouponRepository $coupons,
        private readonly Settings $settings,
    ) {
    }

    public function hasActive(): bool
    {
        return $this->settings->couponsEnabled && $this->coupons->hasActive();
    }

    /** AffiWave coupon code behind a Stripe promotion code id (`promo_...`), if it is one of ours. */
    public function codeForStripePromotionCodeId(string $promotionCodeId): ?string
    {
        if (!$this->settings->couponsEnabled || '' === $promotionCodeId) {
            return null;
        }

        return $this->coupons->findOneByStripePromotionCodeId($promotionCodeId)?->getCode();
    }
}
