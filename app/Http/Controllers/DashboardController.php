<?php

namespace App\Http\Controllers;

use App\Services\DashboardAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request, DashboardAccessService $dashboardAccess): Response|RedirectResponse
    {
        if (! $dashboardAccess->canAccess($request->user())) {
            return redirect()->route('workspace.index');
        }

        return Inertia::render('dashboard/Index');
    }
}
