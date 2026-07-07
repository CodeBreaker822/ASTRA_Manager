<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcription_provider_settings', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('is_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('transcription_provider_settings', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
