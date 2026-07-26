<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wallet tables for unified billing system (USD-based)
     *
     * All prices, balances, and transactions are in USD cents.
     * This ensures consistency across the system and when working with PayMongo.
     *
     * Currency Conversion:
     * - System stores: USD cents (integer)
     * - PayMongo webhook: PHP pesos
     * - Conversion: USD cents × PHP rate ÷ 100 = PHP amount
     */
    public function up(): void
    {
        // Wallet table - stores user's current wallet balance and reservations (in USD cents)
        Schema::create('user_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Wallet balance in USD cents
            // Example: $100.00 = 10,000 cents
            $table->unsignedInteger('balance_cents')->default(0);

            // Reserved amount for current operation (in USD cents)
            $table->unsignedInteger('reserved_cents')->default(0);

            // Audit tracking
            $table->unsignedInteger('total_earned_cents')->default(0);
            $table->unsignedInteger('total_spent_cents')->default(0);

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            // Index for faster balance lookups
            $table->index('user_id');
        });

        // Ledger entries - immutable audit trail for all wallet transactions
        Schema::create('wallet_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_wallet_id')->constrained('user_wallets')->cascadeOnDelete();

            // Transaction details
            $table->string('type'); // credit, debit, adjustment
            $table->text('description');
            $table->integer('amount_cents'); // signed amount (positive for credit, negative for debit)
            $table->integer('balance_cents'); // wallet balance after this transaction (in USD cents)

            // Metadata
            $table->string('reference_type')->nullable(); // 'transcription', 'polish', 'summary', 'topup', etc.
            $table->string('reference_id')->nullable(); // ID of the related record
            $table->string('operation_key')->nullable(); // idempotency key for this operation

            // Additional data
            $table->json('metadata')->nullable(); // Additional context about the transaction

            $table->timestamp('created_at')->useCurrent();

            // Indexes for querying
            $table->index('user_id');
            $table->index('reference_type');
            $table->index('reference_id');
            $table->index('operation_key');
            $table->index('created_at');
        });

        // Billing operations - tracks each billed request
        Schema::create('billing_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Operation details
            $table->string('feature'); // 'transcription', 'polish', 'summary'
            $table->string('status'); // pending, authorized, charged, released, failed
            $table->integer('units_requested'); // Requested units (seconds for audio, characters for polish/summary)
            $table->integer('units_free')->default(0); // Free units consumed
            $table->integer('units_paid')->default(0); // Paid units consumed

            // Pricing snapshot at time of authorization (in USD cents)
            $table->string('rate_per_unit_cents'); // Rate in cents per unit
            $table->unsignedInteger('authorized_amount_cents'); // Amount authorized (in USD cents)
            $table->unsignedInteger('charged_amount_cents')->nullable(); // Amount charged (in USD cents)

            // References
            $table->string('operation_key')->unique(); // Idempotency key
            $table->string('reference_type')->nullable(); // Type of the main resource
            $table->string('reference_id')->nullable(); // ID of the main resource

            // Finalization info
            $table->json('result_payload')->nullable(); // Result data from the operation
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('charged_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->text('error_message')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('user_id');
            $table->index('operation_key');
            $table->index('feature');
            $table->index('status');
            $table->index('authorized_at');
            $table->index('created_at');
        });

        // Create wallet for existing users
        DB::statement("
            INSERT INTO user_wallets (user_id, balance_cents, reserved_cents, total_earned_cents, total_spent_cents)
            SELECT id, 0, 0, 0, 0 FROM users
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_operations');
        Schema::dropIfExists('wallet_ledger_entries');
        Schema::dropIfExists('user_wallets');
    }
};