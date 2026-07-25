<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_ledger_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('billing_operation_id')->nullable();
            $table->foreignId('billing_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 16);
            $table->string('type', 40);
            $table->unsignedBigInteger('amount_nanos');
            $table->unsignedBigInteger('balance_after_nanos');
            $table->string('currency', 3)->default('PHP');
            $table->string('idempotency_key', 180)->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('billing_operation_id')
                ->references('id')
                ->on('billing_operations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_ledger_entries');
    }
};