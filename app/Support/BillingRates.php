<?php

namespace App\Support;

/**
 * The pay-as-you-go rate table shown on the billing settings page, formatted
 * for display.
 */
class BillingRates
{
    /**
     * @param  array<string, mixed>|null  $plan
     * @return array<int, array{name: string, note: string, rate: string}>
     */
    public static function forPlan(?array $plan): array
    {
        if ($plan === null) {
            return [];
        }

        return [
            [
                'name' => 'Audio Upload',
                'note' => 'Charged after free minutes',
                'rate' => self::hourly($plan['upload_price_per_hour']),
            ],
            [
                'name' => 'Live Recording',
                'note' => 'Charged after free minutes',
                'rate' => self::hourly($plan['live_price_per_hour']),
            ],
            [
                'name' => 'Polishing',
                'note' => 'Charged after free uses',
                'rate' => self::perThousandCharacters($plan['polish_price_per_character']),
            ],
            [
                'name' => 'Summarization',
                'note' => 'Charged after free uses',
                'rate' => self::perThousandCharacters($plan['summary_price_per_character']),
            ],
        ];
    }

    private static function hourly(mixed $amount): string
    {
        return Money::format((float) ($amount ?? 0), 'USD', 2, 2).'/hour';
    }

    private static function perThousandCharacters(mixed $amount): string
    {
        return Money::format((float) ($amount ?? 0) * 1000, 'USD', 2, 4).'/1K chars';
    }
}
