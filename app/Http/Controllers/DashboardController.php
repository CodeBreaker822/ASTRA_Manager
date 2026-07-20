<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        if (! $this->canAccessDashboard($request)) {
            return redirect()->route('workspace.index');
        }

        return Inertia::render('dashboard/Index');
    }

    private function canAccessDashboard(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && (
            $user->can('cms.view')
            || $user->can('cms.manage-blog')
            || $user->can('cms.manage-pricing')
            || $user->can('cms.manage-pages')
            || $user->can('user.manage-users')
            || $user->can('API-manage_api')
        );
    }
}
