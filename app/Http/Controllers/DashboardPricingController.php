<?php

namespace App\Http\Controllers;

use App\Models\PlanComparisonRow;
use App\Models\PlanTier;
use App\Services\PageContentService;
use App\Services\PlanService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DashboardPricingController extends Controller
{
    /** Tiers the pricing form edits, in display order. */
    private const PLAN_KEYS = ['free', 'payg'];

    /** Hourly rate inputs: [field, label, placeholder, hint]. */
    private const RATE_FIELDS = [
        ['upload_price_per_hour', 'Uploaded audio price per hour ($)', '0.12', 'Example: <strong>0.12</strong> = $0.12/hour'],
        ['live_price_per_hour', 'Live recording price per hour ($)', '0.15', 'Example: <strong>0.15</strong> = $0.15/hour'],
    ];

    /** Per-character rate inputs: [field, label]. */
    private const CHAR_RATE_FIELDS = [
        ['polish_price_per_character', 'Polish price per character ($)'],
        ['summary_price_per_character', 'Summary price per character ($)'],
    ];

    /** Feature toggles stored under a tier's `entitlements`: [field, label]. */
    private const ENTITLEMENT_FIELDS = [
        ['upload', 'Upload audio transcription'],
        ['live', 'Live browser transcription'],
        ['polish', 'Transcript polishing'],
        ['summarize', 'Transcript summarization'],
    ];

    /**
     * An HTML checkbox group submits nothing when every box is cleared, so the
     * `exports` and `tier_keys` keys have to be defaulted back to empty arrays
     * before validation sees them.
     */
    private function normalizeCheckboxGroups(Request $request): void
    {
        $merge = [];
        $tiers = $request->input('tiers');

        if (is_array($tiers)) {
            foreach ($tiers as $index => $tier) {
                if (! is_array($tier)) {
                    continue;
                }

                $entitlements = is_array($tier['entitlements'] ?? null) ? $tier['entitlements'] : [];
                $exports = $entitlements['exports'] ?? null;
                $entitlements['exports'] = is_array($exports) ? array_values($exports) : [];
                $tier['entitlements'] = $entitlements;
                $tiers[$index] = $tier;
            }

            $merge['tiers'] = $tiers;
        }

        $rows = $request->input('comparisonRows');

        if (is_array($rows)) {
            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $keys = $row['tier_keys'] ?? null;
                $row['tier_keys'] = is_array($keys) ? array_values($keys) : [];
                $rows[$index] = $row;
            }

            $merge['comparisonRows'] = $rows;
        }

        // A malformed payload is left untouched so validation reports it.
        if ($merge !== []) {
            $request->merge($merge);
        }
    }

    public function edit(PlanService $plans, PageContentService $pages): View
    {
        Gate::authorize('cms.manage-pricing');

        return view('dashboard.pricing', [
            // The form keeps a fixed six feature slots so blanks stay editable.
            'tiers' => array_map(
                fn (array $tier): array => [
                    ...$tier,
                    'features' => array_slice(array_pad($tier['features'], 6, ''), 0, 6),
                ],
                $plans->tiersForDisplay(),
            ),
            'comparisonRows' => collect($plans->comparison())
                ->map(fn (array $tierKeys, string $label): array => [
                    'label' => $label,
                    'tier_keys' => $tierKeys,
                ])
                ->values(),
            'pricingContent' => $pages->page('pricing'),
            'planKeys' => self::PLAN_KEYS,
            'exportFormats' => array_keys(config('workspace.export_formats')),
            'rateFields' => self::RATE_FIELDS,
            'charRateFields' => self::CHAR_RATE_FIELDS,
            'entitlementFields' => self::ENTITLEMENT_FIELDS,
        ]);
    }

    public function update(Request $request, PlanService $plans, PageContentService $pages): RedirectResponse
    {
        Gate::authorize('cms.manage-pricing');

        $this->normalizeCheckboxGroups($request);

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
            'tiers.*.cta' => ['required', 'string', 'max:80'],
            'tiers.*.featured' => ['boolean'],
            'tiers.*.features' => ['array'],
            'tiers.*.features.*' => ['nullable', 'string', 'max:160'],
            'tiers.*.entitlements' => ['required', 'array'],
            'tiers.*.entitlements.upload' => ['required', 'boolean'],
            'tiers.*.entitlements.live' => ['required', 'boolean'],
            'tiers.*.entitlements.polish' => ['required', 'boolean'],
            'tiers.*.entitlements.summarize' => ['required', 'boolean'],
            'tiers.*.entitlements.exports' => ['present', 'array'],
            'tiers.*.entitlements.exports.*' => ['string', Rule::in(['txt', 'docx', 'xlsx'])],
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

        foreach (array_values($validated['tiers']) as $index => $tier) {
            if ($tier['key'] !== 'payg') {
                continue;
            }

            foreach (['upload_price_per_hour', 'live_price_per_hour', 'polish_price_per_character', 'summary_price_per_character'] as $field) {
                if ((float) $tier[$field] <= 0) {
                    throw ValidationException::withMessages([
                        "tiers.{$index}.{$field}" => 'Pay-as-you-go paid rates must be greater than zero.',
                    ]);
                }
            }
        }

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
                        'upload_price_per_hour' => $tier['upload_price_per_hour'],
                        'live_price_per_hour' => $tier['live_price_per_hour'],
                        'llm_price' => $tier['llm_price'],
                        'polish_price_per_character' => $tier['polish_price_per_character'],
                        'summary_price_per_character' => $tier['summary_price_per_character'],
                        'minutes' => $tier['minutes'],
                        'free_polish_uses_per_day' => $tier['free_polish_uses_per_day'],
                        'free_summary_uses_per_day' => $tier['free_summary_uses_per_day'],
                        'cta' => $tier['cta'],
                        'featured' => (bool) ($tier['featured'] ?? false),
                        'features' => array_values(array_filter($tier['features'] ?? [], fn (?string $feature): bool => filled($feature))),
                        'entitlements' => [
                            'upload' => (bool) $tier['entitlements']['upload'],
                            'live' => (bool) $tier['entitlements']['live'],
                            'polish' => (bool) $tier['entitlements']['polish'],
                            'summarize' => (bool) $tier['entitlements']['summarize'],
                            'exports' => array_values($tier['entitlements']['exports']),
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
