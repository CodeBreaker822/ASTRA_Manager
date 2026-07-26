<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop obsolete credit fields from users and plan_tiers tables
     *
     * These fields are replaced by the new wallet system:
     * - users.credit_seconds -> user_wallets.balance_nanos
     * - users.polish_credit_characters -> user_wallets.balance_nanos
     * - users.summary_credit_characters -> user_wallets.balance_nanos
     * - plan_tiers.price_per_second -> removed (use hourly rates instead)
     * - plan_tiers.polish_characters -> removed (use per-character pricing)
     * - plan_tiers.summary_characters -> removed (use per-character pricing)
     */
    public function up(): void
    {
        // Drop obsolete plan_tiers columns
        Schema::table('plan_tiers', function (Blueprint $table) {
            $table->dropColumn(['price_per_second', 'polish_characters', 'summary_characters']);
        });

        // Drop obsolete user columns
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['credit_seconds', 'polish_credit_characters', 'summary_credit_characters']);
        });

        // Remove plan_tiers from users (move to user_wallets table)
        // If users have a plan_tier_id, preserve it but it won't be used for billing anymore
    }

    public function down(): void
    {
        // Restore columns for rollback
        Schema::table('users', function (Blueprint $table) {
            $table->integer('credit_seconds')->nullable()->default(0)->after('plan_tier_id');
            $table->integer('polish_credit_characters')->nullable()->default(0)->after('credit_seconds');
            $table->integer('summary_credit_characters')->nullable()->default(0)->after('polish_credit_characters');
        });

        Schema::table('plan_tiers', function (Blueprint $table) {
            $table->decimal('price_per_second', 15, 8)->nullable()->after('summary_price_per_character');
            $table->integer('polish_characters')->nullable()->default(1000)->after('monthly_price');
            $table->integer('summary_characters')->nullable()->default(1000)->after('polish_characters');
        });
    }
};