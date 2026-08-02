<?php

use App\Models\BillingTransaction;
use App\Models\PageVisitDailyStat;
use App\Models\UsageRecord;
use App\Models\User;
use App\Models\UserPermissions;
use App\Models\UserPositions;
use App\Services\EntitlementService;
use Database\Seeders\PlanTierSeeder;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(PlanTierSeeder::class);
    Cache::flush();
});

test('dashboard reconciles paid credits against refundable balances and tracked consumption', function () {
    $manager = createDashboardAnalyticsManager();
    $customer = User::factory()->create([
        'plan' => 'free',
        'wallet_balance' => 40,
        'user_status' => 'active',
    ]);

    BillingTransaction::query()->create([
        'user_id' => $customer->id,
        'provider' => 'paymongo',
        'plan' => 'wallet_topup',
        'reference' => 'PAID-ANALYTICS-1',
        'status' => 'paid',
        'amount' => 10_000,
        'currency' => 'USD',
        'paid_at' => now(),
    ]);
    BillingTransaction::query()->create([
        'user_id' => $customer->id,
        'provider' => 'paymongo',
        'plan' => 'wallet_topup',
        'reference' => 'PENDING-ANALYTICS-1',
        'status' => 'checkout_created',
        'amount' => 2_500,
        'currency' => 'USD',
    ]);
    UsageRecord::query()->create([
        'user_id' => $customer->id,
        'period' => now()->toDateString(),
        'seconds_transcribed' => 3_600,
        'charged_cents' => 6_000,
        'upload_charged_cents' => 6_000,
    ]);
    PageVisitDailyStat::query()->create([
        'visit_date' => now()->toDateString(),
        'route_name' => 'home',
        'path' => '/',
        'path_hash' => hash('sha256', '/'),
        'total_visits' => 12,
        'guest_visits' => 12,
        'bot_visits' => 2,
    ]);

    $this->withoutVite();

    $this->actingAs($manager)
        ->get(route('dashboard', ['days' => 7]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard/Index')
            ->where('analytics.days', 7)
            ->where('analytics.sales.paid_topups_cents', 10_000)
            ->where('analytics.sales.refundable_balance_cents', 4_000)
            ->where('analytics.sales.realized_credits_cents', 6_000)
            ->where('analytics.sales.tracked_charges_cents', 6_000)
            ->where('analytics.sales.pending_topups_cents', 2_500)
            ->where('analytics.popular_pages.0.path', '/')
            ->where('analytics.popular_pages.0.human_visits', 10));

    $this->assertDatabaseHas(PageVisitDailyStat::class, [
        'route_name' => 'dashboard',
        'path' => '/dashboard',
        'authenticated_visits' => 1,
    ]);
});

test('paid usage records the exact charged cents in the existing usage row', function () {
    $user = User::factory()->create([
        'plan' => 'free',
        'wallet_balance' => 1,
    ]);
    $usage = UsageRecord::query()->create([
        'user_id' => $user->id,
        'period' => now()->toDateString(),
        'seconds_transcribed' => 60 * 60,
    ]);

    app(EntitlementService::class)->charge($user, 'upload', 60 * 60);

    expect((float) $user->refresh()->wallet_balance)->toBe(0.88)
        ->and($usage->refresh()->charged_cents)->toBe(12)
        ->and($usage->upload_charged_cents)->toBe(12)
        ->and($usage->live_charged_cents)->toBe(0);
});

test('analytics managers can export a per-user billing reconciliation csv', function () {
    $manager = createDashboardAnalyticsManager();
    $customer = User::factory()->create([
        'email' => 'billing-audit@example.com',
        'plan' => 'free',
        'wallet_balance' => 7,
    ]);

    BillingTransaction::query()->create([
        'user_id' => $customer->id,
        'provider' => 'paymongo',
        'plan' => 'wallet_topup',
        'reference' => 'PAID-EXPORT-1',
        'status' => 'paid',
        'amount' => 1_000,
        'currency' => 'USD',
        'paid_at' => now(),
    ]);
    UsageRecord::query()->create([
        'user_id' => $customer->id,
        'period' => now()->toDateString(),
        'charged_cents' => 300,
        'polish_charged_cents' => 300,
    ]);

    $response = $this->actingAs($manager)
        ->get(route('dashboard.analytics.users.export'))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();

    expect($csv)
        ->toContain('current_refundable_balance_usd')
        ->toContain('historical_or_adjustment_gap_usd')
        ->toContain('billing-audit@example.com')
        ->toContain('10.00,7.00,3.00,3.00,0.00');
});

test('users without analytics permission do not receive or export sensitive figures', function () {
    $editor = createDashboardAnalyticsManager(['cms.manage-blog']);
    $this->withoutVite();

    $this->actingAs($editor)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('analytics', null));

    $this->actingAs($editor)
        ->get(route('dashboard.analytics.users.export'))
        ->assertForbidden();
});

function createDashboardAnalyticsManager(array $permissions = ['analytics.view']): User
{
    $position = UserPositions::query()->create([
        'position_code' => 'TEST_DASHBOARD_ANALYTICS_'.str()->random(8),
        'position_name' => 'Test Dashboard Analytics '.str()->random(8),
        'assigned_office' => 'web',
        'category' => 'analytics',
        'description' => 'Dashboard analytics test manager',
        'is_active' => true,
    ]);

    foreach ($permissions as $permission) {
        UserPermissions::query()->create([
            'position_id' => $position->id,
            'permission_name' => $permission,
        ]);
    }

    return User::factory()->create([
        'position_id' => $position->id,
        'user_status' => 'active',
    ]);
}
