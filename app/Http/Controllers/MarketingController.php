<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class MarketingController extends Controller
{
    public function landing(): Response
    {
        return Inertia::render('marketing/Landing');
    }

    public function features(): Response
    {
        return Inertia::render('marketing/Features');
    }

    public function price(): Response
    {
        return Inertia::render('marketing/Price', [
            'plans' => array_values(config('plans.tiers', [])),
            'comparison' => config('plans.comparison', []),
        ]);
    }
}
