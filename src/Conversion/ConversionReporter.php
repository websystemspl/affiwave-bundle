<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Conversion;

use AffiWave\Bundle\Api\AffiWaveClient;
use AffiWave\Bundle\Api\ApiResult;
use AffiWave\Bundle\Attribution\ClickTokenResolver;
use AffiWave\Bundle\Entity\AffiWaveConversion;
use AffiWave\Bundle\Entity\ConversionStatus;
use AffiWave\Bundle\Money\Currency;
use AffiWave\Bundle\Repository\AffiWaveConversionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Reports paid orders to AffiWave (`POST /api/conversion`).
 *
 * - Never throws: call it from a payment webhook without risking a retry storm. Problems are logged and stored.
 * - Idempotent: every conversion is stored under its `externalOrderId` (unique). A delivered one is not sent again,
 *   and AffiWave itself answers 200 `duplicate` to a repeat.
 * - Nothing to attribute (no click token and no coupon) or amount <= 0: the result is `Skipped` and nothing is stored.
 * - A click token wins over a coupon: then only `click_token` is sent.
 * - Failures a retry can fix (5xx, 429, timeout, 401/403) leave the row `failed` for `affiwave:conversions:retry`;
 *   400/422 answers are final (`rejected`). After `conversions.max_attempts` failed attempts the row is given up
 *   (`rejected`, reason in `last_error`).
 * - A refund marks the stored row `refunded`; a conversion that was never delivered is only marked locally, and is
 *   never sent afterwards.
 */
class ConversionReporter
{
    private readonly int $maxAttempts;

    public function __construct(
        private readonly AffiWaveClient $client,
        private readonly EntityManagerInterface $em,
        private readonly AffiWaveConversionRepository $conversions,
        private readonly LoggerInterface $logger = new NullLogger(),
        int|string $maxAttempts = 20,
    ) {
        $this->maxAttempts = max(1, (int) $maxAttempts);
    }

    public function isEnabled(): bool
    {
        return $this->client->isEnabled();
    }

    public function report(Conversion $conversion): ReportResult
    {
        if (!$this->client->isEnabled()) {
            return new ReportResult(ReportOutcome::Disabled);
        }

        $token = ClickTokenResolver::validToken($conversion->clickToken);
        $coupon = null !== $conversion->couponCode && '' !== trim($conversion->couponCode) ? trim($conversion->couponCode) : null;
        $orderId = trim($conversion->externalOrderId);
        if ('' === $orderId || $conversion->amountMinor <= 0) {
            return new ReportResult(ReportOutcome::Skipped, message: 'non_positive_amount_or_empty_order_id');
        }
        if (null === $token && null === $coupon) {
            return new ReportResult(ReportOutcome::Skipped, message: 'no_attribution');
        }

        try {
            $entity = $this->conversions->findOneByExternalOrderId($orderId);
            if (null === $entity) {
                $entity = new AffiWaveConversion(
                    $orderId,
                    $conversion->amountMinor,
                    strtoupper(trim($conversion->currency)),
                    $token,
                    null !== $token ? null : $coupon,
                    $conversion->occurredAt,
                    self::clip($conversion->customerEmail, 255),
                    self::clip($conversion->customerIp, 45),
                    self::clip($conversion->customerUserAgent, 512),
                );
                $this->em->persist($entity);
                $this->em->flush();
            }

            return $this->deliver($entity);
        } catch (\Throwable $e) {
            $this->logger->error('[AffiWave] storing conversion failed', ['external_order_id' => $orderId, 'error' => $e->getMessage()]);

            return new ReportResult(ReportOutcome::Failed, message: $e->getMessage());
        }
    }

    /**
     * Send a stored conversion (used by `affiwave:conversions:retry`). A delivered or rejected one is left alone.
     */
    public function deliver(AffiWaveConversion $entity): ReportResult
    {
        if (!$this->client->isEnabled()) {
            return new ReportResult(ReportOutcome::Disabled, $entity);
        }
        if ($entity->isDelivered()) {
            return new ReportResult(ReportOutcome::AlreadyReported, $entity);
        }
        if (ConversionStatus::Refunded === $entity->getStatus()) {
            return new ReportResult(ReportOutcome::Refunded, $entity);
        }
        if (ConversionStatus::Rejected === $entity->getStatus()) {
            return new ReportResult(ReportOutcome::Rejected, $entity, $entity->getLastError());
        }
        if ($entity->getAttempts() >= $this->maxAttempts) {
            $entity->markRejected(\sprintf('max_attempts_exceeded: gave up after %d attempts; last error: %s', $entity->getAttempts(), (string) $entity->getLastError()));
            $this->flushQuietly();

            return new ReportResult(ReportOutcome::Rejected, $entity, $entity->getLastError());
        }

        return $this->send($entity);
    }

    /**
     * `POST /api/conversion/refund` for an order reported earlier. Never throws.
     *
     * The stored row becomes `refunded`. A conversion AffiWave never got (pending, failed or rejected) is only marked
     * locally - nothing is sent now and it is never sent later.
     */
    public function refund(string $externalOrderId): ReportResult
    {
        if (!$this->client->isEnabled()) {
            return new ReportResult(ReportOutcome::Disabled);
        }

        $entity = null;
        try {
            $entity = $this->conversions->findOneByExternalOrderId($externalOrderId);
            if (null !== $entity) {
                if (ConversionStatus::Refunded === $entity->getStatus()) {
                    return new ReportResult(ReportOutcome::Refunded, $entity);
                }
                if (!$entity->isDelivered()) {
                    $entity->markRefunded();
                    $this->em->flush();

                    return new ReportResult(ReportOutcome::Refunded, $entity, 'never_delivered');
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('[AffiWave] reading conversion for refund failed', ['external_order_id' => $externalOrderId, 'error' => $e->getMessage()]);

            return new ReportResult(ReportOutcome::Failed, message: $e->getMessage());
        }

        try {
            $result = $this->client->refund($externalOrderId);
        } catch (\Throwable $e) {
            $this->logger->error('[AffiWave] refund failed', ['external_order_id' => $externalOrderId, 'error' => $e->getMessage()]);

            return new ReportResult(ReportOutcome::Failed, $entity, $e->getMessage());
        }
        if ($result->isSuccess()) {
            $entity?->markRefunded();
            $this->flushQuietly();

            return new ReportResult(ReportOutcome::Refunded, $entity, httpStatus: $result->status);
        }
        $this->logger->error('[AffiWave] refund not accepted', ['external_order_id' => $externalOrderId, 'status' => $result->status, 'error' => $result->errorCode()]);

        return new ReportResult($this->isRetryable($result) ? ReportOutcome::Failed : ReportOutcome::Rejected, $entity, $result->summary(), $result->status);
    }

    private function send(AffiWaveConversion $entity): ReportResult
    {
        $entity->startAttempt();
        try {
            $result = $this->client->createConversion($this->payload($entity));
        } catch (\Throwable $e) {
            $this->logger->error('[AffiWave] conversion report failed', ['external_order_id' => $entity->getExternalOrderId(), 'error' => $e->getMessage()]);
            $entity->markFailed($e->getMessage());

            return $this->failed($entity, $e->getMessage(), null);
        }

        if ($result->isSuccess()) {
            $duplicate = true === ($result->body['duplicate'] ?? false);
            $entity->markDelivered($duplicate, self::scalarString($result->body['conversion_id'] ?? null), self::scalarString($result->body['commission_amount'] ?? null));
            $this->flushQuietly();

            return new ReportResult($duplicate ? ReportOutcome::Duplicate : ReportOutcome::Reported, $entity, httpStatus: $result->status);
        }

        $this->logger->error('[AffiWave] conversion not accepted', [
            'external_order_id' => $entity->getExternalOrderId(),
            'status' => $result->status,
            'error' => $result->errorCode(),
        ]);
        $retryable = $this->isRetryable($result);
        if ($retryable) {
            $entity->markFailed($result->summary());

            return $this->failed($entity, $result->summary(), $result->status);
        }
        $entity->markRejected($result->summary());
        $this->flushQuietly();

        return new ReportResult(ReportOutcome::Rejected, $entity, $result->summary(), $result->status);
    }

    /** A retryable failure was just recorded: give the row up once it used all its attempts. */
    private function failed(AffiWaveConversion $entity, string $message, ?int $status): ReportResult
    {
        if ($entity->getAttempts() >= $this->maxAttempts) {
            $entity->markRejected(\sprintf('max_attempts_exceeded: gave up after %d attempts; last error: %s', $entity->getAttempts(), $message));
            $this->flushQuietly();

            return new ReportResult(ReportOutcome::Rejected, $entity, $entity->getLastError(), $status);
        }
        $this->flushQuietly();

        return new ReportResult(ReportOutcome::Failed, $entity, $message, $status);
    }

    /** 5xx, 429, 408 and 401/403 (key, plan or programme state - fixable without code changes) are retried. */
    private function isRetryable(ApiResult $result): bool
    {
        return $result->isTransient() || \in_array($result->status, [401, 403], true);
    }

    /** @return array<string, string> */
    public function payload(AffiWaveConversion $entity): array
    {
        $payload = null !== $entity->getClickToken()
            ? ['click_token' => $entity->getClickToken()]
            : ['coupon' => (string) $entity->getCouponCode()];
        $payload += [
            'amount' => self::formatAmount($entity->getAmountMinor(), $entity->getCurrency()),
            'currency' => $entity->getCurrency(),
            'external_order_id' => $entity->getExternalOrderId(),
            'occurred_at' => $entity->getOccurredAt()->format(\DATE_ATOM),
        ];
        // AffiWave answers 400 to a malformed e-mail or IP, which would reject the whole conversion: omit them.
        $optional = [
            'customer_email' => self::validEmail($entity->getCustomerEmail()),
            'customer_ip' => self::validIp($entity->getCustomerIp()),
            'customer_user_agent' => $entity->getCustomerUserAgent(),
        ];
        foreach ($optional as $key => $value) {
            if (null !== $value && '' !== $value) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    /**
     * 9900 -> "99.00": main units with two decimals, what the API expects. Zero-decimal currencies (JPY: 1000 ->
     * "1000.00") and three-decimal ones (KWD: 12345 -> "12.35", rounded) are handled by {@see Currency}.
     */
    public static function formatAmount(int $minor, string $currency = ''): string
    {
        return Currency::toApiAmount($minor, $currency);
    }

    private static function validEmail(?string $email): ?string
    {
        $email = null === $email ? '' : trim($email);

        return '' !== $email && false !== filter_var($email, \FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /** Accepts a lone IP or an `X-Forwarded-For` style list (first valid address wins). */
    private static function validIp(?string $ip): ?string
    {
        foreach (explode(',', (string) $ip) as $candidate) {
            $candidate = trim($candidate);
            if ('' !== $candidate && false !== filter_var($candidate, \FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        return null;
    }

    private function flushQuietly(): void
    {
        try {
            $this->em->flush();
        } catch (\Throwable $e) {
            // The row stays as it was - the retry command picks it up.
            $this->logger->error('[AffiWave] saving conversion state failed', ['error' => $e->getMessage()]);
        }
    }

    private static function clip(?string $value, int $max): ?string
    {
        $value = null === $value ? '' : trim($value);

        return '' === $value ? null : mb_substr($value, 0, $max);
    }

    private static function scalarString(mixed $value): ?string
    {
        return \is_scalar($value) ? (string) $value : null;
    }
}
