<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, check if the legacy columns still exist
        $schema = Schema::connection(DB::connection()->getName());

        if (! $schema->hasColumn('users', 'credit_seconds')) {
            // Columns already dropped - migration has already run
            return;
        }

        $paygPlan = DB::table('plan_tiers')->where('key', 'payg')->first();

        if (! $paygPlan) {
            return;
        }

        // Only process users who have existing legacy credits
        DB::table('users')
            ->where('credit_seconds', '>', 0)
            ->orWhere('polish_credit_characters', '>', 0)
            ->orWhere('summary_credit_characters', '>', 0)
            ->orderBy('id')
            ->chunk(200, function ($users) use ($paygPlan) {
                foreach ($users as $user) {
                    // Only convert if at least one type of credit exists
                    if (
                        $user->credit_seconds > 0 ||
                        $user->polish_credit_characters > 0 ||
                        $user->summary_credit_characters > 0
                    ) {
                        // Convert minutes from credit_seconds
                        $minutesValue = ($user->credit_seconds / 3600) * ($paygPlan->upload_price_per_hour ?? 0);

                        // Convert polish characters
                        $polishValue = ($user->polish_credit_characters / 1000) * ($paygPlan->polish_price_per_character ?? 0);

                        // Convert summary characters
                        $summaryValue = ($user->summary_credit_characters / 1000) * ($paygPlan->summary_price_per_character ?? 0);

                        $totalValue = $minutesValue + $polishValue + $summaryValue;

                        if ($totalValue > 0) {
                            DB::table('users')->where('id', $user->id)->increment(
                                'wallet_balance',
                                round($totalValue, 2)
                            );
                        }
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No rollback needed - this is a one-time backfill
    }
};