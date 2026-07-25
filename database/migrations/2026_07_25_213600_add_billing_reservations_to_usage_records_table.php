<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_records', function (Blueprint $table): void {
            $table->unsignedInteger('free_seconds_reserved')->default(0);
            $table->unsignedInteger('free_polish_reserved')->default(0);
            $table->unsignedInteger('free_summary_reserved')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('usage_records', function (Blueprint $table): void {
            $table->dropColumn([
                'free_seconds_reserved',
                'free_polish_reserved',
                'free_summary_reserved',
            ]);
        });
    }
};