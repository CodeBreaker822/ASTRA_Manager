<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcripts', function (Blueprint $table): void {
            $table->json('polish_history')->nullable()->after('cleaned_text');
        });
    }

    public function down(): void
    {
        Schema::table('transcripts', function (Blueprint $table): void {
            $table->dropColumn('polish_history');
        });
    }
};
