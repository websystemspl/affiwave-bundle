<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Tests\Functional;

use AffiWave\Bundle\Coupon\CouponState;
use AffiWave\Bundle\Coupon\StripeCouponSynchronizer;
use AffiWave\Bundle\Stripe\DiscountCodeResolver;
use AffiWave\Bundle\Tests\BundleTestCase;

final class DiscountCodeResolverTest extends BundleTestCase
{
    private DiscountCodeResolver $resolver;

    protected function setUp(): void
    {
        $this->boot(['api_url' => 'https://affiwave.example', 'api_key' => 'k', 'coupons' => ['enabled' => true]]);
        $this->resolver = $this->service(DiscountCodeResolver::class);
        // promo_1 = AffiWave code PARTNER10, mirrored by the synchroniser.
        $this->service(StripeCouponSynchronizer::class)->apply(CouponState::fromWebhook([
            'coupon_id' => 42, 'code' => 'PARTNER10', 'discount_type' => 'percentage', 'discount_value' => '10',
            'currency' => 'PLN', 'status' => 'active',
        ]));
        $this->stripe()->calls = [];
    }

    public function testExpandedInvoiceDiscountResolvesLocally(): void
    {
        $invoice = ['id' => 'in_1', 'discounts' => [['promotion_code' => 'promo_1', 'source' => ['coupon' => 'co_1']]]];

        self::assertSame('PARTNER10', $this->resolver->couponCodeFromInvoice($invoice));
        self::assertSame([], $this->stripe()->calls);
    }

    public function testInvoiceWithDiscountIdsOnlyIsFetchedThroughTheGateway(): void
    {
        $this->stripe()->invoices['in_2'] = ['id' => 'in_2', 'discounts' => [['promotion_code' => 'promo_1']]];

        self::assertSame('PARTNER10', $this->resolver->couponCodeFromInvoice(['id' => 'in_2', 'discounts' => ['di_1']]));
        self::assertSame([['id' => 'in_2']], $this->stripe()->callsTo('retrieveInvoice'));
    }

    public function testForeignPromotionCodeIsNotAnAffiWaveCoupon(): void
    {
        $this->stripe()->promos['promo_manual'] = ['id' => 'promo_manual', 'code' => 'SPRING', 'metadata' => []];

        self::assertNull($this->resolver->couponCodeFromInvoice(['id' => 'in_3', 'discounts' => [['promotion_code' => 'promo_manual']]]));
        self::assertNull($this->resolver->couponCodeFromInvoice(['id' => 'in_4', 'discounts' => [['coupon' => ['id' => 'co_x']]]]));
        self::assertNull($this->resolver->couponCodeFromInvoice(['id' => 'in_5', 'discounts' => []]));
    }

    public function testPromotionCodeFromAffiWaveMetadataIsRecognisedViaGateway(): void
    {
        $this->stripe()->promos['promo_other'] = ['id' => 'promo_other', 'code' => 'OTHER5', 'metadata' => ['affiwave_coupon_id' => '77']];

        self::assertSame('OTHER5', $this->resolver->couponCodeFromInvoice(['id' => 'in_6', 'discounts' => [['promotion_code' => 'promo_other']]]));
        self::assertSame('OTHER5', $this->resolver->couponCodeFromInvoice(['id' => 'in_7', 'discounts' => [['promotion_code' => ['id' => 'promo_other', 'code' => 'OTHER5', 'metadata' => ['affiwave_coupon_id' => '77']]]]]));
    }

    public function testGatewayFailureDoesNotThrow(): void
    {
        self::assertNull($this->resolver->couponCodeFromInvoice(['id' => 'in_missing', 'discounts' => ['di_1']]));
    }

    public function testCheckoutSessionWithBreakdown(): void
    {
        $session = ['id' => 'cs_1', 'total_details' => ['amount_discount' => 1000, 'breakdown' => ['discounts' => [['amount' => 1000, 'discount' => ['promotion_code' => 'promo_1']]]]]];

        self::assertSame('PARTNER10', $this->resolver->couponCodeFromSession($session));
    }

    public function testCheckoutSessionWithoutBreakdownIsFetched(): void
    {
        $this->stripe()->sessions['cs_2'] = ['id' => 'cs_2', 'total_details' => ['amount_discount' => 500, 'breakdown' => ['discounts' => [['discount' => ['promotion_code' => 'promo_1']]]]]];

        self::assertSame('PARTNER10', $this->resolver->couponCodeFromSession(['id' => 'cs_2', 'total_details' => ['amount_discount' => 500]]));
        self::assertCount(1, $this->stripe()->callsTo('retrieveCheckoutSession'));
    }

    public function testUndiscountedSessionCostsNoApiCall(): void
    {
        self::assertNull($this->resolver->couponCodeFromSession(['id' => 'cs_3', 'total_details' => ['amount_discount' => 0]]));
        self::assertSame([], $this->stripe()->calls);
    }
}
