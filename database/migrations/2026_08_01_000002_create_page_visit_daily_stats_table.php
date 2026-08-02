<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_visit_daily_stats', function (Blueprint $table): void {
            $table->id();
            $table->date('visit_date');
            $table->string('route_name', 120);
            $table->string('path');
            $table->char('path_hash', 64);
            $table->unsignedBigInteger('total_visits')->default(0);
            $table->unsignedBigInteger('authenticated_visits')->default(0);
            $table->unsignedBigInteger('guest_visits')->default(0);
            $table->unsignedBigInteger('bot_visits')->default(0);
            $table->timestamps();

            $table->unique(['visit_date', 'path_hash']);
            $table->index(['visit_date', 'total_visits']);
            $table->index('route_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_visit_daily_stats');
    }
};
