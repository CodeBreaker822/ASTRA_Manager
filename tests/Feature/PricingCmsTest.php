<?php

use App\Models\PlanComparisonRow;
use App\Models\PlanTier;
use App\Models\User;
use App\Models\UserPermissions;
use App\Models\UserPositions;
use App\Services\Billing\EntitlementService;
use Database\Seeders\PlanTierSeeder;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

test('pricing does not use config fallback when database rows are empty', function () {
    $this->withoutVite();
    PlanTier::query()->delete();
    PlanComparisonRow::query()->delete();
    Cache::flush();

    $user = User::factory()->create(['plan' => 'payg']);

    expect(fn () => app(EntitlementService::class)->planFor($user))
        ->toThrow(RuntimeException::class, 'Pricing plan [payg] is not configured.');

    $this->get(route('price'))
        ->assertServerError();
});

test('pricing managers can update tiers and comparison rows', function () {
    $this->withoutVite();
    $this->seed(PlanTierSeeder::class);
    Cache::flush();

    $manager = createPricingManagerUser();
    $payload = pricingPayload();
    $payload['tiers'][0]['minutes'] = 45;
    $payload['tiers'][1]['minutes'] = 750;
    $payload['tiers'][1]['price_label'] = '$25';
    $payload['tiers'][1]['upload_price_per_hour'] = 0.123456789123;
    $payload['tiers'][1]['live_price_per_hour'] = 0.153456789123;
    $payload['tiers'][1]['llm_price'] = 8;
    $payload['tiers'][0]['free_polish_uses_per_day'] = 3;
    $payload['tiers'][0]['free_summary_uses_per_day'] = 3;
    $payload['tiers'][1]['polish_price_per_character'] = 0.000005123456;
    $payload['tiers'][1]['summary_price_per_character'] = 0.000006123456;
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
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $proTier = PlanTier::query()->where('key', 'payg')->firstOrFail();

    expect($proTier->minutes)->toBe(750)
        ->and((float) $proTier->upload_price_per_hour)->toBe(0.123456789123)
        ->and((float) $proTier->live_price_per_hour)->toBe(0.153456789123)
        ->and($proTier->llm_price)->toBe(8.0)
        ->and((float) $proTier->polish_price_per_character)->toBe(0.000005123456)
        ->and((float) $proTier->summary_price_per_character)->toBe(0.000006123456)
        ->and($proTier->entitlements)->toMatchArray([
            'upload' => false,
            'live' => false,
            'polish' => false,
            'summarize' => false,
            'exports' => [],
        ]);

    $proUser = User::factory()->create(['plan' => 'payg']);

    expect(app(EntitlementService::class)->planFor($proUser)['minutes'])->toBe(750);

    $price = $this->get(route('price'))
        ->assertOk()
        ->assertViewIs('marketing.price');

    expectViewPath($price, 'plans.1.key')->toBe('payg');
    expectViewPath($price, 'plans.1.minutes')->toBe(750);
    expectViewPath($price, 'plans.1.price_label')->toBe('$25');
    expectViewPath($price, 'content.hero.title')->toBe('Managed pricing copy');
    expectViewPath($price, 'comparison.Live browser transcription.0')->toBe('payg');

    $billing = $this->actingAs($proUser)
        ->get(route('billing.edit'))
        ->assertOk()
        ->assertViewIs('settings.billing');

    expectViewPath($billing, 'entitlements.plan.key')->toBe('payg');
    expectViewPath($billing, 'entitlements.plan.minutes')->toBe(45);
    expectViewPath($billing, 'plans.1.key')->toBe('payg');
    expectViewPath($billing, 'plans.1.upload_price_per_hour')->toBe(0.123456789123);
    expectViewPath($billing, 'plans.1.live_price_per_hour')->toBe(0.153456789123);
    expectViewPath($billing, 'plans.1.llm_price')->toEqual(8);
    expectViewPath($billing, 'plans.1.polish_price_per_character')->toBe(0.000005123456);
    expectViewPath($billing, 'plans.1.summary_price_per_character')->toBe(0.000006123456);
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
