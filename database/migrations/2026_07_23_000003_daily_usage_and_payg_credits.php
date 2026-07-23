<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_records', function (Blueprint $table): void {
            $table->string('period', 10)->change();
            $table->unsignedInteger('seconds_purchased')->default(0)->after('seconds_transcribed');
        });

        if (Schema::hasTable('plan_tiers')) {
            foreach (config('plans.tiers', []) as $key => $plan) {
                DB::table('plan_tiers')
                    ->where('key', $key)
                    ->update([
                        'name' => (string) ($plan['name'] ?? ucfirst((string) $key)),
                        'tagline' => (string) ($plan['tagline'] ?? ''),
                        'monthly_price' => $plan['monthly_price'] ?? null,
                        'yearly_price' => $plan['yearly_price'] ?? null,
                        'price_label' => (string) ($plan['price_label'] ?? ''),
                        'minutes' => (int) ($plan['minutes'] ?? 0),
                        'cta' => (string) ($plan['cta'] ?? ''),
                        'featured' => (bool) ($plan['featured'] ?? false),
                        'features' => json_encode(array_values((array) ($plan['features'] ?? [])), JSON_THROW_ON_ERROR),
                        'entitlements' => json_encode((array) ($plan['entitlements'] ?? []), JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
            }
        }

        if (Schema::hasTable('plan_comparison_rows')) {
            DB::table('plan_comparison_rows')->delete();

            foreach (config('plans.comparison', []) as $label => $tierKeys) {
                DB::table('plan_comparison_rows')->insert([
                    'label' => (string) $label,
                    'tier_keys' => json_encode(array_values((array) $tierKeys), JSON_THROW_ON_ERROR),
                    'sort_order' => (int) DB::table('plan_comparison_rows')->count(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('usage_records', function (Blueprint $table): void {
            $table->dropColumn('seconds_purchased');
            $table->string('period', 7)->change();
        });
    }
};
