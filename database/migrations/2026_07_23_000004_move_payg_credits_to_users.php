<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('credit_seconds')->default(0)->after('plan');
        });

        if (Schema::hasColumn('usage_records', 'seconds_purchased')) {
            DB::table('usage_records')
                ->select('user_id', DB::raw('SUM(seconds_purchased) as credit_seconds'))
                ->groupBy('user_id')
                ->orderBy('user_id')
                ->get()
                ->each(function (object $row): void {
                    DB::table('users')
                        ->where('id', $row->user_id)
                        ->increment('credit_seconds', (int) $row->credit_seconds);
                });

            Schema::table('usage_records', function (Blueprint $table): void {
                $table->dropColumn('seconds_purchased');
            });
        }
    }

    public function down(): void
    {
        Schema::table('usage_records', function (Blueprint $table): void {
            $table->unsignedInteger('seconds_purchased')->default(0)->after('seconds_transcribed');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('credit_seconds');
        });
    }
};
