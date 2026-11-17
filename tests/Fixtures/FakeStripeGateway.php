<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Tests\Fixtures;

use AffiWave\Bundle\Stripe\StripeApiException;
use AffiWave\Bundle\Stripe\StripeGatewayInterface;

/** In-memory Stripe: remembers coupons, promotion codes and every call. */
final class FakeStripeGateway implements StripeGatewayInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $coupons = [];
    /** @var array<string, array<string, mixed>> */
    public array $promos = [];
    /** @var array<string, array<string, mixed>> */
    public array $invoices = [];
    /** @var array<string, array<string, mixed>> */
    public array $sessions = [];
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $calls = [];
    /** null = OK, otherwise the HTTP status every write fails with */
    public ?int $failWith = null;

    public function retrieveInvoice(string $invoiceId): array
    {
        $this->record('retrieveInvoice', ['id' => $invoiceId]);

        return $this->invoices[$invoiceId] ?? throw new StripeApiException('No such invoice', 404);
    }

    public function retrieveCheckoutSession(string $sessionId): array
    {
        $this->record('retrieveCheckoutSession', ['id' => $sessionId]);

        return $this->sessions[$sessionId] ?? throw new StripeApiException('No such session', 404);
    }

    public function retrievePromotionCode(string $promotionCodeId): array
    {
        $this->record('retrievePromotionCode', ['id' => $promotionCodeId]);

        return $this->promos[$promotionCodeId] ?? throw new StripeApiException('No such promotion code', 404);
    }

    public function createCoupon(array $params): array
    {
        $this->record('createCoupon', $params);
        $this->maybeFail();
        $id = 'co_'.(\count($this->coupons) + 1);

        return $this->coupons[$id] = ['id' => $id] + $params;
    }

    public function createPromotionCode(array $params): array
    {
        $this->record('createPromotionCode', $params);
        $this->maybeFail();
        $id = 'promo_'.(\count($this->promos) + 1);

        return $this->promos[$id] = ['id' => $id] + $params;
    }

    public function updatePromotionCode(string $id, array $params): array
    {
        $this->record('updatePromotionCode', ['id' => $id] + $params);
        $this->maybeFail();

        return $this->promos[$id] = array_merge($this->promos[$id] ?? ['id' => $id], $params);
    }

    public function listActivePromotionCodes(string $code): array
    {
        $this->record('listActivePromotionCodes', ['code' => $code]);

        return array_values(array_filter($this->promos, static fn (array $p): bool => ($p['active'] ?? false) && 0 === strcasecmp((string) ($p['code'] ?? ''), $code)));
    }

    /** @return list<array<string, mixed>> params of every call with this name */
    public function callsTo(string $name): array
    {
        return array_values(array_map(static fn (array $c): array => $c[1], array_filter($this->calls, static fn (array $c): bool => $c[0] === $name)));
    }

    /** @param array<string, mixed> $params */
    private function record(string $name, array $params): void
    {
        $this->calls[] = [$name, $params];
    }

    private function maybeFail(): void
    {
        if (null !== $this->failWith) {
            throw new StripeApiException('Stripe failure', $this->failWith);
        }
    }
}
