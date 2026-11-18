<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Mapping of an AffiWave coupon to the Stripe coupon + promotion code that mirrors it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'affiwave_coupon')]
#[ORM\UniqueConstraint(name: 'uniq_affiwave_coupon_id', columns: ['affiwave_coupon_id'])]
#[ORM\Index(name: 'idx_affiwave_coupon_promo', columns: ['stripe_promotion_code_id'])]
class AffiWaveCoupon
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id')]
    private ?int $id = null; // @phpstan-ignore property.unusedType

    #[ORM\Column(name: 'code', length: 500)]
    private string $code = '';

    #[ORM\Column(name: 'stripe_coupon_id', length: 255, nullable: true)]
    private ?string $stripeCouponId = null;

    #[ORM\Column(name: 'stripe_promotion_code_id', length: 255, nullable: true)]
    private ?string $stripePromotionCodeId = null;

    /** Hash of everything Stripe cannot change on an existing code (code, amount, limits, expiry, duration...). */
    #[ORM\Column(name: 'conditions_hash', length: 64, nullable: true)]
    private ?string $conditionsHash = null;

    /** Is the Stripe promotion code active right now. */
    #[ORM\Column(name: 'active')]
    private bool $active = false;

    /** Counts Stripe create attempts; part of the idempotency key. */
    #[ORM\Column(name: 'sync_attempts', options: ['default' => 0])]
    private int $syncAttempts = 0;

    #[ORM\Column(name: 'sync_error', type: 'text', nullable: true)]
    private ?string $syncError = null;

    #[ORM\Column(name: 'expires_at', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(name: 'last_event_at', nullable: true)]
    private ?\DateTimeImmutable $lastEventAt = null;

    #[ORM\Column(name: 'synced_at', nullable: true)]
    private ?\DateTimeImmutable $syncedAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Column(name: 'affiwave_coupon_id')]
        private int $affiwaveCouponId,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAffiwaveCouponId(): int
    {
        return $this->affiwaveCouponId;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getStripeCouponId(): ?string
    {
        return $this->stripeCouponId;
    }

    public function getStripePromotionCodeId(): ?string
    {
        return $this->stripePromotionCodeId;
    }

    public function setStripeIds(string $couponId, string $promotionCodeId): static
    {
        $this->stripeCouponId = $couponId;
        $this->stripePromotionCodeId = $promotionCodeId;

        return $this;
    }

    public function getConditionsHash(): ?string
    {
        return $this->conditionsHash;
    }

    public function setConditionsHash(?string $hash): static
    {
        $this->conditionsHash = $hash;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getSyncError(): ?string
    {
        return $this->syncError;
    }

    public function setSyncError(?string $error): static
    {
        $this->syncError = null === $error ? null : mb_substr($error, 0, 1000);

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $at): static
    {
        $this->expiresAt = $at;

        return $this;
    }

    public function getLastEventAt(): ?\DateTimeImmutable
    {
        return $this->lastEventAt;
    }

    public function setLastEventAt(?\DateTimeImmutable $at): static
    {
        $this->lastEventAt = $at;

        return $this;
    }

    public function getSyncedAt(): ?\DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function markSynced(): static
    {
        $this->syncedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSyncAttempts(): int
    {
        return $this->syncAttempts;
    }

    public function nextSyncAttempt(): static
    {
        ++$this->syncAttempts;

        return $this;
    }
}
