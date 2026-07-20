<?php

namespace App\Http\Middleware;

use App\Services\DashboardAccessService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $canAccessDashboard = app(DashboardAccessService::class)->canAccess($user);
        $canManageApi = $user?->can('API-manage_api') ?? false;
        $canManageUsers = $user?->can('user.manage-users') ?? false;
        $canManagePermissions = $user?->can('user.manage-permissions') ?? false;
        $canManageBlog = $user?->can('cms.manage-blog') ?? false;
        $canManagePricing = $user?->can('cms.manage-pricing') ?? false;
        $canManagePages = $user?->can('cms.manage-pages') ?? false;
        $canViewCms = $user?->can('cms.view') ?? false;

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'isAdmin' => $canAccessDashboard,
                'canManageApi' => $canManageApi,
                'canManageUsers' => $canManageUsers,
                'canManagePermissions' => $canManagePermissions,
                'canAccessDashboard' => $canAccessDashboard,
                'canManageBlog' => $canManageBlog,
                'canManagePricing' => $canManagePricing,
                'canManagePages' => $canManagePages,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
