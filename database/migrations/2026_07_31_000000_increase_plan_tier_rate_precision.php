<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_tiers', function (Blueprint $table): void {
            $table->decimal('upload_price_per_hour', 20, 12)->default(0)->change();
            $table->decimal('live_price_per_hour', 20, 12)->default(0)->change();
            $table->decimal('llm_price', 20, 12)->default(0)->change();
            $table->decimal('polish_price_per_character', 20, 12)->default(0)->change();
            $table->decimal('summary_price_per_character', 20, 12)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('plan_tiers', function (Blueprint $table): void {
            $table->decimal('upload_price_per_hour', 12, 2)->default(0)->change();
            $table->decimal('live_price_per_hour', 12, 2)->default(0)->change();
            $table->decimal('llm_price', 12, 2)->default(0)->change();
            $table->decimal('polish_price_per_character', 16, 8)->default(0)->change();
            $table->decimal('summary_price_per_character', 16, 8)->default(0)->change();
        });
    }
};
