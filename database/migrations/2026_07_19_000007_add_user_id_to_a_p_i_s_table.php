<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('a_p_i_s', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('id')->unique()->constrained('users')->cascadeOnDelete();
        });

        DB::table('users')->select('id')->orderBy('id')->each(function (object $user): void {
            if (DB::table('a_p_i_s')->where('user_id', $user->id)->exists()) {
                return;
            }

            do {
                $token = 'is_license_'.bin2hex(random_bytes(48));
            } while (DB::table('a_p_i_s')->where('app_token', $token)->exists());

            DB::table('a_p_i_s')->insert([
                'user_id' => $user->id,
                'app_name' => 'web-user-'.Str::uuid(),
                'app_token' => $token,
                'can_post' => 1,
                'can_get' => 1,
                'can_put' => 0,
                'can_patch' => 0,
                'can_delete' => 0,
                'blacklisted_ips' => null,
                'blacklisted_routes' => null,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('a_p_i_s', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
