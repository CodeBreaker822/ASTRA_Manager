<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcripts', function (Blueprint $table): void {
            $table->uuid('billing_request_id')->nullable()->unique();
            $table->uuid('polish_request_id')->nullable();
            $table->uuid('summary_request_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transcripts', function (Blueprint $table): void {
            $table->dropUnique(['billing_request_id']);
            $table->dropColumn([
                'billing_request_id',
                'polish_request_id',
                'summary_request_id',
            ]);
        });
    }
};