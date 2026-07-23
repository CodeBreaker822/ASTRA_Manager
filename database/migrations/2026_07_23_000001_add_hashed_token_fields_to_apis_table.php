<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('a_p_i_s', function (Blueprint $table): void {
            $table->string('app_token_hash', 64)->nullable()->after('app_token')->unique();
            $table->string('app_token_suffix', 12)->nullable()->after('app_token_hash');
        });

        DB::table('a_p_i_s')
            ->select(['id', 'app_token'])
            ->orderBy('id')
            ->each(function (object $license): void {
                $storedToken = (string) $license->app_token;

                try {
                    $token = Crypt::decryptString($storedToken);
                    $encryptedToken = $storedToken;
                } catch (Throwable) {
                    $token = $storedToken;
                    $encryptedToken = Crypt::encryptString($token);
                }

                DB::table('a_p_i_s')
                    ->where('id', $license->id)
                    ->update([
                        'app_token' => $encryptedToken,
                        'app_token_hash' => hash('sha256', $token),
                        'app_token_suffix' => Str::of($token)->substr(-12)->toString(),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('a_p_i_s', function (Blueprint $table): void {
            $table->dropUnique(['app_token_hash']);
            $table->dropColumn(['app_token_hash', 'app_token_suffix']);
        });
    }
};
