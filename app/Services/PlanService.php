<?php

namespace App\Services;

use App\Models\PlanComparisonRow;
use App\Models\PlanTier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class PlanService
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function tiers(): array
    {
        return Cache::rememberForever('plans.all', function (): array {
            $fallback = $this->configTiers();

            if (! Schema::hasTable('plan_tiers') || PlanTier::query()->where('is_active', true)->doesntExist()) {
                return $fallback;
            }

            return PlanTier::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->mapWithKeys(function (PlanTier $tier) use ($fallback): array {
                    $defaults = $fallback[$tier->key] ?? [];

                    return [
                        $tier->key => array_merge($defaults, [
                            'name' => $tier->name,
                            'tagline' => $tier->tagline,
                            'monthly_price' => $tier->monthly_price,
                            'yearly_price' => $tier->yearly_price,
                            'price_label' => $tier->price_label,
                            'price_per_second' => $tier->price_per_second,
                            'upload_price_per_hour' => $tier->upload_price_per_hour,
                            'live_price_per_hour' => $tier->live_price_per_hour,
                            'llm_price' => $tier->llm_price,
                            'polish_price_per_character' => $tier->polish_price_per_character,
                            'summary_price_per_character' => $tier->summary_price_per_character,
                            'minutes' => $tier->minutes,
                            'free_polish_uses_per_day' => $tier->free_polish_uses_per_day,
                            'free_summary_uses_per_day' => $tier->free_summary_uses_per_day,
                            'polish_characters' => $tier->polish_characters,
                            'summary_characters' => $tier->summary_characters,
                            'cta' => $tier->cta,
                            'featured' => $tier->featured,
                            'features' => $this->stringList($tier->features),
                            'entitlements' => array_merge((array) ($defaults['entitlements'] ?? []), (array) $tier->entitlements),
                        ]),
                    ];
                })
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function tiersForDisplay(): array
    {
        return collect($this->tiers())
            ->map(fn (array $tier, string $key): array => array_merge(['key' => $key], $tier))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function plan(string $key): ?array
    {
        return $this->tiers()[$key] ?? null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function comparison(): array
    {
        return Cache::rememberForever('plans.comparison', function (): array {
            $fallback = $this->configComparison();

            if (! Schema::hasTable('plan_comparison_rows') || PlanComparisonRow::query()->doesntExist()) {
                return $fallback;
            }

            return PlanComparisonRow::query()
                ->orderBy('sort_order')
                ->get()
                ->mapWithKeys(fn (PlanComparisonRow $row): array => [
                    $row->label => $this->stringList($row->tier_keys),
                ])
                ->all();
        });
    }

    public function defaultKey(): string
    {
        return (string) config('plans.default', 'free');
    }

    public function forget(): void
    {
        Cache::forget('plans.all');
        Cache::forget('plans.comparison');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function configTiers(): array
    {
        $tiers = config('plans.tiers', []);

        return is_array($tiers) ? $tiers : [];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function configComparison(): array
    {
        $comparison = config('plans.comparison', []);

        return is_array($comparison) ? $comparison : [];
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
