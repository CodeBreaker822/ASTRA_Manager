<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_tiers', function (Blueprint $table): void {
            $table->decimal('upload_price_per_hour', 12, 2)->default(0)->after('price_per_second');
            $table->decimal('live_price_per_hour', 12, 2)->default(0)->after('upload_price_per_hour');
            $table->decimal('llm_price', 12, 2)->default(0)->after('live_price_per_hour');
        });

        foreach (config('plans.tiers', []) as $key => $plan) {
            DB::table('plan_tiers')
                ->where('key', $key)
                ->update([
                    'upload_price_per_hour' => $plan['upload_price_per_hour'] ?? 0,
                    'live_price_per_hour' => $plan['live_price_per_hour'] ?? 0,
                    'llm_price' => $plan['llm_price'] ?? 0,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('plan_tiers', function (Blueprint $table): void {
            $table->dropColumn([
                'upload_price_per_hour',
                'live_price_per_hour',
                'llm_price',
            ]);
        });
    }
};
