<?php

namespace App\Services;

use App\Models\PlanComparisonRow;
use App\Models\PlanTier;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class PlanService
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function tiers(): array
    {
        return Cache::rememberForever('plans.all', function (): array {
            return PlanTier::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->mapWithKeys(function (PlanTier $tier): array {
                    return [
                        $tier->key => [
                            'name' => $tier->name,
                            'tagline' => $tier->tagline,
                            'monthly_price' => $tier->monthly_price,
                            'yearly_price' => $tier->yearly_price,
                            'price_label' => $tier->price_label,
                            'upload_price_per_hour' => $tier->upload_price_per_hour,
                            'live_price_per_hour' => $tier->live_price_per_hour,
                            'llm_price' => $tier->llm_price,
                            'polish_price_per_character' => $tier->polish_price_per_character,
                            'summary_price_per_character' => $tier->summary_price_per_character,
                            'minutes' => $tier->minutes,
                            'free_polish_uses_per_day' => $tier->free_polish_uses_per_day,
                            'free_summary_uses_per_day' => $tier->free_summary_uses_per_day,
                            'cta' => $tier->cta,
                            'featured' => $tier->featured,
                            'features' => $this->stringList($tier->features),
                            'entitlements' => (array) $tier->entitlements,
                        ],
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
     * @return array{
     *     currency: string,
     *     free_minutes_per_day: int|null,
     *     upload_price_per_hour: float|null,
     *     live_price_per_hour: float|null
     * }
     */
    public function marketingSummary(): array
    {
        $free = $this->plan('free');
        $payg = $this->plan('payg');

        return [
            'currency' => 'USD',
            'free_minutes_per_day' => $this->positiveInt($free['minutes'] ?? null),
            'upload_price_per_hour' => $this->positiveFloat($payg['upload_price_per_hour'] ?? null),
            'live_price_per_hour' => $this->positiveFloat($payg['live_price_per_hour'] ?? null),
        ];
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
            return PlanComparisonRow::query()
                ->orderBy('sort_order')
                ->get()
                ->mapWithKeys(fn (PlanComparisonRow $row): array => [
                    $row->label => $this->stringList($row->tier_keys),
                ])
                ->all();
        });
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

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    private function positiveFloat(mixed $value): ?float
    {
        if (! is_numeric($value) || (float) $value <= 0) {
            return null;
        }

        return (float) $value;
    }

    /**
     * Get the price per unit for a specific feature.
     *
     * @param  string  $feature  Feature name (upload, live, polish, summarize)
     * @return float The rate per unit (e.g., $ per hour or $ per character)
     */
    public function ratePerUnit(string $feature): float
    {
        $paygPlan = $this->plan('payg');

        if (! is_array($paygPlan)) {
            throw new RuntimeException('Pay-as-you-go pricing is not configured. Update Pricing Manager before billing users.');
        }

        $rate = match ($feature) {
            'upload' => $paygPlan['upload_price_per_hour'] ?? null,
            'live' => $paygPlan['live_price_per_hour'] ?? null,
            'polish' => $paygPlan['polish_price_per_character'] ?? null,
            'summarize' => $paygPlan['summary_price_per_character'] ?? null,
            default => throw new \InvalidArgumentException("Unknown billing feature: {$feature}"),
        };

        if (! is_numeric($rate)) {
            throw new RuntimeException("Pay-as-you-go rate for [{$feature}] is not configured. Update Pricing Manager before billing users.");
        }

        return (float) $rate;
    }

    public function forget(): void
    {
        Cache::forget('plans.all');
        Cache::forget('plans.comparison');
    }
}
