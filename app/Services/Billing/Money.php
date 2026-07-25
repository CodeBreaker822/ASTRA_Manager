<?php

namespace App\Services\Billing;

use InvalidArgumentException;

final class Money
{
    public const NANOS_PER_MAJOR = 1_000_000_000;

    public const NANOS_PER_MINOR = 10_000_000;

    public static function decimalToNanos(string|int $amount): int
    {
        $value = trim((string) $amount);

        if (! preg_match('/^\d+(?:\.\d{1,9})?$/', $value)) {
            throw new InvalidArgumentException('Invalid non-negative money value.');
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 9), 9, '0');

        return ((int) $whole * self::NANOS_PER_MAJOR) + (int) $fraction;
    }

    public static function nanosToDecimal(int $nanos): string
    {
        if ($nanos < 0) {
            throw new InvalidArgumentException('Money value cannot be negative.');
        }

        $whole = intdiv($nanos, self::NANOS_PER_MAJOR);
        $fraction = $nanos % self::NANOS_PER_MAJOR;

        return $whole.'.'.str_pad((string) $fraction, 9, '0', STR_PAD_LEFT);
    }

    public static function formatNanos(int $nanos): string
    {
        $decimal = self::nanosToDecimal($nanos);

        [$whole, $fraction] = explode('.', $decimal, 2);

        return '₱'.number_format((int) $whole).'.'.substr($fraction, 0, 2);
    }

    public static function audioCostNanos(int $seconds, string|int $hourlyRate): int
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('Audio seconds cannot be negative.');
        }

        $rateNanos = self::decimalToNanos($hourlyRate);

        if ($seconds === 0 || $rateNanos === 0) {
            return 0;
        }

        return intdiv(($seconds * $rateNanos) + 3_599, 3_600);
    }

    public static function textCostNanos(int $characters, string|int $pricePerCharacter): int
    {
        if ($characters < 0) {
            throw new InvalidArgumentException('Character count cannot be negative.');
        }

        return $characters * self::decimalToNanos($pricePerCharacter);
    }

    public static function minorToNanos(int $minorUnits): int
    {
        if ($minorUnits < 0) {
            throw new InvalidArgumentException('Minor units cannot be negative.');
        }

        return $minorUnits * self::NANOS_PER_MINOR;
    }
}