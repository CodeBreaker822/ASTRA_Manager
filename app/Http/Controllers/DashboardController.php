<?php

namespace App\Http\Controllers;

use App\Services\DashboardAccessService;
use App\Services\DashboardAnalyticsService;
use App\Support\DashboardOverview;
use App\Support\Nav;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DashboardController extends Controller
{
    public function index(
        Request $request,
        DashboardAccessService $dashboardAccess,
        DashboardAnalyticsService $analytics,
    ): View|RedirectResponse {
        if (! $dashboardAccess->canAccess($request->user())) {
            return redirect()->route('workspace.index');
        }

        $canViewAnalytics = $request->user()?->can('analytics.view') ?? false;
        $days = 30;

        if ($canViewAnalytics) {
            $validated = $request->validate([
                'days' => ['sometimes', 'integer', Rule::in(DashboardOverview::DAY_OPTIONS)],
            ]);
            $days = (int) ($validated['days'] ?? 30);
        }

        $figures = $canViewAnalytics ? $analytics->dashboard($days) : null;

        return view('dashboard.index', [
            'analytics' => $figures,
            'overview' => DashboardOverview::from($figures),
            'dayOptions' => DashboardOverview::DAY_OPTIONS,
            'shortcuts' => Nav::dashboardShortcuts($request->user()),
        ]);
    }

    public function exportUsers(DashboardAnalyticsService $analytics): StreamedResponse
    {
        Gate::authorize('analytics.view');

        return response()->streamDownload(function () use ($analytics): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $analytics->userAuditHeaders(), escape: '');

            foreach ($analytics->userAuditRows() as $row) {
                fputcsv($output, $row, escape: '');
            }

            fclose($output);
        }, 'jerva-user-billing-audit-'.now()->format('Y-m-d-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
