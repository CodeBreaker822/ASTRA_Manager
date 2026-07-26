<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('billing_operations');
        Schema::dropIfExists('wallet_ledger_entries');
        Schema::dropIfExists('user_wallets');

        $this->dropColumnsIfPresent('users', [
            'credit_seconds',
            'polish_credit_characters',
            'summary_credit_characters',
            'total_earned_credits',
            'total_spent_credits',
        ]);

        $this->dropColumnsIfPresent('plan_tiers', [
            'price_per_second',
            'polish_characters',
            'summary_characters',
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'total_earned_credits')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unsignedInteger('total_earned_credits')->default(0)->after('wallet_balance');
                $table->unsignedInteger('total_spent_credits')->default(0)->after('total_earned_credits');
            });
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropColumnsIfPresent(string $tableName, array $columns): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $columns = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn($tableName, $column),
        ));

        if ($columns === []) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }
};
