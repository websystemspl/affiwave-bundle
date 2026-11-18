<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Money;

/**
 * The one place that knows how many decimal places a currency has in Stripe (the minor unit exponent).
 */
final class Currency
{
    /** Stripe zero-decimal currencies: minor unit = major unit. */
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /** Stripe three-decimal currencies (amounts must be divisible by 10 on the Stripe side). */
    private const THREE_DECIMAL = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    public static function exponent(string $currency): int
    {
        $code = strtoupper(trim($currency));

        return match (true) {
            \in_array($code, self::ZERO_DECIMAL, true) => 0,
            \in_array($code, self::THREE_DECIMAL, true) => 3,
            default => 2,
        };
    }

    /**
     * Minor units -> the AffiWave `amount` string: main units, always exactly two decimals
     * (`^\d{1,12}(\.\d{1,2})?$`). Three-decimal currencies are rounded (half up) to two places.
     */
    public static function toApiAmount(int $minor, string $currency): string
    {
        $exponent = self::exponent($currency);
        $negative = $minor < 0;
        $abs = abs($minor);

        // Work in hundredths of a main unit, with integers only.
        $hundredths = match ($exponent) {
            0 => $abs * 100,
            3 => intdiv($abs + 5, 10),
            default => $abs,
        };

        return \sprintf('%s%d.%02d', $negative ? '-' : '', intdiv($hundredths, 100), $hundredths % 100);
    }

    /**
     * A decimal amount in main units -> minor units of the currency. Three-decimal currencies are rounded to a
     * multiple of 10 minor units, which is what Stripe requires.
     */
    public static function toMinor(float $major, string $currency): int
    {
        $exponent = self::exponent($currency);
        $minor = (int) round($major * 10 ** $exponent);

        return 3 === $exponent ? (int) (round($minor / 10) * 10) : $minor;
    }
}
