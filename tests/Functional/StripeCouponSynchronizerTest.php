<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Tests\Functional;

use AffiWave\Bundle\Coupon\CouponState;
use AffiWave\Bundle\Coupon\CouponSyncResult;
use AffiWave\Bundle\Coupon\PromotionCodes;
use AffiWave\Bundle\Coupon\StripeCouponSynchronizer;
use AffiWave\Bundle\Entity\AffiWaveCoupon;
use AffiWave\Bundle\Stripe\StripeApiException;
use AffiWave\Bundle\Tests\BundleTestCase;

final class StripeCouponSynchronizerTest extends BundleTestCase
{
    private StripeCouponSynchronizer $sync;

    /**
     * @param array<string, mixed> $coupons
     */
    private function setUpWith(array $coupons = []): void
    {
        $this->boot(['api_url' => 'https://affiwave.example', 'api_key' => 'k', 'coupons' => ['enabled' => true] + $coupons]);
        $this->sync = $this->service(StripeCouponSynchronizer::class);
    }

    /** @param array<string, mixed> $override */
    private static function state(array $override = []): CouponState
    {
        return CouponState::fromWebhook($override + [
            'coupon_id' => 42, 'code' => 'PARTNER10', 'discount_type' => 'percentage', 'discount_value' => '10.00',
            'currency' => 'PLN', 'status' => 'active', 'usage_limit' => null, 'expires_at' => null, 'program_id' => 3,
        ]);
    }

    private function mapping(int $id = 42): ?AffiWaveCoupon
    {
        $this->em->clear();

        return $this->em->getRepository(AffiWaveCoupon::class)->findOneBy(['affiwaveCouponId' => $id]);
    }

    public function testNewPercentageCouponCreatesCouponAndPromotionCode(): void
    {
        $this->setUpWith();

        $result = $this->sync->apply(self::state(['usage_limit' => 5, 'expires_at' => '2099-01-01T00:00:00+00:00']));

        self::assertSame(CouponSyncResult::Created, $result);
        $coupon = $this->stripe()->callsTo('createCoupon')[0];
        self::assertSame(10.0, $coupon['percent_off']);
        self::assertSame('once', $coupon['duration']);
        self::assertSame(['affiwave_coupon_id' => '42'], $coupon['metadata']);
        $promo = $this->stripe()->callsTo('createPromotionCode')[0];
        self::assertSame('PARTNER10', $promo['code']);
        self::assertSame(['type' => 'coupon', 'coupon' => 'co_1'], $promo['promotion']);
        self::assertTrue($promo['active']);
        self::assertSame(5, $promo['max_redemptions']);
        self::assertSame(4070908800, $promo['expires_at']);

        $row = $this->mapping();
        self::assertSame('co_1', $row?->getStripeCouponId());
        self::assertSame('promo_1', $row->getStripePromotionCodeId());
        self::assertTrue($row->isActive());
        self::assertNull($row->getSyncError());
        self::assertNotNull($row->getConditionsHash());
        self::assertTrue($this->service(PromotionCodes::class)->hasActive());
        self::assertSame('PARTNER10', $this->service(PromotionCodes::class)->codeForStripePromotionCodeId('promo_1'));
    }

    public function testRepeatedEventIsIdempotent(): void
    {
        $this->setUpWith();
        $this->sync->apply(self::state());
        $calls = \count($this->stripe()->calls);

        self::assertSame(CouponSyncResult::Unchanged, $this->sync->apply(self::state()));
        self::assertCount($calls, $this->stripe()->calls);
        self::assertSame(1, $this->em->getRepository(AffiWaveCoupon::class)->count([]));
    }

    public function testChangedConditionsDeactivateTheOldCodeAndCreateANewOne(): void
    {
        $this->setUpWith();
        $this->sync->apply(self::state());

        $result = $this->sync->apply(self::state(['discount_value' => '15.00']));

        self::assertSame(CouponSyncResult::Replaced, $result);
        self::assertSame(['id' => 'promo_1', 'active' => false], $this->stripe()->callsTo('updatePromotionCode')[0]);
        self::assertCount(2, $this->stripe()->callsTo('createCoupon'));
        self::assertSame(15.0, $this->stripe()->callsTo('createCoupon')[1]['percent_off']);
        self::assertFalse($this->stripe()->promos['promo_1']['active']);
        self::assertTrue($this->stripe()->promos['promo_2']['active']);
        self::assertSame('promo_2', $this->mapping()?->getStripePromotionCodeId());
    }

    public function testStatusChangeOnlyTogglesThePromotionCode(): void
    {
        $this->setUpWith();
        $this->sync->apply(self::state());

        self::assertSame(CouponSyncResult::Status, $this->sync->apply(self::state(['status' => 'disabled'])));
        self::assertFalse($this->mapping()?->isActive());
        self::assertFalse($this->service(PromotionCodes::class)->hasActive());
        self::assertCount(1, $this->stripe()->callsTo('createCoupon'));

        self::assertSame(CouponSyncResult::Status, $this->sync->apply(self::state(['status' => 'active'])));
        self::assertTrue($this->mapping()?->isActive());
        self::assertCount(1, $this->stripe()->callsTo('createPromotionCode'));
    }

    public function testInvalidCodeGoesToSyncErrorWithoutCallingStripe(): void
    {
        $this->setUpWith();

        $result = $this->sync->apply(self::state(['code' => 'ZLY KOD!']));

        self::assertSame(CouponSyncResult::Error, $result);
        self::assertSame([], $this->stripe()->calls);
        $row = $this->mapping();
        self::assertStringStartsWith('invalid_code', (string) $row?->getSyncError());
        self::assertFalse($row->isActive());
        self::assertFalse($this->service(PromotionCodes::class)->hasActive());
    }

    public function testRenamedToAnInvalidCodeDeactivatesTheOldPromotionCode(): void
    {
        $this->setUpWith();
        $this->sync->apply(self::state());

        self::assertSame(CouponSyncResult::Error, $this->sync->apply(self::state(['code' => 'ZLY KOD'])));

        self::assertFalse($this->stripe()->promos['promo_1']['active']);
        self::assertFalse($this->service(PromotionCodes::class)->hasActive());
    }

    public function testErrorIsRetriedOnTheNextEvent(): void
    {
        $this->setUpWith();
        $this->sync->apply(self::state(['code' => 'BAD CODE']));

        self::assertSame(CouponSyncResult::Replaced, $this->sync->apply(self::state()));
        self::assertNull($this->mapping()?->getSyncError());
    }

    public function testFixedGrossAmountBecomesNetWithVatRate(): void
    {
        $this->setUpWith(['vat_rate' => 23, 'currencies' => ['PLN', 'EUR']]);

        $this->sync->apply(self::state(['discount_type' => 'fixed', 'discount_value' => '123.00', 'amount_basis' => 'gross']));

        $coupon = $this->stripe()->callsTo('createCoupon')[0];
        self::assertSame(10000, $coupon['amount_off']);
        self::assertSame('pln', $coupon['currency']);
        self::assertArrayNotHasKey('percent_off', $coupon);
    }

    public function testFixedAmountIsKeptWithoutVatRate(): void
    {
        $this->setUpWith();

        $this->sync->apply(self::state(['discount_type' => 'fixed', 'discount_value' => '50.00']));

        self::assertSame(5000, $this->stripe()->callsTo('createCoupon')[0]['amount_off']);
    }

    public function testFixedCouponInAnUnsupportedCurrencyIsRefused(): void
    {
        $this->setUpWith(['currencies' => ['EUR']]);

        self::assertSame(CouponSyncResult::Error, $this->sync->apply(self::state(['discount_type' => 'fixed', 'discount_value' => '5.00'])));
        self::assertStringStartsWith('unsupported_currency', (string) $this->mapping()?->getSyncError());
    }

    public function testRepeatingDurationAndPercentAlias(): void
    {
        $this->setUpWith(['duration' => 'repeating', 'duration_months' => 3]);

        $this->sync->apply(self::state(['discount_type' => 'percent']));

        $coupon = $this->stripe()->callsTo('createCoupon')[0];
        self::assertSame('repeating', $coupon['duration']);
        self::assertSame(3, $coupon['duration_in_months']);
        self::assertSame(10.0, $coupon['percent_off']);
    }

    public function testExpiredCouponIsCreatedInactive(): void
    {
        $this->setUpWith();

        $this->sync->apply(self::state(['expires_at' => '2020-01-01T00:00:00+00:00']));

        $promo = $this->stripe()->callsTo('createPromotionCode')[0];
        self::assertFalse($promo['active']);
        self::assertArrayNotHasKey('expires_at', $promo);
        self::assertFalse($this->service(PromotionCodes::class)->hasActive());
    }

    public function testForeignActiveCodeBlocksTheNewOne(): void
    {
        $this->setUpWith();
        $this->stripe()->promos['promo_manual'] = ['id' => 'promo_manual', 'code' => 'partner10', 'active' => true, 'metadata' => []];

        self::assertSame(CouponSyncResult::Error, $this->sync->apply(self::state()));
        self::assertStringStartsWith('code_conflict', (string) $this->mapping()?->getSyncError());
        self::assertSame([], $this->stripe()->callsTo('createCoupon'));
    }

    public function testStaleEventIsSkipped(): void
    {
        $this->setUpWith();
        $this->sync->apply(self::state(), new \DateTimeImmutable('2026-11-01T12:00:00Z'));

        $result = $this->sync->apply(self::state(['status' => 'disabled']), new \DateTimeImmutable('2026-11-01T11:00:00Z'));

        self::assertSame(CouponSyncResult::Stale, $result);
        self::assertTrue($this->mapping()?->isActive());
    }

    public function testStripeOutageThrowsAndLeavesNoMapping(): void
    {
        $this->setUpWith();
        $this->stripe()->failWith = 503;

        try {
            $this->sync->apply(self::state());
            self::fail('Expected StripeApiException');
        } catch (StripeApiException $e) {
            self::assertFalse($e->isRejected());
        }

        self::assertSame(0, $this->em->getRepository(AffiWaveCoupon::class)->count([]));
    }

    public function testStripeRejectionGoesToSyncError(): void
    {
        $this->setUpWith();
        $this->stripe()->failWith = 400;

        self::assertSame(CouponSyncResult::Error, $this->sync->apply(self::state()));
        self::assertStringStartsWith('stripe:', (string) $this->mapping()?->getSyncError());
    }

    public function testIncompleteStateIsRejectedByTheDto(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CouponState::fromWebhook(['coupon_id' => 1, 'code' => 'X']);
    }

    public function testDisabledSynchronizerDoesNothing(): void
    {
        $this->boot(['coupons' => ['enabled' => false]]);

        self::assertFalse($this->kernel->getContainer()->has(StripeCouponSynchronizer::class));
    }
}
