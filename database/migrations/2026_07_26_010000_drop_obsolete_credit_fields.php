<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop obsolete credit fields from users and plan_tiers tables
     *
     * These fields are replaced by users.wallet_balance:
     * - users.credit_seconds
     * - users.polish_credit_characters
     * - users.summary_credit_characters
     * - plan_tiers.price_per_second -> removed (use hourly rates instead)
     * - plan_tiers.polish_characters -> removed (use per-character pricing)
     * - plan_tiers.summary_characters -> removed (use per-character pricing)
     */
    public function up(): void
    {
        $this->dropColumnsIfPresent('users', [
            'credit_seconds',
            'polish_credit_characters',
            'summary_credit_characters',
        ]);

        $this->dropColumnsIfPresent('plan_tiers', [
            'price_per_second',
            'polish_characters',
            'summary_characters',
        ]);
    }

    public function down(): void
    {
        // Restore columns for rollback
        if (! Schema::hasColumn('users', 'credit_seconds')) {
            Schema::table('users', function (Blueprint $table) {
                $table->integer('credit_seconds')->nullable()->default(0)->after('wallet_balance');
                $table->integer('polish_credit_characters')->nullable()->default(0)->after('credit_seconds');
                $table->integer('summary_credit_characters')->nullable()->default(0)->after('polish_credit_characters');
            });
        }

        if (! Schema::hasColumn('plan_tiers', 'price_per_second')) {
            Schema::table('plan_tiers', function (Blueprint $table) {
                $table->decimal('price_per_second', 15, 8)->nullable()->after('summary_price_per_character');
            });
        }

        if (! Schema::hasColumn('plan_tiers', 'polish_characters')) {
            Schema::table('plan_tiers', function (Blueprint $table) {
                $table->integer('polish_characters')->nullable()->default(1000)->after('monthly_price');
                $table->integer('summary_characters')->nullable()->default(1000)->after('polish_characters');
            });
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropColumnsIfPresent(string $tableName, array $columns): void
    {
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
