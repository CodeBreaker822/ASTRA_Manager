<?php

namespace App\Http\Controllers;

use App\Models\PlanComparisonRow;
use App\Models\PlanTier;
use App\Services\PageContentService;
use App\Services\PlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DashboardPricingController extends Controller
{
    public function edit(PlanService $plans, PageContentService $pages): Response
    {
        Gate::authorize('cms.manage-pricing');

        return Inertia::render('dashboard/Pricing', [
            'tiers' => $plans->tiersForDisplay(),
            'comparisonRows' => collect($plans->comparison())
                ->map(fn (array $tierKeys, string $label): array => [
                    'label' => $label,
                    'tier_keys' => $tierKeys,
                ])
                ->values(),
            'pricingContent' => $pages->page('pricing'),
        ]);
    }

    public function update(Request $request, PlanService $plans, PageContentService $pages): RedirectResponse
    {
        Gate::authorize('cms.manage-pricing');

        $validated = $request->validate([
            'tiers' => ['required', 'array', 'min:1'],
            'tiers.*.key' => ['required', 'string', Rule::in(['free', 'pro', 'team'])],
            'tiers.*.name' => ['required', 'string', 'max:80'],
            'tiers.*.tagline' => ['required', 'string', 'max:180'],
            'tiers.*.monthly_price' => ['nullable', 'integer', 'min:0'],
            'tiers.*.yearly_price' => ['nullable', 'integer', 'min:0'],
            'tiers.*.price_label' => ['required', 'string', 'max:40'],
            'tiers.*.minutes' => ['required', 'integer', 'min:0'],
            'tiers.*.cta' => ['required', 'string', 'max:80'],
            'tiers.*.featured' => ['boolean'],
            'tiers.*.features' => ['array'],
            'tiers.*.features.*' => ['nullable', 'string', 'max:160'],
            'tiers.*.entitlements.upload' => ['boolean'],
            'tiers.*.entitlements.live' => ['boolean'],
            'tiers.*.entitlements.polish' => ['boolean'],
            'tiers.*.entitlements.summarize' => ['boolean'],
            'tiers.*.entitlements.team' => ['boolean'],
            'tiers.*.entitlements.exports' => ['array'],
            'tiers.*.entitlements.exports.*' => ['string', Rule::in(['txt', 'docx', 'xlsx'])],
            'comparisonRows' => ['array'],
            'comparisonRows.*.label' => ['required', 'string', 'max:120'],
            'comparisonRows.*.tier_keys' => ['array'],
            'comparisonRows.*.tier_keys.*' => ['string', Rule::in(['free', 'pro', 'team'])],
            'pricingContent.hero.eyebrow' => ['required', 'string', 'max:80'],
            'pricingContent.hero.title' => ['required', 'string', 'max:180'],
            'pricingContent.hero.intro' => ['required', 'string', 'max:500'],
            'pricingContent.faq' => ['array', 'max:6'],
            'pricingContent.faq.*.question' => ['required', 'string', 'max:180'],
            'pricingContent.faq.*.answer' => ['required', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($validated): void {
            foreach (array_values($validated['tiers']) as $index => $tier) {
                PlanTier::query()->updateOrCreate(
                    ['key' => $tier['key']],
                    [
                        'name' => $tier['name'],
                        'tagline' => $tier['tagline'],
                        'monthly_price' => $tier['monthly_price'] ?? null,
                        'yearly_price' => $tier['yearly_price'] ?? null,
                        'price_label' => $tier['price_label'],
                        'minutes' => $tier['minutes'],
                        'cta' => $tier['cta'],
                        'featured' => (bool) ($tier['featured'] ?? false),
                        'features' => array_values(array_filter($tier['features'] ?? [], fn (?string $feature): bool => filled($feature))),
                        'entitlements' => [
                            'upload' => (bool) data_get($tier, 'entitlements.upload', false),
                            'live' => (bool) data_get($tier, 'entitlements.live', false),
                            'polish' => (bool) data_get($tier, 'entitlements.polish', false),
                            'summarize' => (bool) data_get($tier, 'entitlements.summarize', false),
                            'exports' => array_values((array) data_get($tier, 'entitlements.exports', [])),
                            'team' => (bool) data_get($tier, 'entitlements.team', false),
                        ],
                        'sort_order' => $index,
                        'is_active' => true,
                    ],
                );
            }

            PlanComparisonRow::query()->delete();

            foreach (array_values($validated['comparisonRows'] ?? []) as $index => $row) {
                PlanComparisonRow::query()->create([
                    'label' => $row['label'],
                    'tier_keys' => array_values($row['tier_keys'] ?? []),
                    'sort_order' => $index,
                ]);
            }
        });

        $plans->forget();
        $pages->save('pricing', $validated['pricingContent'], $request->user()?->id);

        return back()->with('success', 'Pricing saved.');
    }
}
