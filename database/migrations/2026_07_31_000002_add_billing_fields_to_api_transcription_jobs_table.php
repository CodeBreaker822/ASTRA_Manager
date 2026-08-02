<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('api_transcription_jobs', 'billing_feature')) {
            Schema::table('api_transcription_jobs', function (Blueprint $table): void {
                $table->string('billing_feature', 16)->nullable()->after('request_payload');
            });
        }

        if (! Schema::hasColumn('api_transcription_jobs', 'billing_seconds')) {
            Schema::table('api_transcription_jobs', function (Blueprint $table): void {
                $table->unsignedInteger('billing_seconds')->default(0)->after('billing_feature');
            });
        }

        if (! Schema::hasColumn('api_transcription_jobs', 'billed_at')) {
            Schema::table('api_transcription_jobs', function (Blueprint $table): void {
                $table->timestamp('billed_at')->nullable()->after('billing_seconds');
            });
        }

        if (! Schema::hasIndex('api_transcription_jobs', ['billed_at'])) {
            Schema::table('api_transcription_jobs', function (Blueprint $table): void {
                $table->index('billed_at');
            });
        }
    }

    public function down(): void
    {
        Schema::table('api_transcription_jobs', function (Blueprint $table) {
            $table->dropIndex(['billed_at']);
            $table->dropColumn(['billing_feature', 'billing_seconds', 'billed_at']);
        });
    }
};
