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
}
