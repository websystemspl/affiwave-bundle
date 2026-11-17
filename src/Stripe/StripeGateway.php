<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Stripe;

use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * {@see StripeGatewayInterface} on stripe-php (suggested dependency). Promotion codes use the `promotion[coupon]`
 * form of the current Stripe API versions, so keep stripe-php at 19+.
 */
class StripeGateway implements StripeGatewayInterface
{
    private ?StripeClient $client;

    /**
     * @param string|null $apiBase null = api.stripe.com (e.g. http://stripe-mock:12111 in development)
     */
    public function __construct(
        private readonly ?string $secretKey = null,
        private readonly ?string $apiBase = null,
        ?StripeClient $client = null,
    ) {
        $this->client = $client;
    }

    public function isConfigured(): bool
    {
        return null !== $this->client || '' !== trim((string) $this->secretKey);
    }

    public function retrieveInvoice(string $invoiceId): array
    {
        return $this->call(static fn (StripeClient $s) => $s->invoices->retrieve($invoiceId, ['expand' => ['discounts']]));
    }

    public function retrieveCheckoutSession(string $sessionId): array
    {
        return $this->call(static fn (StripeClient $s) => $s->checkout->sessions->retrieve($sessionId, ['expand' => ['total_details.breakdown']]));
    }

    public function retrievePromotionCode(string $promotionCodeId): array
    {
        return $this->call(static fn (StripeClient $s) => $s->promotionCodes->retrieve($promotionCodeId));
    }

    public function createCoupon(array $params): array
    {
        return $this->call(static fn (StripeClient $s) => $s->coupons->create($params));
    }

    public function createPromotionCode(array $params): array
    {
        return $this->call(static fn (StripeClient $s) => $s->promotionCodes->create($params));
    }

    public function updatePromotionCode(string $id, array $params): array
    {
        return $this->call(static fn (StripeClient $s) => $s->promotionCodes->update($id, $params));
    }

    public function listActivePromotionCodes(string $code): array
    {
        $list = $this->call(static fn (StripeClient $s) => $s->promotionCodes->all(['code' => $code, 'active' => true, 'limit' => 10]));

        // Stripe filters by code itself; the check also keeps stray results (e.g. stripe-mock fixtures) out.
        $matching = [];
        foreach ($list['data'] ?? [] as $promotionCode) {
            if (\is_array($promotionCode) && 0 === strcasecmp((string) ($promotionCode['code'] ?? ''), $code)) {
                $matching[] = $promotionCode;
            }
        }

        return $matching;
    }

    /**
     * @param callable(StripeClient): \Stripe\StripeObject $call
     *
     * @return array<string, mixed>
     */
    private function call(callable $call): array
    {
        if (!$this->isConfigured()) {
            throw new StripeApiException('Stripe is not configured (set affiwave.stripe.secret_key or provide your own StripeGatewayInterface).');
        }
        $this->client ??= new StripeClient(array_filter([
            'api_key' => trim((string) $this->secretKey),
            'api_base' => null === $this->apiBase ? '' : trim($this->apiBase),
        ]));

        try {
            return $call($this->client)->toArray();
        } catch (ApiErrorException $e) {
            throw new StripeApiException($e->getMessage(), $e->getHttpStatus(), $e);
        }
    }
}
