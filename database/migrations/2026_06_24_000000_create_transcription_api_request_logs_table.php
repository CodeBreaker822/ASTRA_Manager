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
        Schema::create('transcription_api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->foreignId('api_id')->nullable()->constrained('a_p_i_s')->nullOnDelete();
            $table->string('app_name')->nullable();
            $table->string('license_token_prefix', 24)->nullable();
            $table->string('license_token_hash', 64)->nullable();
            $table->string('operation', 50);
            $table->string('endpoint');
            $table->string('http_method', 10);
            $table->string('status', 50);
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('low');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('provider', 50)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('language_code', 20)->nullable();
            $table->unsignedInteger('clip_index')->nullable();
            $table->unsignedInteger('clip_start_ms')->nullable();
            $table->unsignedInteger('clip_end_ms')->nullable();
            $table->string('audio_file_name')->nullable();
            $table->string('audio_mime_type', 120)->nullable();
            $table->unsignedBigInteger('audio_size_bytes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('request_summary')->nullable();
            $table->json('response_summary')->nullable();
            $table->string('error_message')->nullable();
            $table->timestamps();

            $table->index(['api_id', 'created_at']);
            $table->index(['ip_address', 'created_at']);
            $table->index(['status', 'severity']);
            $table->index(['provider', 'created_at']);
            $table->index('license_token_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transcription_api_request_logs');
    }
};
