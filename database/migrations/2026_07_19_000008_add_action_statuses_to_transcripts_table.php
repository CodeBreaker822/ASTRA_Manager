<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcripts', function (Blueprint $table): void {
            $table->string('polish_status')->default('idle')->after('processing_log');
            $table->text('polish_error_message')->nullable()->after('polish_status');
            $table->string('summary_status')->default('idle')->after('polish_error_message');
            $table->text('summary_error_message')->nullable()->after('summary_status');
        });
    }

    public function down(): void
    {
        Schema::table('transcripts', function (Blueprint $table): void {
            $table->dropColumn([
                'polish_status',
                'polish_error_message',
                'summary_status',
                'summary_error_message',
            ]);
        });
    }
};
