<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('transcript_sections', 'speaker_timestamps')) {
            return;
        }

        Schema::table('transcript_sections', function (Blueprint $table): void {
            $table->json('speaker_timestamps')->nullable()->after('ended_at_ms');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('transcript_sections', 'speaker_timestamps')) {
            return;
        }

        Schema::table('transcript_sections', function (Blueprint $table): void {
            $table->dropColumn('speaker_timestamps');
        });
    }
};
