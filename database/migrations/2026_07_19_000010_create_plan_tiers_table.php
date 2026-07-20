<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_tiers', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('tagline');
            $table->integer('monthly_price')->nullable();
            $table->integer('yearly_price')->nullable();
            $table->string('price_label');
            $table->integer('minutes');
            $table->string('cta');
            $table->boolean('featured')->default(false);
            $table->json('features');
            $table->json('entitlements');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_tiers');
    }
};
