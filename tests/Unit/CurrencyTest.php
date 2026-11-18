<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Tests\Unit;

use AffiWave\Bundle\Money\Currency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurrencyTest extends TestCase
{
    #[DataProvider('exponents')]
    public function testExponent(string $currency, int $expected): void
    {
        self::assertSame($expected, Currency::exponent($currency));
    }

    /** @return iterable<string, array{string, int}> */
    public static function exponents(): iterable
    {
        foreach (['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'] as $c) {
            yield $c => [$c, 0];
        }
        foreach (['BHD', 'JOD', 'KWD', 'OMR', 'TND'] as $c) {
            yield $c => [$c, 3];
        }
        yield 'PLN' => ['PLN', 2];
        yield 'lowercase' => ['jpy', 0];
        yield 'empty' => ['', 2];
    }

    public function testToMinor(): void
    {
        self::assertSame(1250, Currency::toMinor(12.5, 'PLN'));
        self::assertSame(500, Currency::toMinor(500.0, 'JPY'));
        self::assertSame(1250, Currency::toMinor(1.25, 'KWD'));
        self::assertSame(1230, Currency::toMinor(1.2304, 'KWD'), 'three-decimal amounts are multiples of 10 for Stripe');
    }
}
