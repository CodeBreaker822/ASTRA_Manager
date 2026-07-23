<?php

use App\Models\User;
use App\Services\EntitlementService;
use Inertia\Testing\AssertableInertia;

test('billing settings require authentication', function () {
    $this->get(route('billing.edit'))
        ->assertRedirect(route('login'));
});

test('verified users can view billing settings with plan usage', function () {
    $this->withoutVite();

    $user = User::factory()->create([
        'plan' => 'free',
        'credit_seconds' => 600,
        'polish_credit_characters' => 5000,
        'summary_credit_characters' => 7000,
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
            ->where('entitlements.plan.key', 'payg')
            ->where('entitlements.plan.minutes', 60)
            ->where('entitlements.usage.minutes_used', 3)
            ->where('entitlements.usage.minutes_credit_balance', 10)
            ->where('entitlements.usage.free_polish_remaining', 1)
            ->where('entitlements.usage.free_summary_remaining', 2)
            ->where('entitlements.usage.polish_credit_characters', 5000)
            ->where('entitlements.usage.summary_credit_characters', 7000)
            ->where('entitlements.usage.period', now()->toDateString())
            ->has('plans', 2)
        );
});

test('free polish and summarize uses reset daily before paid characters are consumed', function () {
    $user = User::factory()->create([
        'plan' => 'free',
        'polish_credit_characters' => 1000,
        'summary_credit_characters' => 800,
    ]);
    $entitlements = app(EntitlementService::class);

    $user->usageRecords()->create([
        'period' => now()->subDay()->toDateString(),
        'polish_count' => 3,
        'summary_count' => 3,
    ]);

    expect($entitlements->canPolish($user, 500))->toBeTrue()
        ->and($entitlements->canSummarize($user, 500))->toBeTrue();

    $today = $user->usageRecords()->firstOrCreate([
        'period' => now()->toDateString(),
    ]);
    $today->update([
        'polish_count' => 3,
        'summary_count' => 3,
    ]);

    expect($entitlements->canPolish($user, 1000))->toBeTrue()
        ->and($entitlements->canPolish($user, 1001))->toBeFalse()
        ->and($entitlements->canSummarize($user, 800))->toBeTrue()
        ->and($entitlements->canSummarize($user, 801))->toBeFalse();

    $entitlements->recordPolishUsage($user, 600);
    $entitlements->recordSummaryUsage($user, 500);

    expect($user->refresh()->polish_credit_characters)->toBe(400)
        ->and($user->summary_credit_characters)->toBe(300)
        ->and($today->refresh()->polish_count)->toBe(4)
        ->and($today->summary_count)->toBe(4);
});

test('free transcription minutes reset each day and paid credits persist', function () {
    $user = User::factory()->create(['plan' => 'free']);
    $entitlements = app(EntitlementService::class);

    $user->usageRecords()->create([
        'period' => now()->subDay()->toDateString(),
        'seconds_transcribed' => 60 * 60,
    ]);

    expect($entitlements->canTranscribe($user, 60 * 60))->toBeTrue()
        ->and($entitlements->canTranscribe($user, (60 * 60) + 1))->toBeFalse();

    $user->update(['credit_seconds' => 10 * 60]);
    $user->usageRecords()->where('period', now()->toDateString())->first()?->update([
        'seconds_transcribed' => 65 * 60,
    ]);

    expect($entitlements->canTranscribe($user, 5 * 60))->toBeTrue()
        ->and($entitlements->canTranscribe($user, (5 * 60) + 1))->toBeFalse();
});

test('recording completed transcription usage consumes paid credits after free minutes', function () {
    $user = User::factory()->create([
        'plan' => 'free',
        'credit_seconds' => 10 * 60,
    ]);
    $entitlements = app(EntitlementService::class);

    $user->usageRecords()->create([
        'period' => now()->toDateString(),
        'seconds_transcribed' => 55 * 60,
    ]);

    $entitlements->recordTranscriptionUsage($user, 10 * 60);

    expect($user->refresh()->credit_seconds)->toBe(5 * 60)
        ->and($user->usageRecords()->where('period', now()->toDateString())->first()?->seconds_transcribed)->toBe(65 * 60);
});
