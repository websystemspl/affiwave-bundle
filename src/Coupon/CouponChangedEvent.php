<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Coupon;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched by the webhook controller for every verified `coupon.created` / `coupon.updated` event of the
 * configured programme, before the built-in Stripe synchronisation (when `coupons.enabled`). Listen to it to keep
 * coupons somewhere else too. An exception from a listener makes the endpoint answer 500, so AffiWave retries.
 */
final class CouponChangedEvent extends Event
{
    public function __construct(
        public readonly CouponState $state,
        public readonly string $eventType,
        public readonly ?\DateTimeImmutable $eventAt = null,
    ) {
    }
}
