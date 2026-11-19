<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Tests\Functional;

use AffiWave\Bundle\Coupon\CouponChangedEvent;
use AffiWave\Bundle\Entity\AffiWaveCoupon;
use AffiWave\Bundle\Tests\BundleTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class WebhookControllerTest extends BundleTestCase
{
    private const SECRET = 'whsec_functional';

    /** @param array<string, mixed> $config */
    private function bootWebhook(array $config = []): void
    {
        $this->boot($config + [
            'api_url' => 'https://affiwave.example', 'api_key' => 'k', 'webhook_secret' => self::SECRET,
            'coupons' => ['enabled' => true],
        ]);
    }

    /** @param array<string, mixed> $data */
    private static function eventBody(array $data = [], string $type = 'coupon.created'): string
    {
        return json_encode(['event' => $type, 'created_at' => '2026-11-01T10:00:00+00:00', 'data' => $data + [
            'coupon_id' => 42, 'code' => 'PARTNER10', 'discount_type' => 'percentage', 'discount_value' => '10.00',
            'amount_basis' => 'gross', 'currency' => 'PLN', 'status' => 'active', 'usage_limit' => null, 'expires_at' => null,
            'campaign_id' => 7, 'program_id' => 3,
        ]], \JSON_THROW_ON_ERROR);
    }

    private function post(string $body, ?string $signature = null, string $secret = self::SECRET): Response
    {
        $signature ??= \sprintf('t=%d,v1=%s', $t = time(), hash_hmac('sha256', $t.'.'.$body, $secret));
        $request = Request::create('/affiwave/webhook', 'POST', content: $body, server: ['HTTP_X_AFFIWAVE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json']);

        return $this->kernel->handle($request);
    }

    /** @return array<string, mixed> */
    private static function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    public function testValidCouponEventCreatesThePromotionCode(): void
    {
        $this->bootWebhook();

        $response = $this->post(self::eventBody());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['received' => true, 'handled' => true, 'result' => 'created'], self::json($response));
        self::assertSame('PARTNER10', $this->stripe()->callsTo('createPromotionCode')[0]['code']);
    }

    public function testBadSignatureAndBadBodyAreRejectedWith400(): void
    {
        $this->bootWebhook();

        self::assertSame(400, $this->post(self::eventBody(), 't=1,v1=ab')->getStatusCode());
        self::assertSame(400, $this->post(self::eventBody(), 'nonsense')->getStatusCode());
        self::assertSame(400, $this->post(self::eventBody(), secret: 'wrong')->getStatusCode());
        self::assertSame(400, $this->post('not json')->getStatusCode());
        self::assertSame(400, $this->post(json_encode(['event' => 'coupon.created']))->getStatusCode());
        self::assertSame(400, $this->post(self::eventBody(['discount_type' => 'weird']))->getStatusCode());
        self::assertSame([], $this->stripe()->calls);
    }

    public function testMissingSecretAnswers503(): void
    {
        $this->bootWebhook(['webhook_secret' => null]);

        self::assertSame(503, $this->post(self::eventBody(), 't=1,v1=ab')->getStatusCode());
    }

    public function testEventOfAnyProgramIsHandled(): void
    {
        $this->bootWebhook();

        self::assertTrue(self::json($this->post(self::eventBody(['program_id' => 99])))['handled']);
    }

    public function testOtherEventsAreAcknowledgedAndIgnored(): void
    {
        $this->bootWebhook();

        $response = $this->post(json_encode(['event' => 'conversion.created', 'data' => []], \JSON_THROW_ON_ERROR));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['received' => true, 'handled' => false], self::json($response));
    }

    public function testInvalidCodeIsAcceptedWith200AndStoredAsSyncError(): void
    {
        $this->bootWebhook();

        $response = $this->post(self::eventBody(['code' => 'ZLY KOD']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('error', self::json($response)['result']);
        $this->em->clear();
        self::assertStringStartsWith('invalid_code', (string) $this->em->getRepository(AffiWaveCoupon::class)->findOneBy([])?->getSyncError());
    }

    public function testStripeOutageAnswers500SoAffiWaveRetries(): void
    {
        $this->bootWebhook();
        $this->stripe()->failWith = 503;

        self::assertSame(500, $this->post(self::eventBody())->getStatusCode());
    }

    public function testEventIsDispatchedToApplicationListeners(): void
    {
        $this->bootWebhook(['coupons' => ['enabled' => false]]);
        $seen = [];
        $this->service('event_dispatcher')->addListener(CouponChangedEvent::class, static function (CouponChangedEvent $e) use (&$seen): void {
            $seen[] = [$e->eventType, $e->state->code, $e->state->programId, $e->eventAt?->format('c')];
        });

        $response = $this->post(self::eventBody(type: 'coupon.updated'));

        self::assertSame(['received' => true, 'handled' => true, 'result' => null], self::json($response));
        self::assertSame([['coupon.updated', 'PARTNER10', 3, '2026-11-01T10:00:00+00:00']], $seen);
        self::assertSame([], $this->stripe()->calls);
    }

    public function testListenerExceptionAnswers500(): void
    {
        $this->bootWebhook();
        $this->service('event_dispatcher')->addListener(CouponChangedEvent::class, static function (): void {
            throw new \RuntimeException('listener down');
        });

        self::assertSame(500, $this->post(self::eventBody())->getStatusCode());
    }

    public function testGetIsNotAllowed(): void
    {
        $this->bootWebhook();

        self::assertSame(405, $this->kernel->handle(Request::create('/affiwave/webhook'))->getStatusCode());
    }
}
