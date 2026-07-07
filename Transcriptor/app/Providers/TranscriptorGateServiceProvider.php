<?php

namespace App\Providers;

use App\Gates\APIManagerGates;
use App\Gates\UserGates;
use Illuminate\Support\ServiceProvider;

class TranscriptorGateServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        UserGates::register();
        APIManagerGates::register();
    }
}

