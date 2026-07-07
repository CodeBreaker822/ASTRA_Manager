<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcription_provider_settings', function (Blueprint $table) {
            $table->dropUnique('transcription_provider_settings_provider_unique');
            $table->unique(['provider', 'model']);
        });
    }

    public function down(): void
    {
        Schema::table('transcription_provider_settings', function (Blueprint $table) {
            $table->dropUnique(['provider', 'model']);
            $table->unique('provider');
        });
    }
};
