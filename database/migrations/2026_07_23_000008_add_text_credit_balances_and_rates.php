<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('polish_credit_characters')->default(0)->after('credit_seconds');
            $table->unsignedInteger('summary_credit_characters')->default(0)->after('polish_credit_characters');
        });

        Schema::table('plan_tiers', function (Blueprint $table): void {
            $table->unsignedSmallInteger('free_polish_uses_per_day')->default(0)->after('minutes');
            $table->unsignedSmallInteger('free_summary_uses_per_day')->default(0)->after('free_polish_uses_per_day');
            $table->unsignedInteger('polish_characters')->default(0)->after('free_summary_uses_per_day');
            $table->unsignedInteger('summary_characters')->default(0)->after('polish_characters');
            $table->decimal('polish_price_per_character', 16, 8)->default(0)->after('llm_price');
            $table->decimal('summary_price_per_character', 16, 8)->default(0)->after('polish_price_per_character');
        });

        foreach (config('plans.tiers', []) as $key => $plan) {
            DB::table('plan_tiers')
                ->where('key', $key)
                ->update([
                    'free_polish_uses_per_day' => $plan['free_polish_uses_per_day'] ?? 0,
                    'free_summary_uses_per_day' => $plan['free_summary_uses_per_day'] ?? 0,
                    'polish_characters' => $plan['polish_characters'] ?? 0,
                    'summary_characters' => $plan['summary_characters'] ?? 0,
                    'polish_price_per_character' => $plan['polish_price_per_character'] ?? 0,
                    'summary_price_per_character' => $plan['summary_price_per_character'] ?? 0,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('plan_tiers', function (Blueprint $table): void {
            $table->dropColumn([
                'free_polish_uses_per_day',
                'free_summary_uses_per_day',
                'polish_characters',
                'summary_characters',
                'polish_price_per_character',
                'summary_price_per_character',
            ]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'polish_credit_characters',
                'summary_credit_characters',
            ]);
        });
    }
};
