<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transcription_provider_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique();
            $table->text('api_key')->nullable();
            $table->string('model')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcription_provider_settings');
    }
};
