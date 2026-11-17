<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Conversion;

/**
 * A paid order to report to AffiWave. Amounts are NET, in minor units (cents) of `currency`.
 */
final readonly class Conversion
{
    public \DateTimeImmutable $occurredAt;

    /**
     * @param string      $externalOrderId Your idempotency key, e.g. the Stripe invoice id or checkout session id
     * @param int         $amountMinor     Net amount in minor units; 0 or less is skipped
     * @param string      $currency        ISO 4217 code of the payment (AffiWave converts to the programme currency itself)
     * @param string|null $clickToken      A stored `aw_click` token (wins over the coupon)
     * @param string|null $couponCode      An AffiWave coupon code used for the payment
     */
    public function __construct(
        public string $externalOrderId,
        public int $amountMinor,
        public string $currency,
        public ?string $clickToken = null,
        public ?string $couponCode = null,
        ?\DateTimeInterface $occurredAt = null,
        public ?string $customerEmail = null,
        public ?string $customerIp = null,
        public ?string $customerUserAgent = null,
    ) {
        $this->occurredAt = null === $occurredAt ? new \DateTimeImmutable() : \DateTimeImmutable::createFromInterface($occurredAt);
    }
}
