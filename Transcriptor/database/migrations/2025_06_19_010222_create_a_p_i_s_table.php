<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('a_p_i_s', function (Blueprint $table) {
            $table->id();
            $table->string('app_name')->unique();
            $table->string('app_token')->unique();
            $table->tinyInteger('can_post')->default(0);
            $table->tinyInteger('can_get')->default(0);
            $table->tinyInteger('can_put')->default(0);
            $table->tinyInteger('can_patch')->default(0);
            $table->tinyInteger('can_delete')->default(0);
            $table->json('blacklisted_ips')->nullable();
            $table->json('blacklisted_routes')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('a_p_i_s');
    }
};
