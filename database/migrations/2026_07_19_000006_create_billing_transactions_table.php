<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('paymongo');
            $table->string('plan');
            $table->string('reference')->unique();
            $table->string('checkout_session_id')->nullable()->index();
            $table->string('payment_id')->nullable()->index();
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('PHP');
            $table->text('checkout_url')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_transactions');
    }
};
