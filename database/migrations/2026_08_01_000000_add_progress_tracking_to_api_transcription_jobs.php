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
        Schema::table('api_transcription_jobs', function (Blueprint $table) {
            $table->integer('total_clips')->nullable()->after('billing_seconds');
            $table->integer('processed_clips')->default(0)->after('total_clips');
            $table->timestamp('last_progress_update')->nullable()->after('processed_clips');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('api_transcription_jobs', function (Blueprint $table) {
            $table->dropColumn(['total_clips', 'processed_clips', 'last_progress_update']);
        });
    }
};
