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
        $content = $pages->page('home');
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

    public function features(
        PageContentService $pages,
        PlanService $plans,
        MarketingSeoService $seo,
    ): Response {
        $content = $pages->page('features');

        return Inertia::render('marketing/Features', [
            'content' => $content,
            'pricing' => $plans->marketingSummary(),
            'seo' => $seo->metadata(
                $content,
                route('features'),
                $seo->breadcrumbStructuredData('Features', route('features')),
            ),
        ]);
    }

    public function price(
        PlanService $plans,
        PageContentService $pages,
        MarketingSeoService $seo,
    ): Response {
        $content = $pages->page('pricing');

        return Inertia::render('marketing/Price', [
            'plans' => $plans->tiersForDisplay(),
            'comparison' => $plans->comparison(),
            'content' => $content,
            'seo' => $seo->metadata(
                [
                    'seo' => [
                        'title' => 'Affordable Pay-As-You-Go Transcription Pricing | JERVA',
                        'description' => 'Compare JERVA online transcription rates, free daily minutes, and pay-as-you-go credits. No monthly or yearly subscription is required.',
                    ],
                ],
                route('price'),
                $seo->breadcrumbStructuredData('Pricing', route('price')),
            ),
        ]);
    }
}
