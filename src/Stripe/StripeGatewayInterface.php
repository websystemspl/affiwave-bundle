<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Stripe;

/**
 * The few Stripe calls the bundle makes, as plain arrays - a seam for tests and for applications that keep their own
 * Stripe client. Failures must surface as {@see StripeApiException}.
 */
interface StripeGatewayInterface
{
    /** @return array<string, mixed> invoice with `discounts` expanded */
    public function retrieveInvoice(string $invoiceId): array;

    /** @return array<string, mixed> session with `total_details.breakdown` expanded */
    public function retrieveCheckoutSession(string $sessionId): array;

    /** @return array<string, mixed> */
    public function retrievePromotionCode(string $promotionCodeId): array;

    /**
     * @param array<string, mixed> $params
     * @param string|null          $idempotencyKey Stripe `Idempotency-Key`; implementations should pass it on
     *
     * @return array<string, mixed>
     */
    public function createCoupon(array $params, ?string $idempotencyKey = null): array;

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function createPromotionCode(array $params, ?string $idempotencyKey = null): array;

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function updatePromotionCode(string $id, array $params): array;

    /**
     * Active promotion codes with exactly this code (Stripe matches codes case-insensitively).
     *
     * @return list<array<string, mixed>>
     */
    public function listActivePromotionCodes(string $code): array;
}
