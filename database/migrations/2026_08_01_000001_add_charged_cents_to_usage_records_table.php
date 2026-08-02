<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_records', function (Blueprint $table): void {
            $table->unsignedInteger('charged_cents')->default(0)->after('summary_count');
            $table->unsignedInteger('upload_charged_cents')->default(0)->after('charged_cents');
            $table->unsignedInteger('live_charged_cents')->default(0)->after('upload_charged_cents');
            $table->unsignedInteger('polish_charged_cents')->default(0)->after('live_charged_cents');
            $table->unsignedInteger('summary_charged_cents')->default(0)->after('polish_charged_cents');
            $table->index(['period', 'charged_cents'], 'usage_records_period_charged_index');
        });
    }

    public function down(): void
    {
        Schema::table('usage_records', function (Blueprint $table): void {
            $table->dropIndex('usage_records_period_charged_index');
            $table->dropColumn([
                'charged_cents',
                'upload_charged_cents',
                'live_charged_cents',
                'polish_charged_cents',
                'summary_charged_cents',
            ]);
        });
    }
};
