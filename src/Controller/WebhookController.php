<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Controller;

use AffiWave\Bundle\Config\Settings;
use AffiWave\Bundle\Coupon\CouponChangedEvent;
use AffiWave\Bundle\Coupon\CouponState;
use AffiWave\Bundle\Coupon\StripeCouponSynchronizer;
use AffiWave\Bundle\Webhook\WebhookSignatureVerifier;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * AffiWave webhook endpoint. Handles `coupon.created` and `coupon.updated`; any other event is acknowledged and
 * ignored (the endpoint may have more events ticked in AffiWave).
 *
 * Answers: 400 bad signature or body (a retry will not help - see AffiWave's delivery log), 503 no webhook secret,
 * 500 failure worth retrying (Stripe outage, listener exception), 200 accepted - also when the coupon cannot be
 * created for a reason a retry will not fix (`result: error`, reason in `affiwave_coupon.sync_error`) and when
 * the event belongs to another programme than `affiwave.program_id`.
 */
final class WebhookController
{
    private const COUPON_EVENTS = ['coupon.created', 'coupon.updated'];

    public function __construct(
        private readonly WebhookSignatureVerifier $verifier,
        private readonly Settings $settings,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ?StripeCouponSynchronizer $synchronizer = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $event = $this->verifier->verify($request->getContent(), $request->headers->get('X-AffiWave-Signature'));
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $type = (string) ($event['event'] ?? '');
        if (!\in_array($type, self::COUPON_EVENTS, true)) {
            return new JsonResponse(['received' => true, 'handled' => false]);
        }

        $data = $event['data'] ?? null;
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'Missing event data.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $state = CouponState::fromWebhook($data);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $programId = $this->settings->programId();
        if (null !== $programId && null !== $state->programId && $state->programId !== $programId) {
            return new JsonResponse(['received' => true, 'handled' => false, 'ignored' => 'other_program']);
        }

        try {
            $eventAt = $this->eventAt($event);
            $this->dispatcher->dispatch(new CouponChangedEvent($state, $type, $eventAt));
            $result = $this->synchronizer?->apply($state, $eventAt);
        } catch (\Throwable $e) {
            $this->logger->error('[AffiWave] coupon event failed', [
                'event' => $type,
                'affiwave_coupon_id' => $state->id,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'Coupon handling failed, retry later.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse(['received' => true, 'handled' => true, 'result' => $result?->value]);
    }

    /**
     * Envelope `created_at` = when AffiWave queued the event (order of changes, not of deliveries).
     *
     * @param array<string, mixed> $event
     */
    private function eventAt(array $event): ?\DateTimeImmutable
    {
        $raw = $event['created_at'] ?? null;
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
