<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Api;

/**
 * Answer of the AffiWave API: HTTP status plus the decoded JSON body (empty array when the body is not JSON).
 */
final readonly class ApiResult
{
    /**
     * @param array<mixed> $body
     */
    public function __construct(
        public int $status,
        public array $body = [],
        public string $rawBody = '',
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** Server-side or throttling problem - the same request may succeed later. */
    public function isTransient(): bool
    {
        return $this->status >= 500 || 429 === $this->status || 408 === $this->status;
    }

    /** Machine readable `error` code of an error answer, e.g. `unknown_token`. */
    public function errorCode(): ?string
    {
        $code = $this->body['error'] ?? null;

        return \is_string($code) ? $code : null;
    }

    public function summary(): string
    {
        $code = $this->errorCode();
        $text = null !== $code ? $code : mb_substr($this->rawBody, 0, 200);

        return \sprintf('HTTP %d%s', $this->status, '' !== $text ? ': '.$text : '');
    }
}
