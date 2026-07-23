<?php

namespace App\Http\Controllers;

use App\Models\PlanComparisonRow;
use App\Models\PlanTier;
use App\Services\PageContentService;
use App\Services\PlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'tiers.*.key' => ['required', 'string', Rule::in(['free', 'payg'])],
            'tiers.*.name' => ['required', 'string', 'max:80'],
            'tiers.*.tagline' => ['required', 'string', 'max:180'],
            'tiers.*.monthly_price' => ['nullable', 'integer', 'min:0'],
            'tiers.*.yearly_price' => ['nullable', 'integer', 'min:0'],
            'tiers.*.price_label' => ['required', 'string', 'max:40'],
            'tiers.*.upload_price_per_hour' => ['required', 'numeric', 'min:0'],
            'tiers.*.live_price_per_hour' => ['required', 'numeric', 'min:0'],
            'tiers.*.llm_price' => ['required', 'numeric', 'min:0'],
            'tiers.*.polish_price_per_character' => ['required', 'numeric', 'min:0'],
            'tiers.*.summary_price_per_character' => ['required', 'numeric', 'min:0'],
            'tiers.*.minutes' => ['required', 'integer', 'min:0'],
            'tiers.*.free_polish_uses_per_day' => ['required', 'integer', 'min:0', 'max:65535'],
            'tiers.*.free_summary_uses_per_day' => ['required', 'integer', 'min:0', 'max:65535'],
            'tiers.*.polish_characters' => ['required', 'integer', 'min:0'],
            'tiers.*.summary_characters' => ['required', 'integer', 'min:0'],
            'tiers.*.cta' => ['required', 'string', 'max:80'],
            'tiers.*.featured' => ['boolean'],
            'tiers.*.features' => ['array'],
            'tiers.*.features.*' => ['nullable', 'string', 'max:160'],
            'comparisonRows' => ['array'],
            'comparisonRows.*.label' => ['required', 'string', 'max:120'],
            'comparisonRows.*.tier_keys' => ['array'],
            'comparisonRows.*.tier_keys.*' => ['string', Rule::in(['free', 'payg'])],
            'pricingContent.hero.eyebrow' => ['required', 'string', 'max:80'],
            'pricingContent.hero.title' => ['required', 'string', 'max:180'],
            'pricingContent.hero.intro' => ['required', 'string', 'max:500'],
            'pricingContent.faq' => ['array', 'max:6'],
            'pricingContent.faq.*.question' => ['required', 'string', 'max:180'],
            'pricingContent.faq.*.answer' => ['required', 'string', 'max:500'],
        ]);

        PlanTier::query()->getConnection()->transaction(function () use ($validated): void {
            foreach (array_values($validated['tiers']) as $index => $tier) {
                PlanTier::query()->updateOrCreate(
                    ['key' => $tier['key']],
                    [
                        'name' => $tier['name'],
                        'tagline' => $tier['tagline'],
                        'monthly_price' => $tier['monthly_price'] ?? null,
                        'yearly_price' => $tier['yearly_price'] ?? null,
                        'price_label' => $tier['price_label'],
                        'price_per_second' => round(((float) $tier['upload_price_per_hour']) / 3600, 8),
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
                        'featured' => (bool) ($tier['featured'] ?? false),
                        'features' => array_values(array_filter($tier['features'] ?? [], fn (?string $feature): bool => filled($feature))),
                        'entitlements' => [
                            'upload' => true,
                            'live' => true,
                            'polish' => true,
                            'summarize' => true,
                            'exports' => ['txt', 'docx', 'xlsx'],
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
