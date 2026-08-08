<?php

namespace App\Support;

use RuntimeException;

class Money
{
    public static function decimalDollarsToUsdCents(mixed $value): int
    {
        $decimal = trim((string) ($value ?? '0'));

        if ($decimal === '') {
            return 0;
        }

        $negative = str_starts_with($decimal, '-');
        $decimal = ltrim($decimal, '+-');
        [$dollars, $cents] = array_pad(explode('.', $decimal, 2), 2, '0');
        $dollars = preg_replace('/\D/', '', $dollars) ?? '0';
        $cents = preg_replace('/\D/', '', $cents) ?? '0';
        $cents = str_pad(substr($cents, 0, 2), 2, '0');

        $amount = ((int) $dollars * 100) + (int) $cents;

        return $negative ? -$amount : $amount;
    }

    public static function usdCentsToDecimalDollars(int $cents): string
    {
        $negative = $cents < 0;
        $cents = abs($cents);

        return ($negative ? '-' : '').intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function usdCentsToDollars(int $cents): float
    {
        return $cents / 100;
    }

    public static function dollarsToUsdCents(float|int|string $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    public static function usdCentsToPhpCentavos(int $usdCents, float $rate): int
    {
        if ($rate <= 0) {
            throw new RuntimeException('Live USD to PHP exchange rate must be greater than 0.');
        }

        return (int) round($usdCents * $rate);
    }

    /**
     * Format an amount the way Intl.NumberFormat did on the client: pad to
     * $min fraction digits, allow up to $max, and drop the trailing zeros in
     * between. Implemented without ext-intl, which is not guaranteed here.
     */
    public static function format(float $value, string $currency = 'USD', int $min = 0, int $max = 2): string
    {
        $rounded = round($value, $max);
        $formatted = number_format($rounded, $max, '.', ',');

        if ($max > $min && str_contains($formatted, '.')) {
            $formatted = rtrim($formatted, '0');
            [$whole, $fraction] = array_pad(explode('.', $formatted, 2), 2, '');
            $formatted = $min === 0 && $fraction === ''
                ? $whole
                : $whole.'.'.str_pad($fraction, $min, '0');
        }

        return (self::SYMBOLS[$currency] ?? $currency.' ').$formatted;
    }

    private const SYMBOLS = [
        'USD' => '$',
        'PHP' => '₱',
        'EUR' => '€',
        'GBP' => '£',
    ];
}
