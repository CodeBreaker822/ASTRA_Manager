<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_transcription_jobs', function (Blueprint $table) {
            $table->string('billing_feature', 16)->nullable()->after('request_payload');
            $table->unsignedInteger('billing_seconds')->default(0)->after('billing_feature');
            $table->timestamp('billed_at')->nullable()->after('billing_seconds')->index();
        });
    }

    public function down(): void
    {
        Schema::table('api_transcription_jobs', function (Blueprint $table) {
            $table->dropIndex(['billed_at']);
            $table->dropColumn(['billing_feature', 'billing_seconds', 'billed_at']);
        });
    }
};
