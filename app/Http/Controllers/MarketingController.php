<?php

namespace App\Http\Controllers;

use App\Services\MarketingSeoService;
use App\Services\PageContentService;
use App\Services\PlanService;
use App\Support\Money;
use Illuminate\Contracts\View\View;

class MarketingController extends Controller
{
    public function landing(
        PageContentService $pages,
        PlanService $plans,
        MarketingSeoService $seo,
    ): View {
        $content = $pages->pageOrDefault('home', config('marketing.pages.home', []));
        $pricing = $plans->marketingSummary();

        return view('marketing.landing', [
            'content' => $content,
            'pricing' => $pricing,
            'seo' => $seo->metadata(
                $content,
                route('home'),
                $seo->homeStructuredData($pricing),
            ),
        ]);
    }

    public function audioToText(
        PageContentService $pages,
        PlanService $plans,
        MarketingSeoService $seo,
    ): View {
        $content = $pages->pageOrDefault(
            'audio_to_text',
            config('marketing.pages.audio_to_text', []),
        );

        return view('marketing.audio-to-text', [
            'content' => $content,
            'pricing' => $plans->marketingSummary(),
            'seo' => $seo->metadata(
                $content,
                route('audio-to-text'),
                $seo->audioToTextStructuredData($content),
            ),
        ]);
    }

    public function features(
        PageContentService $pages,
        PlanService $plans,
        MarketingSeoService $seo,
    ): View {
        $content = $pages->pageOrDefault('features', config('marketing.pages.features', []));

        return view('marketing.features', [
            'content' => $content,
            'pricing' => $plans->marketingSummary(),
            'seo' => $seo->metadata(
                $content,
                route('features'),
                $seo->breadcrumbStructuredData(
                    (string) data_get($content, 'schema.breadcrumb_label', ''),
                    route('features'),
                ),
            ),
        ]);
    }

    public function price(
        PlanService $plans,
        PageContentService $pages,
        MarketingSeoService $seo,
    ): View {
        $content = $pages->pageOrDefault('pricing', config('marketing.pages.pricing', []));
        $pricing = $plans->marketingSummary();
        $tiers = $plans->tiersForDisplay();

        throw_if($tiers === [], \RuntimeException::class, 'Public pricing is not configured.');

        $labels = $content['plans_ui'];
        $currency = $pricing['currency'];

        return view('marketing.price', [
            'plans' => array_map(fn (array $tier): array => [
                ...$tier,
                'upload_rate' => $this->hourlyRate($tier['upload_price_per_hour'], $currency, $labels),
                'live_rate' => $this->hourlyRate($tier['live_price_per_hour'], $currency, $labels),
                'polish_rate' => $this->characterRate($tier['polish_price_per_character'], $currency, $labels),
                'summary_rate' => $this->characterRate($tier['summary_price_per_character'], $currency, $labels),
            ], $tiers),
            'comparison' => $plans->comparison(),
            'currency' => $currency,
            'content' => $content,
            'planLabels' => $labels,
            'seo' => $seo->metadata(
                $content,
                route('price'),
                $seo->breadcrumbStructuredData(
                    (string) data_get($content, 'schema.breadcrumb_label', ''),
                    route('price'),
                ),
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $labels
     */
    private function hourlyRate(float $amount, string $currency, array $labels): string
    {
        return Money::format($amount, $currency, 2, 2).$labels['per_hour_suffix'];
    }

    /**
     * Per-character rates are quoted per 1,000 characters, which is the unit
     * the pricing table advertises.
     *
     * @param  array<string, mixed>  $labels
     */
    private function characterRate(float $amount, string $currency, array $labels): string
    {
        return Money::format($amount * 1000, $currency, 2, 4)
            .$labels['per_thousand_characters_suffix'];
    }
}
