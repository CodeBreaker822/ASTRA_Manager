<?php

use App\Models\PlanTier;
use App\Models\User;
use App\Models\UserPermissions;
use App\Models\UserPositions;
use App\Services\EntitlementService;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Cache::flush();
});

test('pricing uses config fallback when database rows are empty', function () {
    $this->withoutVite();

    $user = User::factory()->create(['plan' => 'pro']);

    expect(app(EntitlementService::class)->planFor($user))
        ->toMatchArray([
            'key' => 'pro',
            'minutes' => 600,
        ]);

    $this->get(route('price'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('marketing/Price')
            ->where('plans.0.key', 'free')
            ->where('plans.1.key', 'pro')
            ->where('content.hero.title', 'Simple pricing')
            ->where('comparison.Upload audio transcription.0', 'free')
        );
});

test('pricing managers can update tiers and comparison rows', function () {
    $this->withoutVite();

    $manager = createPricingManagerUser();
    $payload = pricingPayload();
    $payload['tiers'][1]['minutes'] = 750;
    $payload['tiers'][1]['price_label'] = '$25';
    $payload['tiers'][1]['features'][0] = '750 transcription minutes each month';
    $payload['pricingContent']['hero']['title'] = 'Managed pricing copy';
    $payload['comparisonRows'] = [
        ['label' => 'Live browser transcription', 'tier_keys' => ['pro', 'team']],
        ['label' => 'Team seats', 'tier_keys' => ['team']],
    ];

    $this->actingAs($manager)
        ->put(route('dashboard.pricing.update'), $payload)
        ->assertRedirect();

    expect(PlanTier::query()->where('key', 'pro')->firstOrFail()->minutes)->toBe(750);

    $proUser = User::factory()->create(['plan' => 'pro']);

    expect(app(EntitlementService::class)->planFor($proUser)['minutes'])->toBe(750);

    $this->get(route('price'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('marketing/Price')
            ->where('plans.1.key', 'pro')
            ->where('plans.1.minutes', 750)
            ->where('plans.1.price_label', '$25')
            ->where('content.hero.title', 'Managed pricing copy')
            ->where('comparison.Live browser transcription.0', 'pro')
        );

    $this->actingAs($proUser)
        ->get(route('billing.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Billing')
            ->where('entitlements.plan.minutes', 750)
            ->where('plans.1.key', 'pro')
        );
});

test('pricing dashboard routes require the pricing management gate', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard.pricing.edit'))
        ->assertForbidden();

    $this->actingAs($user)
        ->put(route('dashboard.pricing.update'), pricingPayload())
        ->assertForbidden();
});

function createPricingManagerUser(): User
{
    $position = UserPositions::query()->create([
        'position_code' => 'TEST_PRICING_MANAGER',
        'position_name' => 'Test Pricing Manager',
        'assigned_office' => 'web',
        'category' => 'cms',
        'description' => 'Test pricing manager position',
        'is_active' => true,
    ]);

    foreach (['cms.view', 'cms.manage-pricing'] as $permission) {
        UserPermissions::query()->create([
            'position_id' => $position->id,
            'permission_name' => $permission,
        ]);
    }

    return User::factory()->create([
        'position_id' => $position->id,
    ]);
}

/**
 * @return array<string, mixed>
 */
function pricingPayload(): array
{
    return [
        'tiers' => collect(config('plans.tiers'))
            ->map(fn (array $tier, string $key): array => [
                'key' => $key,
                'name' => $tier['name'],
                'tagline' => $tier['tagline'],
                'monthly_price' => $tier['monthly_price'],
                'yearly_price' => $tier['yearly_price'],
                'price_label' => $tier['price_label'],
                'minutes' => $tier['minutes'],
                'cta' => $tier['cta'],
                'featured' => $tier['featured'],
                'features' => $tier['features'],
                'entitlements' => [
                    'upload' => (bool) data_get($tier, 'entitlements.upload', false),
                    'live' => (bool) data_get($tier, 'entitlements.live', false),
                    'polish' => (bool) data_get($tier, 'entitlements.polish', false),
                    'summarize' => (bool) data_get($tier, 'entitlements.summarize', false),
                    'team' => (bool) data_get($tier, 'entitlements.team', false),
                    'exports' => data_get($tier, 'entitlements.exports', []),
                ],
            ])
            ->values()
            ->all(),
        'comparisonRows' => collect(config('plans.comparison'))
            ->map(fn (array $tierKeys, string $label): array => [
                'label' => $label,
                'tier_keys' => $tierKeys,
            ])
            ->values()
            ->all(),
        'pricingContent' => config('marketing.pages.pricing'),
    ];
}
