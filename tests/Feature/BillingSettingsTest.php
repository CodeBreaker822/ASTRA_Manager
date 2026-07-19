<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('billing settings require authentication', function () {
    $this->get(route('billing.edit'))
        ->assertRedirect(route('login'));
});

test('verified users can view billing settings with plan usage', function () {
    $this->withoutVite();

    $user = User::factory()->create(['plan' => 'pro']);

    $user->usageRecords()->create([
        'period' => now()->format('Y-m'),
        'seconds_transcribed' => 125,
        'polish_count' => 2,
        'summary_count' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('billing.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Billing')
            ->where('entitlements.plan.key', 'pro')
            ->where('entitlements.usage.minutes_used', 3)
            ->has('plans', 3)
        );
});
