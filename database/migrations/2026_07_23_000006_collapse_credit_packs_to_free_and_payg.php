<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plan_tiers')) {
            return;
        }

        foreach (config('plans.tiers', []) as $key => $plan) {
            DB::table('plan_tiers')->updateOrInsert(
                ['key' => $key],
                [
                    'name' => (string) ($plan['name'] ?? ucfirst((string) $key)),
                    'tagline' => (string) ($plan['tagline'] ?? ''),
                    'monthly_price' => $plan['monthly_price'] ?? null,
                    'yearly_price' => $plan['yearly_price'] ?? null,
                    'price_label' => (string) ($plan['price_label'] ?? ''),
                    'price_per_second' => $plan['price_per_second'] ?? 0,
                    'minutes' => (int) ($plan['minutes'] ?? 0),
                    'cta' => (string) ($plan['cta'] ?? ''),
                    'featured' => (bool) ($plan['featured'] ?? false),
                    'features' => json_encode(array_values((array) ($plan['features'] ?? [])), JSON_THROW_ON_ERROR),
                    'entitlements' => json_encode((array) ($plan['entitlements'] ?? []), JSON_THROW_ON_ERROR),
                    'sort_order' => array_search($key, array_keys(config('plans.tiers', [])), true) ?: 0,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        DB::table('plan_tiers')
            ->whereIn('key', ['pro', 'team'])
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

        if (! Schema::hasTable('plan_comparison_rows')) {
            return;
        }

        DB::table('plan_comparison_rows')->delete();

        $comparisonIndex = 0;

        foreach (config('plans.comparison', []) as $label => $tierKeys) {
            DB::table('plan_comparison_rows')->insert([
                'label' => (string) $label,
                'tier_keys' => json_encode(array_values((array) $tierKeys), JSON_THROW_ON_ERROR),
                'sort_order' => $comparisonIndex,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $comparisonIndex++;
        }
    }

    public function down(): void
    {
        DB::table('plan_tiers')
            ->whereIn('key', ['pro', 'team'])
            ->update([
                'is_active' => true,
                'updated_at' => now(),
            ]);
    }
};
