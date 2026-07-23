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
            $table->decimal('price_per_second', 12, 8)->default(0)->after('price_label');
        });

        foreach (config('plans.tiers', []) as $key => $plan) {
            DB::table('plan_tiers')
                ->where('key', $key)
                ->update([
                    'price_per_second' => $plan['price_per_second'] ?? 0,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('plan_tiers', function (Blueprint $table): void {
            $table->dropColumn('price_per_second');
        });
    }
};
