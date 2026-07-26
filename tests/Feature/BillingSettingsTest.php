<?php

use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\User;
use App\Services\EntitlementService;
use Inertia\Testing\AssertableInertia;

test('billing settings require authentication', function () {
    $this->get(route('billing.edit'))
        ->assertRedirect(route('login'));
});

test('verified users can view billing settings with credit balance', function () {
    $this->withoutVite();

    $user = User::factory()->create([
        'plan' => 'free',
        'wallet_balance' => 12.50,
    ]);

    $user->usageRecords()->create([
        'period' => now()->toDateString(),
        'seconds_transcribed' => 125,
        'polish_count' => 2,
        'summary_count' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('billing.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Billing')
            ->where('walletBalance', 1250)
            ->where('entitlements.plan.key', 'payg')
            ->where('entitlements.plan.minutes', 60)
            ->where('entitlements.usage.minutes_used', 3)
            ->where('entitlements.usage.minutes_remaining', 57)
            ->where('entitlements.usage.free_polish_remaining', 1)
            ->where('entitlements.usage.free_summary_remaining', 2)
            ->where('entitlements.usage.wallet_balance', 12.5)
            ->where('entitlements.usage.wallet_balance_cents', 1250)
            ->where('entitlements.usage.period', now()->toDateString())
            ->has('plans', 2)
        );
});

test('free transcription minutes are consumed before credit balance', function () {
    $user = User::factory()->create([
        'plan' => 'free',
        'wallet_balance' => 10.00,
    ]);
    $entitlements = app(EntitlementService::class);

    $user->usageRecords()->create([
        'period' => now()->toDateString(),
        'seconds_transcribed' => 59 * 60,
    ]);

    expect($entitlements->canAfford($user, 'upload', 120))->toBeTrue();

    $entitlements->charge($user, 'upload', 120);

    expect((float) $user->refresh()->wallet_balance)->toBe(6.83)
        ->and($user->usageRecords()->where('period', now()->toDateString())->first()?->seconds_transcribed)->toBe(61 * 60);
});

test('paid transcription fails when credit balance is not enough', function () {
    $user = User::factory()->create([
        'plan' => 'free',
        'wallet_balance' => 3.00,
    ]);
    $entitlements = app(EntitlementService::class);

    $user->usageRecords()->create([
        'period' => now()->toDateString(),
        'seconds_transcribed' => 60 * 60,
    ]);

    expect($entitlements->canAfford($user, 'upload', 60))->toBeFalse();

    $entitlements->charge($user, 'upload', 60);
})->throws(InsufficientWalletBalanceException::class);

test('free polish and summarize uses are consumed before credit balance', function () {
    $user = User::factory()->create([
        'plan' => 'free',
        'wallet_balance' => 2.00,
    ]);
    $entitlements = app(EntitlementService::class);

    $today = $user->usageRecords()->create([
        'period' => now()->toDateString(),
        'polish_count' => 2,
        'summary_count' => 3,
    ]);

    $entitlements->charge($user, 'polish', 1000);
    $entitlements->charge($user, 'summarize', 1000);

    expect((float) $user->refresh()->wallet_balance)->toBe(1.80)
        ->and($today->refresh()->polish_count)->toBe(3)
        ->and($today->summary_count)->toBe(4);
});
