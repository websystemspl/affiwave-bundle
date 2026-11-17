<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Stripe;

/**
 * Stripe error seen by the bundle. Tells a rejected request (4xx other than 429 - retrying will not help: bad code,
 * duplicate, bad amount) from an outage (5xx, 429, network - worth retrying).
 */
final class StripeApiException extends \RuntimeException
{
    public function __construct(string $message, private readonly ?int $statusCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, (int) $statusCode, $previous);
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function isRejected(): bool
    {
        return null !== $this->statusCode && $this->statusCode >= 400 && $this->statusCode < 500 && 429 !== $this->statusCode;
    }
}
