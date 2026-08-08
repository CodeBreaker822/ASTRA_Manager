<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Settings open as an overlay on whatever page you are on, driven by a
 * `?settings=<tab>` query parameter. This resolves which tab that is and the
 * links the chrome needs, so nothing has to navigate away to change a setting.
 */
class SettingsPanel
{
    /** Tabs in the order the modal lists them: [key, title, icon]. */
    private const TABS = [
        ['profile', 'Profile', 'user-round'],
        ['security', 'Security', 'shield-check'],
        ['recording', 'Recording', 'mic'],
        ['billing', 'Billing', 'credit-card'],
    ];

    /**
     * The tab the current request wants open, or null when settings are shut.
     */
    public static function activeTab(Request $request): ?string
    {
        $tab = $request->query('settings');

        return is_string($tab) && in_array($tab, array_column(self::TABS, 0), true)
            ? $tab
            : null;
    }

    /**
     * Tab links keep the current path, so closing returns you to the page you
     * opened settings from.
     *
     * @return array<int, array{key: string, title: string, icon: string, href: string, active: bool}>
     */
    public static function tabs(Request $request): array
    {
        $active = self::activeTab($request);

        return array_map(fn (array $tab): array => [
            'key' => $tab[0],
            'title' => $tab[1],
            'icon' => $tab[2],
            'href' => self::href($request, $tab[0]),
            'active' => $tab[0] === $active,
        ], self::TABS);
    }

    /**
     * The current URL with the settings parameter set, or removed when null.
     */
    public static function href(Request $request, ?string $tab): string
    {
        $query = $request->query();

        if ($tab === null) {
            unset($query['settings']);
        } else {
            $query['settings'] = $tab;
        }

        return $request->path() === '/'
            ? '/'.($query === [] ? '' : '?'.http_build_query($query))
            : '/'.$request->path().($query === [] ? '' : '?'.http_build_query($query));
    }

    /**
     * Whether the security tab may show two-factor material inline.
     *
     * The dedicated /settings/security route sits behind RequirePassword, so
     * the overlay must not hand out setup keys and recovery codes to a session
     * that has not confirmed its password recently.
     */
    public static function securityUnlocked(Request $request): bool
    {
        $confirmedAt = $request->session()->get('auth.password_confirmed_at');

        if (! is_numeric($confirmedAt)) {
            return false;
        }

        $timeout = (int) config('auth.password_timeout', 10800);

        return (time() - (int) $confirmedAt) < $timeout;
    }

    /**
     * Where the brand link and close button go once settings are dismissed.
     */
    public static function closeHref(Request $request): string
    {
        return self::href($request, null);
    }

    public static function user(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
