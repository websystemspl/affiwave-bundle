<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Attribution;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Finds the AffiWave click token of a visit. The link itself (`?aw_click=<token>`) wins over an older cookie.
 * The application stores the token wherever it keeps the referral (e.g. on the new account at sign-up).
 */
final class ClickTokenResolver
{
    public function __construct(
        private readonly string $queryParameter = 'aw_click',
        private readonly string $cookieName = 'aw_click',
    ) {
    }

    /** AffiWave click tokens are 32 hex characters; anything else is ignored. */
    public static function validToken(mixed $value): ?string
    {
        return \is_string($value) && 1 === preg_match('/^[a-f0-9]{32}$/', $value) ? $value : null;
    }

    public function resolve(Request $request): ?string
    {
        return $this->fromQuery($request) ?? $this->fromCookie($request);
    }

    public function fromQuery(Request $request): ?string
    {
        return self::validToken($request->query->get($this->queryParameter));
    }

    public function fromCookie(Request $request): ?string
    {
        return self::validToken($request->cookies->get($this->cookieName));
    }

    /** Drop the cookie once the token is stored - the next sign-up from this browser is not the partner's. */
    public function forget(Response $response): void
    {
        $response->headers->clearCookie($this->cookieName, '/');
    }
}
