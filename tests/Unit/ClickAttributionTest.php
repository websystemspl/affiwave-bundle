<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Tests\Unit;

use AffiWave\Bundle\Attribution\ClickCookieListener;
use AffiWave\Bundle\Attribution\ClickTokenResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ClickAttributionTest extends TestCase
{
    private const TOKEN = '0123456789abcdef0123456789abcdef';
    private const OTHER = 'fedcba9876543210fedcba9876543210';

    #[DataProvider('invalidTokens')]
    public function testInvalidTokensAreIgnored(mixed $value): void
    {
        self::assertNull(ClickTokenResolver::validToken($value));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidTokens(): iterable
    {
        yield 'too short' => ['abc'];
        yield 'uppercase' => [strtoupper(self::TOKEN)];
        yield 'non hex' => [str_repeat('z', 32)];
        yield 'too long' => [self::TOKEN.'0'];
        yield 'array' => [[self::TOKEN]];
        yield 'null' => [null];
    }

    public function testQueryWinsOverCookie(): void
    {
        $request = Request::create('/?aw_click='.self::TOKEN, cookies: ['aw_click' => self::OTHER]);

        self::assertSame(self::TOKEN, (new ClickTokenResolver())->resolve($request));
    }

    public function testCookieIsUsedWithoutQueryAndInvalidQueryFallsBack(): void
    {
        $resolver = new ClickTokenResolver();

        self::assertSame(self::OTHER, $resolver->resolve(Request::create('/', cookies: ['aw_click' => self::OTHER])));
        self::assertSame(self::OTHER, $resolver->resolve(Request::create('/?aw_click=nope', cookies: ['aw_click' => self::OTHER])));
        self::assertNull($resolver->resolve(Request::create('/')));
    }

    public function testCustomParameterAndCookieNames(): void
    {
        $resolver = new ClickTokenResolver('ref', 'ref_cookie');

        self::assertSame(self::TOKEN, $resolver->resolve(Request::create('/?ref='.self::TOKEN)));
        self::assertSame(self::OTHER, $resolver->resolve(Request::create('/', cookies: ['ref_cookie' => self::OTHER])));
        self::assertNull($resolver->resolve(Request::create('/?aw_click='.self::TOKEN)));
    }

    public function testListenerSetsAHardenedCookie(): void
    {
        $response = $this->handle(Request::create('https://app.example/?aw_click='.self::TOKEN), 14);

        $cookie = $response->headers->getCookies()[0] ?? null;
        self::assertInstanceOf(Cookie::class, $cookie);
        self::assertSame('aw_click', $cookie->getName());
        self::assertSame(self::TOKEN, $cookie->getValue());
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertSame('/', $cookie->getPath());
        self::assertEqualsWithDelta(time() + 14 * 86400, $cookie->getExpiresTime(), 5);
    }

    public function testListenerIgnoresPlainHttpSecureFlagAndMissingOrInvalidTokens(): void
    {
        $plain = $this->handle(Request::create('http://app.example/?aw_click='.self::TOKEN));
        self::assertFalse($plain->headers->getCookies()[0]->isSecure());

        self::assertSame([], $this->handle(Request::create('/'))->headers->getCookies());
        self::assertSame([], $this->handle(Request::create('/?aw_click=bad'))->headers->getCookies());
        // A cookie alone is not refreshed.
        self::assertSame([], $this->handle(Request::create('/', cookies: ['aw_click' => self::TOKEN]))->headers->getCookies());
    }

    public function testListenerSkipsSubRequests(): void
    {
        $response = $this->handle(Request::create('/?aw_click='.self::TOKEN), 30, HttpKernelInterface::SUB_REQUEST);

        self::assertSame([], $response->headers->getCookies());
    }

    public function testForgetClearsTheCookie(): void
    {
        $response = new Response();
        (new ClickTokenResolver())->forget($response);

        $cookie = $response->headers->getCookies()[0];
        self::assertSame('aw_click', $cookie->getName());
        self::assertLessThan(time(), $cookie->getExpiresTime());
    }

    private function handle(Request $request, int $days = 30, int $type = HttpKernelInterface::MAIN_REQUEST): Response
    {
        $response = new Response();
        $kernel = $this->createStub(HttpKernelInterface::class);
        (new ClickCookieListener(new ClickTokenResolver(), 'aw_click', $days))->onKernelResponse(new ResponseEvent($kernel, $request, $type, $response));

        return $response;
    }
}
