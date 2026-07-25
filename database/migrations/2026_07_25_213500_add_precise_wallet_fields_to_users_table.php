<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('wallet_balance_nanos')->default(0);
            $table->unsignedBigInteger('wallet_reserved_nanos')->default(0);
            $table->unsignedBigInteger('total_earned_nanos')->default(0);
            $table->unsignedBigInteger('total_spent_nanos')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'wallet_balance_nanos',
                'wallet_reserved_nanos',
                'total_earned_nanos',
                'total_spent_nanos',
            ]);
        });
    }
};