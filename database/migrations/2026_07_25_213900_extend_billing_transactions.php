<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->unsignedBigInteger('wallet_credit_nanos')->nullable();
            $table->string('provider_event_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('billing_transactions', function (Blueprint $table): void {
            $table->dropUnique(['provider_event_id']);
            $table->dropColumn([
                'amount_minor',
                'wallet_credit_nanos',
                'provider_event_id',
            ]);
        });
    }
};