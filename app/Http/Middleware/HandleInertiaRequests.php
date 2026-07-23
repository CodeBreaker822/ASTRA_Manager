<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\DashboardAccessService;
use App\Services\EntitlementService;
use App\Services\PayMongoCheckoutService;
use App\Services\PlanService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Middleware;
use Laravel\Fortify\Features;

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
            'settingsModal' => fn () => $this->settingsModal($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function settingsModal(Request $request): ?array
    {
        $user = $request->user();
        $tab = $request->query('settings');

        if (! $user instanceof User || ! is_string($tab)) {
            return null;
        }

        if (! in_array($tab, ['profile', 'security', 'appearance', 'billing'], true)) {
            return null;
        }

        return [
            'tab' => $tab,
            'profile' => [
                'mustVerifyEmail' => $user instanceof MustVerifyEmail,
                'status' => $request->session()->get('status'),
            ],
            'security' => $tab === 'security' ? $this->securitySettings($user) : null,
            'billing' => $tab === 'billing' ? $this->billingSettings($user) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function securitySettings(User $user): array
    {
        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'canManagePasskeys' => Features::canManagePasskeys(),
            'passkeys' => Features::canManagePasskeys()
                ? $user
                    ->passkeys()
                    ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
                    ->latest()
                    ->get()
                    ->map(fn ($passkey) => [
                        'id' => $passkey->id,
                        'name' => $passkey->name,
                        'authenticator' => $passkey->authenticator,
                        'created_at_diff' => $passkey->created_at->diffForHumans(),
                        'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
                    ])
                    ->values()
                    ->all()
                : [],
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $props['twoFactorEnabled'] = $user->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return $props;
    }

    /**
     * @return array<string, mixed>
     */
    private function billingSettings(User $user): array
    {
        $payMongo = app(PayMongoCheckoutService::class);

        return [
            'billing' => [
                'provider' => config('services.billing.provider'),
                'checkout_available' => $payMongo->isConfiguredFor('payg', 'audio')
                    || $payMongo->isConfiguredFor('payg', 'polish')
                    || $payMongo->isConfiguredFor('payg', 'summary'),
                'portal_available' => false,
                'paymongo_ready' => [
                    'audio' => $payMongo->isConfiguredFor('payg', 'audio'),
                    'polish' => $payMongo->isConfiguredFor('payg', 'polish'),
                    'summary' => $payMongo->isConfiguredFor('payg', 'summary'),
                ],
            ],
            'entitlements' => app(EntitlementService::class)->summaryFor($user),
            'plans' => app(PlanService::class)->tiersForDisplay(),
        ];
    }
}
