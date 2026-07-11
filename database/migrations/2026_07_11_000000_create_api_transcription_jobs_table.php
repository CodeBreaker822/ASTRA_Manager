<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_transcription_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('api_id')->constrained('a_p_i_s')->cascadeOnDelete();
            $table->string('status', 32)->default('queued')->index();
            $table->json('request_payload');
            $table->json('result_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_transcription_jobs');
    }
};
