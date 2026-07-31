<?php

use App\Exceptions\InsufficientWalletBalanceException;
use Database\Seeders\PlanTierSeeder;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\LicenseKeyService;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(PlanTierSeeder::class);
    Cache::flush();
});

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
            ->where('entitlements.plan.key', 'free')
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

test('license status includes account credit balance for user licenses', function () {
    $user = User::factory()->create([
        'plan' => 'free',
        'wallet_balance' => 12.50,
    ]);

    $license = app(LicenseKeyService::class)->provisionForUser($user);

    $this
        ->withToken($license->app_token)
        ->getJson('/api/license/status')
        ->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('account.credits.wallet_balance', 12.5)
        ->assertJsonPath('account.credits.wallet_balance_cents', 1250)
        ->assertJsonPath('account.credits.label', '$12.50')
        ->assertJsonPath('account.entitlements.plan.key', 'free');
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

    expect((float) $user->refresh()->wallet_balance)->toBe(10.0)
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

    expect($entitlements->canAfford($user, 'upload', 31 * 60 * 60))->toBeFalse();

    $entitlements->charge($user, 'upload', 31 * 60 * 60);
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

    expect((float) $user->refresh()->wallet_balance)->toBe(1.99)
        ->and($today->refresh()->polish_count)->toBe(3)
        ->and($today->summary_count)->toBe(4);
});
