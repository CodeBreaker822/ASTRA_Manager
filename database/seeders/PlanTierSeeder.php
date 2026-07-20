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

        foreach (config('plans.tiers', []) as $key => $plan) {
            if (! is_array($plan)) {
                continue;
            }

            if (! is_string($key)) {
                continue;
            }

            PlanTier::query()->updateOrCreate(
                ['key' => $key],
                [
                    'name' => (string) ($plan['name'] ?? ucfirst($key)),
                    'tagline' => (string) ($plan['tagline'] ?? ''),
                    'monthly_price' => $plan['monthly_price'] ?? null,
                    'yearly_price' => $plan['yearly_price'] ?? null,
                    'price_label' => (string) ($plan['price_label'] ?? ''),
                    'minutes' => (int) ($plan['minutes'] ?? 0),
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
