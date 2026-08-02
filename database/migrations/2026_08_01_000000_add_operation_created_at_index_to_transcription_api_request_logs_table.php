<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcription_api_request_logs', function (Blueprint $table) {
            $table->index(
                ['operation', 'created_at'],
                'transcription_logs_operation_created_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('transcription_api_request_logs', function (Blueprint $table) {
            $table->dropIndex('transcription_logs_operation_created_index');
        });
    }
};
