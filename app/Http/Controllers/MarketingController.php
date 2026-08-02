<?php

namespace App\Http\Controllers;

use App\Services\MarketingSeoService;
use App\Services\PageContentService;
use App\Services\PlanService;
use Inertia\Inertia;
use Inertia\Response;

class MarketingController extends Controller
{
    public function landing(
        PageContentService $pages,
        PlanService $plans,
        MarketingSeoService $seo,
    ): Response {
        $content = $pages->pageOrDefault('home', config('marketing.pages.home', []));
        $pricing = $plans->marketingSummary();

        return Inertia::render('marketing/Landing', [
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
    ): Response {
        $content = $pages->pageOrDefault(
            'audio_to_text',
            config('marketing.pages.audio_to_text', []),
        );

        return Inertia::render('marketing/AudioToText', [
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
    ): Response {
        $content = $pages->pageOrDefault('features', config('marketing.pages.features', []));

        return Inertia::render('marketing/Features', [
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
    ): Response {
        $content = $pages->pageOrDefault('pricing', config('marketing.pages.pricing', []));
        $pricing = $plans->marketingSummary();
        $tiers = $plans->tiersForDisplay();

        throw_if($tiers === [], \RuntimeException::class, 'Public pricing is not configured.');

        return Inertia::render('marketing/Price', [
            'plans' => $tiers,
            'comparison' => $plans->comparison(),
            'currency' => $pricing['currency'],
            'content' => $content,
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
}
