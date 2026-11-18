<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Coupon;

/**
 * Full state of an AffiWave coupon, as delivered by the `coupon.created` / `coupon.updated` webhook
 * or by `GET /api/coupon_codes`.
 */
final readonly class CouponState
{
    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_FIXED = 'fixed';

    /**
     * @param string               $value        Discount as a string with two decimals; `fixed` is GROSS in `currency`
     * @param list<string>         $changed      Fields changed by a `coupon.updated` event
     */
    public function __construct(
        public int $id,
        public string $code,
        public string $type,
        public string $value,
        public string $currency,
        public string $status,
        public ?int $usageLimit,
        public ?\DateTimeImmutable $expiresAt,
        public ?int $programId = null,
        public array $changed = [],
    ) {
    }

    /**
     * From the `data` object of a webhook event (snake_case).
     *
     * @param array<string, mixed> $data
     *
     * @throws \InvalidArgumentException incomplete or malformed state
     */
    public static function fromWebhook(array $data): self
    {
        return self::build(
            $data['coupon_id'] ?? null,
            $data['code'] ?? null,
            $data['discount_type'] ?? null,
            $data['discount_value'] ?? null,
            $data['currency'] ?? null,
            $data['status'] ?? null,
            $data['usage_limit'] ?? null,
            $data['expires_at'] ?? null,
            $data['program_id'] ?? null,
            $data['changed'] ?? [],
        );
    }

    /**
     * From an item of `GET /api/coupon_codes` (camelCase). The API does not return the programme: narrow it down with a key bound to the programme.
     *
     * @param array<string, mixed> $item
     *
     * @throws \InvalidArgumentException incomplete or malformed state
     */
    public static function fromApiItem(array $item): self
    {
        return self::build(
            $item['id'] ?? null,
            $item['code'] ?? null,
            $item['discountType'] ?? null,
            $item['discountValue'] ?? null,
            $item['currency'] ?? null,
            $item['status'] ?? null,
            $item['usageLimit'] ?? null,
            $item['expiresAt'] ?? null,
            null,
            [],
        );
    }

    public function isActiveAt(\DateTimeInterface $now): bool
    {
        return 'active' === $this->status && (null === $this->expiresAt || $this->expiresAt > $now);
    }

    private static function build(mixed $id, mixed $code, mixed $type, mixed $value, mixed $currency, mixed $status, mixed $usageLimit, mixed $expiresAt, mixed $programId, mixed $changed): self
    {
        $code = \is_string($code) ? trim($code) : '';
        $type = \is_string($type) ? strtolower($type) : '';
        $type = 'percent' === $type ? self::TYPE_PERCENTAGE : $type;
        if (!is_numeric($id) || (int) $id <= 0 || '' === $code || !\in_array($type, [self::TYPE_PERCENTAGE, self::TYPE_FIXED], true) || !is_numeric($value)) {
            throw new \InvalidArgumentException('Incomplete AffiWave coupon state.');
        }

        $expires = null;
        if (\is_string($expiresAt) && '' !== $expiresAt) {
            try {
                $expires = new \DateTimeImmutable($expiresAt);
            } catch (\Exception) {
                throw new \InvalidArgumentException('Invalid AffiWave coupon expires_at.');
            }
        }

        return new self(
            (int) $id,
            $code,
            $type,
            number_format((float) $value, 2, '.', ''),
            \is_string($currency) ? strtoupper(trim($currency)) : '',
            \is_string($status) && '' !== $status ? $status : 'active',
            is_numeric($usageLimit) ? (int) $usageLimit : null,
            $expires,
            is_numeric($programId) ? (int) $programId : null,
            \is_array($changed) ? array_values(array_filter($changed, 'is_string')) : [],
        );
    }
}
