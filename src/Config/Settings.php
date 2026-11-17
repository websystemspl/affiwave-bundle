<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Config;

/**
 * Resolved bundle configuration. Built by the container from the `affiwave:` config (env values included).
 */
final class Settings
{
    public const DURATIONS = ['once', 'forever', 'repeating'];

    /** @var list<string> */
    public readonly array $couponCurrencies;

    /**
     * @param list<string>|string|null $couponCurrencies
     */
    public function __construct(
        private readonly ?string $apiUrl = null,
        private readonly ?string $apiKey = null,
        private readonly ?string $webhookSecret = null,
        private readonly int|string|null $programId = null,
        private readonly ?bool $enabled = null,
        public readonly float $httpTimeout = 5.0,
        public readonly bool $couponsEnabled = false,
        public readonly string $couponDuration = 'once',
        public readonly int $couponDurationMonths = 1,
        public readonly float $couponVatRate = 0.0,
        array|string|null $couponCurrencies = [],
    ) {
        if (\is_string($couponCurrencies)) {
            $couponCurrencies = explode(',', $couponCurrencies);
        }
        $this->couponCurrencies = array_values(array_filter(array_map(
            static fn (mixed $c): string => strtoupper(trim((string) $c)),
            $couponCurrencies ?? [],
        ), static fn (string $c): bool => '' !== $c));
    }

    public function apiUrl(): string
    {
        return rtrim(trim((string) $this->apiUrl), '/');
    }

    public function apiKey(): string
    {
        return trim((string) $this->apiKey);
    }

    public function webhookSecret(): string
    {
        return trim((string) $this->webhookSecret);
    }

    public function programId(): ?int
    {
        $id = (int) $this->programId;

        return $id > 0 ? $id : null;
    }

    /** Explicit `enabled: false` wins; otherwise the integration is on when both api_url and api_key are set. */
    public function isEnabled(): bool
    {
        return false !== $this->enabled && '' !== $this->apiUrl() && '' !== $this->apiKey();
    }
}
