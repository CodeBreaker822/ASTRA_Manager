<?php

namespace App\Support;

/**
 * The five headline facts under the marketing hero. Each falls back to
 * CMS-managed copy when the corresponding rate is not configured.
 */
class PricingProof
{
    /**
     * @param  array<string, mixed>  $pricing  Summary from PlanService::marketingSummary()
     * @param  array<string, string>  $copy  The `pricing_proof` section of the site content
     * @return array<int, array{value: string, label: string}>
     */
    public static function facts(array $pricing, array $copy): array
    {
        $freeMinutes = $pricing['free_minutes_per_day'];
        $uploadRate = $pricing['upload_price_per_hour'];
        $liveRate = $pricing['live_price_per_hour'];
        $currency = (string) $pricing['currency'];

        return [
            [
                'value' => $freeMinutes
                    ? $freeMinutes.' '.$copy['free_minutes_suffix']
                    : $copy['free_value_fallback'],
                'label' => $freeMinutes ? $copy['free_active_label'] : $copy['free_fallback_label'],
            ],
            [
                'value' => self::rate($uploadRate, $currency) ?? $copy['upload_value_fallback'],
                'label' => $uploadRate ? $copy['upload_active_label'] : $copy['upload_fallback_label'],
            ],
            [
                'value' => self::rate($liveRate, $currency) ?? $copy['live_value_fallback'],
                'label' => $liveRate ? $copy['live_active_label'] : $copy['live_fallback_label'],
            ],
            ['value' => $copy['languages_value'], 'label' => $copy['languages_label']],
            ['value' => $copy['desktop_value'], 'label' => $copy['desktop_label']],
        ];
    }

    /**
     * Sub-dollar rates need more decimals to read as a real price.
     */
    private static function rate(mixed $value, string $currency): ?string
    {
        if (! is_numeric($value) || (float) $value <= 0) {
            return null;
        }

        $amount = (float) $value;

        return $amount < 1
            ? Money::format($amount, $currency, 2, 4)
            : Money::format($amount, $currency, 0, 2);
    }
}
