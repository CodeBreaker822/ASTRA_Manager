<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_transcription_jobs', function (Blueprint $table): void {
            $table->string('request_id', 100)->nullable();
            $table->uuid('billing_operation_id')->nullable();
            $table->string('billing_feature', 32)->default('upload');
            $table->unsignedInteger('duration_seconds')->default(0);

            $table->foreign('billing_operation_id')
                ->references('id')
                ->on('billing_operations')
                ->nullOnDelete();

            $table->unique(['api_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::table('api_transcription_jobs', function (Blueprint $table): void {
            $table->dropUnique(['api_id', 'request_id']);
            $table->dropForeign(['billing_operation_id']);
            $table->dropColumn([
                'request_id',
                'billing_operation_id',
                'billing_feature',
                'duration_seconds',
            ]);
        });
    }
};