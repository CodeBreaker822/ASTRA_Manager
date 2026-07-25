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
            // Add wallet balance column for tracking user credits
            // Credits can only be modified through the PaymentService, not direct manipulation
            $table->decimal('wallet_balance', 15, 2)->default('0.00')->unsigned()->after('email_verified_at');
            $table->unsignedInteger('total_earned_credits')->default(0)->after('wallet_balance');
            $table->unsignedInteger('total_spent_credits')->default(0)->after('total_earned_credits');

            // Add index for faster wallet balance queries
            $table->index('wallet_balance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['wallet_balance']);
            $table->dropColumn(['wallet_balance', 'total_earned_credits', 'total_spent_credits']);
        });
    }
};
