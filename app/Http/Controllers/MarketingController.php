<?php

namespace App\Http\Controllers;

use App\Services\PageContentService;
use App\Services\PlanService;
use Inertia\Inertia;
use Inertia\Response;

class MarketingController extends Controller
{
    public function landing(): Response
    {
        return Inertia::render('marketing/Landing');
    }

    public function features(PageContentService $pages): Response
    {
        return Inertia::render('marketing/Features', [
            'content' => $pages->page('features'),
        ]);
    }

    public function price(PlanService $plans, PageContentService $pages): Response
    {
        return Inertia::render('marketing/Price', [
            'plans' => $plans->tiersForDisplay(),
            'comparison' => $plans->comparison(),
            'content' => $pages->page('pricing'),
        ]);
    }
}
