<?php

namespace Database\Seeders;

use App\Models\PlanComparisonRow;
use App\Models\PlanTier;
use Illuminate\Database\Seeder;

class PlanTierSeeder extends Seeder
{
    public function run(): void
    {
        $tierIndex = 0;
        $activeKeys = [];

        foreach (config('plans.tiers', []) as $key => $plan) {
            if (! is_array($plan)) {
                continue;
            }

            if (! is_string($key)) {
                continue;
            }

            $activeKeys[] = $key;

            PlanTier::query()->updateOrCreate(
                ['key' => $key],
                [
                    'name' => (string) ($plan['name'] ?? ucfirst($key)),
                    'tagline' => (string) ($plan['tagline'] ?? ''),
                    'monthly_price' => $plan['monthly_price'] ?? null,
                    'yearly_price' => $plan['yearly_price'] ?? null,
                    'price_label' => (string) ($plan['price_label'] ?? ''),
                    'price_per_second' => (float) ($plan['price_per_second'] ?? 0),
                    'upload_price_per_hour' => (float) ($plan['upload_price_per_hour'] ?? 0),
                    'live_price_per_hour' => (float) ($plan['live_price_per_hour'] ?? 0),
                    'llm_price' => (float) ($plan['llm_price'] ?? 0),
                    'polish_price_per_character' => (float) ($plan['polish_price_per_character'] ?? 0),
                    'summary_price_per_character' => (float) ($plan['summary_price_per_character'] ?? 0),
                    'minutes' => (int) ($plan['minutes'] ?? 0),
                    'free_polish_uses_per_day' => (int) ($plan['free_polish_uses_per_day'] ?? 0),
                    'free_summary_uses_per_day' => (int) ($plan['free_summary_uses_per_day'] ?? 0),
                    'polish_characters' => (int) ($plan['polish_characters'] ?? 0),
                    'summary_characters' => (int) ($plan['summary_characters'] ?? 0),
                    'cta' => (string) ($plan['cta'] ?? ''),
                    'featured' => (bool) ($plan['featured'] ?? false),
                    'features' => array_values((array) ($plan['features'] ?? [])),
                    'entitlements' => (array) ($plan['entitlements'] ?? []),
                    'sort_order' => $tierIndex,
                    'is_active' => true,
                ],
            );

            $tierIndex++;
        }

        PlanTier::query()
            ->whereNotIn('key', $activeKeys)
            ->update(['is_active' => false]);

        $rowIndex = 0;

        foreach (config('plans.comparison', []) as $label => $tierKeys) {
            if (! is_string($label)) {
                continue;
            }

            PlanComparisonRow::query()->updateOrCreate(
                ['label' => $label],
                [
                    'tier_keys' => array_values((array) $tierKeys),
                    'sort_order' => $rowIndex,
                ],
            );

            $rowIndex++;
        }
    }
}
