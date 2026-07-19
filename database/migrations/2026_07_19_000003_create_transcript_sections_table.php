<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transcript_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transcript_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->longText('text');
            $table->longText('cleaned_text')->nullable();
            $table->unsignedInteger('started_at_ms')->nullable();
            $table->unsignedInteger('ended_at_ms')->nullable();
            $table->timestamps();

            $table->unique(['transcript_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcript_sections');
    }
};
