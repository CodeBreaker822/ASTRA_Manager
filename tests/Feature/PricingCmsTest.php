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

    $user = User::factory()->create(['plan' => 'payg']);

    expect(app(EntitlementService::class)->planFor($user))
        ->toMatchArray([
            'key' => 'payg',
            'minutes' => 600,
        ]);

    $this->get(route('price'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('marketing/Price')
            ->where('plans.0.key', 'free')
            ->where('plans.1.key', 'payg')
            ->where('content.hero.title', 'Pay as you go')
            ->where('comparison.Upload audio transcription.0', 'free')
        );
});

test('pricing managers can update tiers and comparison rows', function () {
    $this->withoutVite();

    $manager = createPricingManagerUser();
    $payload = pricingPayload();
    $payload['tiers'][0]['minutes'] = 45;
    $payload['tiers'][1]['minutes'] = 750;
    $payload['tiers'][1]['price_label'] = '$25';
    $payload['tiers'][1]['upload_price_per_hour'] = 220;
    $payload['tiers'][1]['live_price_per_hour'] = 260;
    $payload['tiers'][1]['llm_price'] = 8;
    $payload['tiers'][0]['free_polish_uses_per_day'] = 3;
    $payload['tiers'][0]['free_summary_uses_per_day'] = 3;
    $payload['tiers'][1]['polish_characters'] = 125000;
    $payload['tiers'][1]['summary_characters'] = 90000;
    $payload['tiers'][1]['polish_price_per_character'] = 0.0003;
    $payload['tiers'][1]['summary_price_per_character'] = 0.0004;
    $payload['tiers'][1]['features'][0] = '750 one-time transcription minutes';
    $payload['tiers'][1]['entitlements'] = [
        'upload' => false,
        'live' => false,
        'polish' => false,
        'summarize' => false,
        'exports' => [],
    ];
    $payload['pricingContent']['hero']['title'] = 'Managed pricing copy';
    $payload['comparisonRows'] = [
        ['label' => 'Live browser transcription', 'tier_keys' => ['payg']],
        ['label' => 'One-time minute credits', 'tier_keys' => ['payg']],
    ];

    $this->actingAs($manager)
        ->put(route('dashboard.pricing.update'), $payload)
        ->assertRedirect();

    $proTier = PlanTier::query()->where('key', 'payg')->firstOrFail();

    expect($proTier->minutes)->toBe(750)
        ->and($proTier->price_per_second)->toBe(round(220 / 3600, 8))
        ->and($proTier->upload_price_per_hour)->toBe(220.0)
        ->and($proTier->live_price_per_hour)->toBe(260.0)
        ->and($proTier->llm_price)->toBe(8.0)
        ->and($proTier->polish_characters)->toBe(125000)
        ->and($proTier->summary_characters)->toBe(90000)
        ->and($proTier->polish_price_per_character)->toBe(0.0003)
        ->and($proTier->summary_price_per_character)->toBe(0.0004)
        ->and($proTier->entitlements)->toMatchArray([
            'upload' => true,
            'live' => true,
            'polish' => true,
            'summarize' => true,
            'exports' => ['txt', 'docx', 'xlsx'],
        ]);

    $proUser = User::factory()->create(['plan' => 'payg']);

    expect(app(EntitlementService::class)->planFor($proUser)['minutes'])->toBe(750);

    $this->get(route('price'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('marketing/Price')
            ->where('plans.1.key', 'payg')
            ->where('plans.1.minutes', 750)
            ->where('plans.1.price_label', '$25')
            ->where('content.hero.title', 'Managed pricing copy')
            ->where('comparison.Live browser transcription.0', 'payg')
        );

    $this->actingAs($proUser)
        ->get(route('billing.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Billing')
            ->where('entitlements.plan.key', 'payg')
            ->where('entitlements.plan.minutes', 45)
            ->where('plans.1.key', 'payg')
            ->where('plans.1.upload_price_per_hour', 220)
            ->where('plans.1.live_price_per_hour', 260)
            ->where('plans.1.llm_price', 8)
            ->where('plans.1.polish_characters', 125000)
            ->where('plans.1.summary_characters', 90000)
            ->where('plans.1.polish_price_per_character', 0.0003)
            ->where('plans.1.summary_price_per_character', 0.0004)
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
                'upload_price_per_hour' => $tier['upload_price_per_hour'],
                'live_price_per_hour' => $tier['live_price_per_hour'],
                'llm_price' => $tier['llm_price'],
                'polish_price_per_character' => $tier['polish_price_per_character'],
                'summary_price_per_character' => $tier['summary_price_per_character'],
                'minutes' => $tier['minutes'],
                'free_polish_uses_per_day' => $tier['free_polish_uses_per_day'],
                'free_summary_uses_per_day' => $tier['free_summary_uses_per_day'],
                'polish_characters' => $tier['polish_characters'],
                'summary_characters' => $tier['summary_characters'],
                'cta' => $tier['cta'],
                'featured' => $tier['featured'],
                'features' => $tier['features'],
                'entitlements' => [
                    'upload' => (bool) data_get($tier, 'entitlements.upload', false),
                    'live' => (bool) data_get($tier, 'entitlements.live', false),
                    'polish' => (bool) data_get($tier, 'entitlements.polish', false),
                    'summarize' => (bool) data_get($tier, 'entitlements.summarize', false),
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
