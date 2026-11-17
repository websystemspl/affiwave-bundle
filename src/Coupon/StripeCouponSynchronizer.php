<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Coupon;

use AffiWave\Bundle\Config\Settings;
use AffiWave\Bundle\Entity\AffiWaveCoupon;
use AffiWave\Bundle\Repository\AffiWaveCouponRepository;
use AffiWave\Bundle\Stripe\StripeApiException;
use AffiWave\Bundle\Stripe\StripeGatewayInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Brings Stripe to the state of an AffiWave partner coupon: a Stripe coupon plus a promotion code with the same
 * code, so a customer holding a partner's code gets the discount in Checkout.
 *
 * Input is the full coupon state - from the `coupon.*` webhook or `GET /api/coupon_codes`. It is compared with the
 * stored {@see AffiWaveCoupon} mapping, so a repeated event and the fallback sync are idempotent.
 *
 * - Code, amount, type, currency, usage limit and expiry are immutable in Stripe: any change = the old promotion
 *   code is deactivated, a new coupon and promotion code are created. A status change only toggles the code.
 * - `fixed` amounts arrive GROSS. With `coupons.vat_rate` > 0 they are turned into the net `amount_off`
 *   (Stripe takes the discount before adding VAT). `coupons.currencies` limits the currencies accepted for fixed
 *   coupons (empty = any); a fixed coupon works in its own currency only.
 * - Refusals a retry will not fix (code format, currency, clash with a manual code, Stripe 4xx) go to `sync_error`
 *   and the log without an exception, so AffiWave does not retry forever. A Stripe outage (5xx, 429, network)
 *   throws, so the webhook answers 5xx and AffiWave retries.
 */
class StripeCouponSynchronizer
{
    /** Characters Stripe accepts in a promotion code. */
    private const CODE_PATTERN = '/^[A-Za-z0-9-]{1,500}$/';

    public function __construct(
        private readonly AffiWaveCouponRepository $coupons,
        private readonly StripeGatewayInterface $stripe,
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param \DateTimeImmutable|null $eventAt when AffiWave recorded this state - older than the applied one is skipped
     *
     * @throws StripeApiException Stripe outage worth retrying
     */
    public function apply(CouponState $state, ?\DateTimeImmutable $eventAt = null): CouponSyncResult
    {
        if (!$this->settings->couponsEnabled) {
            return CouponSyncResult::Disabled;
        }

        $mapping = $this->coupons->findOneByAffiwaveCouponId($state->id);
        // An older event after a newer one is skipped - unless the last attempt ended in an error.
        if (null !== $mapping && null === $mapping->getSyncError() && null !== $eventAt
            && null !== $mapping->getLastEventAt() && $eventAt < $mapping->getLastEventAt()) {
            return CouponSyncResult::Stale;
        }

        $isNew = null === $mapping;
        $mapping ??= new AffiWaveCoupon($state->id);
        $hash = $this->conditionsHash($state);
        $shapeChanged = $isNew || null === $mapping->getStripePromotionCodeId() || $mapping->getConditionsHash() !== $hash;
        $wantActive = $state->isActiveAt(new \DateTimeImmutable());

        $mapping->setCode($state->code)->setExpiresAt($state->expiresAt);
        if (null !== $eventAt && (null === $mapping->getLastEventAt() || $eventAt > $mapping->getLastEventAt())) {
            $mapping->setLastEventAt($eventAt);
        }
        $this->em->persist($mapping);

        try {
            if ($shapeChanged) {
                $result = $this->replaceInStripe($mapping, $state, $hash, $wantActive, $isNew);
            } elseif ($wantActive !== $mapping->isActive()) {
                $this->stripe->updatePromotionCode((string) $mapping->getStripePromotionCodeId(), ['active' => $wantActive]);
                $mapping->setActive($wantActive)->setSyncError(null);
                $result = CouponSyncResult::Status;
            } else {
                $result = CouponSyncResult::Unchanged;
            }
        } catch (StripeApiException $e) {
            if (!$e->isRejected()) {
                $this->forget($mapping, $isNew);
                throw $e;
            }
            $result = $this->fail($mapping, 'stripe: '.$e->getMessage());
        } catch (\Throwable $e) {
            $this->forget($mapping, $isNew);
            throw $e;
        }

        if (CouponSyncResult::Unchanged !== $result && CouponSyncResult::Error !== $result) {
            $mapping->markSynced();
        }
        $this->em->flush();

        return $result;
    }

    private function replaceInStripe(AffiWaveCoupon $mapping, CouponState $state, string $hash, bool $wantActive, bool $isNew): CouponSyncResult
    {
        // The old code (other amount / code / limit) must stop working - also when the new one cannot be created.
        $this->deactivateCurrent($mapping);
        $problem = $this->validate($state);
        if (null !== $problem) {
            return $this->fail($mapping, $problem);
        }
        $couponPayload = $this->couponPayload($state);

        // Active codes with the same code: ours (left over from an interrupted attempt) are disabled, a foreign
        // one (created by hand in Stripe) blocks a new active code - Stripe would reject it anyway.
        foreach ($this->stripe->listActivePromotionCodes($state->code) as $existing) {
            $existingId = (string) ($existing['id'] ?? '');
            $metadata = \is_array($existing['metadata'] ?? null) ? $existing['metadata'] : [];
            if ((string) $state->id === (string) ($metadata['affiwave_coupon_id'] ?? '')) {
                $this->stripe->updatePromotionCode($existingId, ['active' => false]);
            } elseif ($wantActive) {
                return $this->fail($mapping, \sprintf('code_conflict: active promotion code %s in Stripe does not come from AffiWave', $existingId));
            }
        }

        $coupon = $this->stripe->createCoupon($couponPayload);
        $promotionCode = $this->stripe->createPromotionCode($this->promotionCodePayload($state, (string) $coupon['id'], $wantActive));

        $mapping->setStripeIds((string) $coupon['id'], (string) $promotionCode['id'])
            ->setConditionsHash($hash)
            ->setActive((bool) ($promotionCode['active'] ?? $wantActive))
            ->setSyncError(null);

        return $isNew ? CouponSyncResult::Created : CouponSyncResult::Replaced;
    }

    /**
     * Failure half-way (Stripe down): a new mapping must not stay in the Unit of Work, or the next flush in this
     * process would store it without Stripe IDs. An existing one stays - the retried event fixes it.
     */
    private function forget(AffiWaveCoupon $mapping, bool $isNew): void
    {
        if ($isNew) {
            $this->em->detach($mapping);
        }
    }

    private function deactivateCurrent(AffiWaveCoupon $mapping): void
    {
        if (null !== $mapping->getStripePromotionCodeId() && $mapping->isActive()) {
            $this->stripe->updatePromotionCode($mapping->getStripePromotionCodeId(), ['active' => false]);
        }
        $mapping->setActive(false);
    }

    private function fail(AffiWaveCoupon $mapping, string $reason): CouponSyncResult
    {
        // No hash: the next event or sync run tries again from scratch.
        $mapping->setSyncError($reason)->setConditionsHash(null);
        $this->logger->warning('[AffiWave] coupon not synced to Stripe', [
            'affiwave_coupon_id' => $mapping->getAffiwaveCouponId(),
            'reason' => $reason,
        ]);

        return CouponSyncResult::Error;
    }

    private function validate(CouponState $state): ?string
    {
        if (1 !== preg_match(self::CODE_PATTERN, $state->code)) {
            return 'invalid_code: Stripe accepts letters, digits and hyphens only';
        }
        if ((float) $state->value <= 0) {
            return 'invalid_value: discount must be greater than zero';
        }
        if (CouponState::TYPE_PERCENTAGE === $state->type && (float) $state->value > 100) {
            return 'invalid_value: percentage discount over 100%';
        }
        if (CouponState::TYPE_FIXED === $state->type) {
            if ('' === $state->currency) {
                return 'invalid_currency: fixed coupon without a currency';
            }
            $allowed = $this->settings->couponCurrencies;
            if ([] !== $allowed && !\in_array($state->currency, $allowed, true)) {
                return \sprintf('unsupported_currency: coupon in %s, accepted: %s', $state->currency, implode('/', $allowed));
            }
        }
        if (!\in_array($this->settings->couponDuration, Settings::DURATIONS, true)) {
            return \sprintf('invalid_config: affiwave.coupons.duration=%s', $this->settings->couponDuration);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function couponPayload(CouponState $state): array
    {
        $payload = [
            'name' => mb_substr($state->code, 0, 40),
            'duration' => $this->settings->couponDuration,
            'metadata' => ['affiwave_coupon_id' => (string) $state->id],
        ];
        if ('repeating' === $this->settings->couponDuration) {
            $payload['duration_in_months'] = max(1, $this->settings->couponDurationMonths);
        }
        if (CouponState::TYPE_FIXED === $state->type) {
            $payload['amount_off'] = $this->netMinor($state->value);
            $payload['currency'] = strtolower($state->currency);
        } else {
            $payload['percent_off'] = (float) $state->value;
        }

        return $payload;
    }

    /** Gross discount in main units -> net amount in minor units (VAT rate 0 = unchanged). */
    private function netMinor(string $gross): int
    {
        $net = (float) $gross * 100 / (1 + max(0.0, $this->settings->couponVatRate) / 100);

        return max(1, (int) round($net));
    }

    /**
     * @return array<string, mixed>
     */
    private function promotionCodePayload(CouponState $state, string $stripeCouponId, bool $active): array
    {
        $payload = [
            'promotion' => ['type' => 'coupon', 'coupon' => $stripeCouponId],
            'code' => $state->code,
            'active' => $active,
            'metadata' => ['affiwave_coupon_id' => (string) $state->id],
        ];
        // Limit 0 or none = unlimited (0 does not mean "blocked" - that is what the status is for).
        if (null !== $state->usageLimit && $state->usageLimit > 0) {
            $payload['max_redemptions'] = $state->usageLimit;
        }
        // Stripe rejects a date in the past - an expired code is created inactive anyway.
        if (null !== $state->expiresAt && $state->expiresAt > new \DateTimeImmutable()) {
            $payload['expires_at'] = $state->expiresAt->getTimestamp();
        }

        return $payload;
    }

    /** Everything that is immutable in Stripe, plus the bundle settings that shape the Stripe coupon. */
    private function conditionsHash(CouponState $state): string
    {
        return hash('sha256', json_encode([
            $state->code,
            $state->type,
            $state->value,
            $state->currency,
            $state->usageLimit,
            $state->expiresAt?->getTimestamp(),
            $this->settings->couponDuration,
            $this->settings->couponDurationMonths,
            $this->settings->couponVatRate,
        ], \JSON_THROW_ON_ERROR));
    }
}
