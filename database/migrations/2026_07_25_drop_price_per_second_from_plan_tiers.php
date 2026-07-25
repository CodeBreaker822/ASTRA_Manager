<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('plan_tiers', function (Blueprint $table) {
            $table->dropColumn('price_per_second');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plan_tiers', function (Blueprint $table) {
            $table->decimal('price_per_second', 8, 8)->default(0.00000000);
        });
    }
};