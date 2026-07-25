<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('api_id')->nullable()->constrained('a_p_i_s')->nullOnDelete();
            $table->string('feature', 32);
            $table->string('status', 24)->default('authorized');
            $table->string('idempotency_key', 160)->unique();
            $table->string('subject_type', 120)->nullable();
            $table->string('subject_id', 120)->nullable();
            $table->unsignedBigInteger('requested_units');
            $table->unsignedBigInteger('free_units')->default(0);
            $table->unsignedBigInteger('paid_units')->default(0);
            $table->unsignedBigInteger('rate_nanos')->default(0);
            $table->unsignedBigInteger('authorized_amount_nanos')->default(0);
            $table->unsignedBigInteger('captured_amount_nanos')->default(0);
            $table->string('currency', 3)->default('PHP');
            $table->unsignedInteger('authorization_attempts')->default(1);
            $table->json('metadata')->nullable();
            $table->json('result_payload')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_operations');
    }
};