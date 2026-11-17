<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Attribution;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Remembers the click token of a partner link (`?aw_click=<token>`) in a first-party, host-only, httpOnly cookie.
 * The token is not a secret (it is in the partner's URL); httpOnly only keeps it away from page scripts.
 */
final class ClickCookieListener
{
    public function __construct(
        private readonly ClickTokenResolver $resolver,
        private readonly string $cookieName = 'aw_click',
        private readonly int $cookieDays = 30,
    ) {
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $token = $this->resolver->fromQuery($request);
        if (null === $token) {
            return;
        }

        $event->getResponse()->headers->setCookie(Cookie::create($this->cookieName)
            ->withValue($token)
            ->withExpires(new \DateTimeImmutable('+'.$this->cookieDays.' days'))
            ->withPath('/')
            ->withSecure($request->isSecure())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX));
    }
}
