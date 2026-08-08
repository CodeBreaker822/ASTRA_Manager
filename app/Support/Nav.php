<?php

namespace App\Support;

use App\Models\User;
use App\Services\DashboardAccessService;

/**
 * Builds the navigation each chrome view renders. Permission checks and the
 * active-item match happen here so the blade files only loop and print.
 *
 * Every item is shaped ['title', 'href', 'icon', 'active'].
 */
class Nav
{
    /**
     * Grouped sidebar navigation, with empty groups removed.
     *
     * @return array<string, array<int, array{title: string, href: string, icon: string, active: bool}>>
     */
    public static function sidebarGroups(?User $user): array
    {
        $groups = [
            'Platform' => [
                ['title' => 'Workspace', 'href' => '/workspace', 'icon' => 'layout-grid'],
                self::when(self::canAccessDashboard($user), [
                    'title' => 'Dashboard', 'href' => route('dashboard'), 'icon' => 'briefcase-business',
                ]),
            ],
            'Admin Management' => [
                self::when(
                    self::can($user, 'user.manage-users') || self::can($user, 'user.manage-permissions'),
                    ['title' => 'User Management', 'href' => '/dashboard/users', 'icon' => 'users-round'],
                ),
                self::when(self::can($user, 'API-manage_api'), [
                    'title' => 'API Management', 'href' => '/dashboard/api', 'icon' => 'key-round',
                ]),
            ],
            'CMS Managers' => [
                self::when(self::can($user, 'cms.manage-blog'), [
                    'title' => 'Blog Manager', 'href' => '/dashboard/blog', 'icon' => 'newspaper',
                ]),
                self::when(self::can($user, 'cms.manage-pricing'), [
                    'title' => 'Pricing Manager', 'href' => '/dashboard/pricing', 'icon' => 'tags',
                ]),
                self::when(self::can($user, 'cms.manage-pages'), [
                    'title' => 'Page Content', 'href' => '/dashboard/pages/home', 'icon' => 'file-text',
                ]),
            ],
        ];

        return array_filter(array_map(self::mark(...), $groups));
    }

    /**
     * @return array<int, array{title: string, href: string, icon: string, active: bool}>
     */
    public static function settingsTabs(): array
    {
        return self::mark([
            ['title' => 'Profile', 'href' => route('profile.edit'), 'icon' => 'user-round'],
            ['title' => 'Security', 'href' => route('security.edit'), 'icon' => 'shield-check'],
            ['title' => 'Recording', 'href' => route('recording.edit'), 'icon' => 'mic'],
            ['title' => 'Billing', 'href' => route('billing.edit'), 'icon' => 'credit-card'],
        ]);
    }

    /**
     * Management cards on the dashboard landing page.
     *
     * @return array<int, array{title: string, body: string, href: string, icon: string}>
     */
    public static function dashboardShortcuts(?User $user): array
    {
        $shortcuts = [];

        if (self::can($user, 'cms.manage-blog')) {
            $shortcuts[] = ['title' => 'Blog', 'body' => 'Manage public posts', 'href' => '/dashboard/blog', 'icon' => 'newspaper'];
        }

        if (self::can($user, 'cms.manage-pricing')) {
            $shortcuts[] = ['title' => 'Pricing', 'body' => 'Manage plans and rates', 'href' => '/dashboard/pricing', 'icon' => 'tags'];
        }

        if (self::can($user, 'cms.manage-pages')) {
            $shortcuts[] = ['title' => 'Pages', 'body' => 'Edit public page content', 'href' => '/dashboard/pages/home', 'icon' => 'file-text'];
        }

        return $shortcuts;
    }

    public static function canAccessDashboard(?User $user): bool
    {
        return app(DashboardAccessService::class)->canAccess($user);
    }

    /**
     * Where the brand link and the settings close button go.
     */
    public static function home(?User $user): string
    {
        return self::canAccessDashboard($user) ? '/dashboard' : '/workspace';
    }

    /**
     * Drops the nulls left by permission checks and flags the current page.
     *
     * @param  array<int, array{title: string, href: string, icon: string}|null>  $items
     * @return array<int, array{title: string, href: string, icon: string, active: bool}>
     */
    private static function mark(array $items): array
    {
        $marked = [];

        foreach ($items as $item) {
            if ($item === null) {
                continue;
            }

            $marked[] = [
                'title' => $item['title'],
                'href' => $item['href'],
                'icon' => $item['icon'],
                'active' => self::isActive($item['href']),
            ];
        }

        return $marked;
    }

    private static function isActive(string $href): bool
    {
        $path = trim(parse_url($href, PHP_URL_PATH) ?: '', '/');

        return $path !== '' && (request()->is($path) || request()->is($path.'/*'));
    }

    private static function can(?User $user, string $ability): bool
    {
        return $user?->can($ability) ?? false;
    }

    /**
     * @param  array{title: string, href: string, icon: string}  $item
     * @return array{title: string, href: string, icon: string}|null
     */
    private static function when(bool $condition, array $item): ?array
    {
        return $condition ? $item : null;
    }
}
