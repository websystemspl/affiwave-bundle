<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Webhook;

use AffiWave\Bundle\Config\Settings;

/**
 * AffiWave webhook signature: `X-AffiWave-Signature: t=<unix ts>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>`
 * (the Stripe scheme). A 5 minute window stops replays; an empty secret means the endpoint is not configured.
 */
class WebhookSignatureVerifier
{
    public function __construct(
        private readonly Settings $settings,
        private readonly int $tolerance = 300,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->settings->webhookSecret();
    }

    /**
     * @return array<string, mixed> the decoded event
     *
     * @throws \RuntimeException         the endpoint is not configured
     * @throws \InvalidArgumentException bad or expired signature, or a body that is not a JSON object
     */
    public function verify(string $payload, ?string $signatureHeader, ?int $now = null): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('AffiWave webhook is not configured.');
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', trim((string) $signatureHeader)) as $segment) {
            [$key, $value] = array_pad(explode('=', trim($segment), 2), 2, '');
            if ('t' === $key && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ('v1' === $key && '' !== $value) {
                $signatures[] = $value;
            }
        }
        if (null === $timestamp || [] === $signatures) {
            throw new \InvalidArgumentException('Missing or invalid X-AffiWave-Signature header.');
        }
        if (abs(($now ?? time()) - $timestamp) > $this->tolerance) {
            throw new \InvalidArgumentException('Expired AffiWave webhook timestamp.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $this->settings->webhookSecret());
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                $decoded = json_decode($payload, true);
                if (!\is_array($decoded)) {
                    throw new \InvalidArgumentException('Invalid AffiWave webhook payload.');
                }

                /** @var array<string, mixed> $decoded */
                return $decoded;
            }
        }

        throw new \InvalidArgumentException('Invalid AffiWave webhook signature.');
    }
}
