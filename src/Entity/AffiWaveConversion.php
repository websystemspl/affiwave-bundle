<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One conversion handed to AffiWave. `external_order_id` is the idempotency key on both sides.
 */
#[ORM\Entity]
#[ORM\Table(name: 'affiwave_conversion')]
#[ORM\UniqueConstraint(name: 'uniq_affiwave_conversion_order', columns: ['external_order_id'])]
#[ORM\Index(name: 'idx_affiwave_conversion_status', columns: ['status'])]
class AffiWaveConversion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id')]
    private ?int $id = null; // @phpstan-ignore property.unusedType

    #[ORM\Column(name: 'status', enumType: ConversionStatus::class, length: 16)]
    private ConversionStatus $status = ConversionStatus::Pending;

    #[ORM\Column(name: 'attempts')]
    private int $attempts = 0;

    #[ORM\Column(name: 'last_error', type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(name: 'conversion_id', length: 64, nullable: true)]
    private ?string $conversionId = null;

    #[ORM\Column(name: 'commission_amount', length: 32, nullable: true)]
    private ?string $commissionAmount = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'reported_at', nullable: true)]
    private ?\DateTimeImmutable $reportedAt = null;

    public function __construct(
        #[ORM\Column(name: 'external_order_id', length: 255)]
        private string $externalOrderId,
        #[ORM\Column(name: 'amount_minor')]
        private int $amountMinor,
        #[ORM\Column(name: 'currency', length: 3)]
        private string $currency,
        #[ORM\Column(name: 'click_token', length: 32, nullable: true)]
        private ?string $clickToken,
        #[ORM\Column(name: 'coupon_code', length: 500, nullable: true)]
        private ?string $couponCode,
        #[ORM\Column(name: 'occurred_at')]
        private \DateTimeImmutable $occurredAt,
        #[ORM\Column(name: 'customer_email', length: 255, nullable: true)]
        private ?string $customerEmail = null,
        #[ORM\Column(name: 'customer_ip', length: 45, nullable: true)]
        private ?string $customerIp = null,
        #[ORM\Column(name: 'customer_user_agent', length: 512, nullable: true)]
        private ?string $customerUserAgent = null,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExternalOrderId(): string
    {
        return $this->externalOrderId;
    }

    public function getAmountMinor(): int
    {
        return $this->amountMinor;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getClickToken(): ?string
    {
        return $this->clickToken;
    }

    public function getCouponCode(): ?string
    {
        return $this->couponCode;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getCustomerEmail(): ?string
    {
        return $this->customerEmail;
    }

    public function getCustomerIp(): ?string
    {
        return $this->customerIp;
    }

    public function getCustomerUserAgent(): ?string
    {
        return $this->customerUserAgent;
    }

    public function getStatus(): ConversionStatus
    {
        return $this->status;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getConversionId(): ?string
    {
        return $this->conversionId;
    }

    public function getCommissionAmount(): ?string
    {
        return $this->commissionAmount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReportedAt(): ?\DateTimeImmutable
    {
        return $this->reportedAt;
    }

    public function isDelivered(): bool
    {
        return $this->status->isDelivered();
    }

    public function startAttempt(): void
    {
        ++$this->attempts;
    }

    public function markDelivered(bool $duplicate, ?string $conversionId, ?string $commissionAmount): void
    {
        $this->status = $duplicate ? ConversionStatus::Duplicate : ConversionStatus::Reported;
        $this->conversionId = $conversionId;
        $this->commissionAmount = $commissionAmount;
        $this->lastError = null;
        $this->reportedAt = new \DateTimeImmutable();
    }

    public function markRejected(string $error): void
    {
        $this->status = ConversionStatus::Rejected;
        $this->lastError = mb_substr($error, 0, 1000);
    }

    public function markFailed(string $error): void
    {
        $this->status = ConversionStatus::Failed;
        $this->lastError = mb_substr($error, 0, 1000);
    }
}
