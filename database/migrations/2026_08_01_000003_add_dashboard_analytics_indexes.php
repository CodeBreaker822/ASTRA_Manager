<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_transactions', function (Blueprint $table): void {
            $table->index(
                ['plan', 'currency', 'status', 'paid_at'],
                'billing_sales_period_index',
            );
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->index('created_at', 'users_created_at_analytics_index');
        });
    }

    public function down(): void
    {
        Schema::table('billing_transactions', function (Blueprint $table): void {
            $table->dropIndex('billing_sales_period_index');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_created_at_analytics_index');
        });
    }
};
