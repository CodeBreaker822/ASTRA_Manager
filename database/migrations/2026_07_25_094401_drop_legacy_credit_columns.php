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
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'credit_seconds',
                'polish_credit_characters',
                'summary_credit_characters',
            ]);
        });

        Schema::table('plan_tiers', function (Blueprint $table) {
            $table->dropColumn([
                'polish_characters',
                'summary_characters',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('credit_seconds')->default(0)->after('wallet_balance');
            $table->unsignedInteger('polish_credit_characters')->default(0);
            $table->unsignedInteger('summary_credit_characters')->default(0);
        });

        Schema::table('plan_tiers', function (Blueprint $table) {
            $table->unsignedInteger('polish_characters')->default(0);
            $table->unsignedInteger('summary_characters')->default(0);
        });
    }
};