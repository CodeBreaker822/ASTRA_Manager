<?php

use App\Models\API;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->indexExists('a_p_i_s', 'a_p_i_s_app_token_unique')) {
            Schema::table('a_p_i_s', function (Blueprint $table): void {
                $table->dropUnique('a_p_i_s_app_token_unique');
            });
        }

        Schema::table('a_p_i_s', function (Blueprint $table): void {
            $table->text('app_token')->change();

            if (! Schema::hasColumn('a_p_i_s', 'app_token_hash')) {
                $table->string('app_token_hash', 64)->nullable()->after('app_token')->unique();
            }

            if (! Schema::hasColumn('a_p_i_s', 'app_token_suffix')) {
                $table->string('app_token_suffix', 12)->nullable()->after('app_token_hash');
            }
        });

        if (Schema::hasColumn('a_p_i_s', 'app_token_hash')
            && ! $this->indexExists('a_p_i_s', 'a_p_i_s_app_token_hash_unique')) {
            Schema::table('a_p_i_s', function (Blueprint $table): void {
                $table->unique('app_token_hash');
            });
        }

        API::query()
            ->select(['id', 'app_token', 'app_token_hash', 'app_token_suffix'])
            ->orderBy('id')
            ->each(function (API $license): void {
                if (filled($license->app_token_hash) && filled($license->app_token_suffix)) {
                    return;
                }

                $storedToken = (string) $license->getRawOriginal('app_token');

                $license->forceFill([
                    'app_token' => $storedToken,
                ])->save();
            });
    }

    public function down(): void
    {
        Schema::table('a_p_i_s', function (Blueprint $table): void {
            if ($this->indexExists('a_p_i_s', 'a_p_i_s_app_token_hash_unique')) {
                $table->dropUnique('a_p_i_s_app_token_hash_unique');
            }

            if (Schema::hasColumn('a_p_i_s', 'app_token_hash')) {
                $table->dropColumn('app_token_hash');
            }

            if (Schema::hasColumn('a_p_i_s', 'app_token_suffix')) {
                $table->dropColumn('app_token_suffix');
            }
        });
    }

    private function indexExists(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === $name);
    }
};
