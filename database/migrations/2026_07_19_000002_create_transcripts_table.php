<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transcripts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('transcript_projects')->cascadeOnDelete();
            $table->string('source')->default('upload');
            $table->string('status')->default('draft');
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->longText('raw_text')->nullable();
            $table->longText('cleaned_text')->nullable();
            $table->longText('summary_text')->nullable();
            $table->string('audio_path')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcripts');
    }
};
