<?php

namespace App\Providers;

use App\Gates\APIManagerGates;
use App\Gates\CmsGates;
use App\Gates\UserGates;
use App\Http\Controllers\Settings\BillingController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Models\Transcript;
use App\Models\TranscriptProject;
use App\Models\User;
use App\Policies\TranscriptPolicy;
use App\Policies\TranscriptProjectPolicy;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\PayMongoCheckoutService;
use App\Services\Billing\PayMongoWalletTopupReconciler;
use App\Services\Billing\PlanService;
use App\Services\PageContentService;
use App\Support\FlashNotification;
use App\Support\Nav;
use App\Support\SettingsPanel;
use Carbon\CarbonImmutable;
use GuzzleHttp\Utils as GuzzleUtils;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\Rules\Password;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->shareMarketingSite();
        $this->shareNavigation();
    }

    /**
     * Resolve the navigation for the chrome components. They render in an
     * isolated scope, so the data has to be composed onto them directly rather
     * than passed down from a controller.
     */
    protected function shareNavigation(): void
    {
        // Every "Settings" link opens the overlay on the page it was clicked
        // from, so the URL keeps the current path and only gains the parameter.
        View::share('settingsHref', fn (string $tab): string => SettingsPanel::href(request(), $tab));

        View::composer('components.app.sidebar', function (ViewContract $view): void {
            $user = Auth::user();

            $view->with([
                'navGroups' => Nav::sidebarGroups($user),
                'navHome' => Nav::home($user),
                'authUser' => $user,
            ]);
        });

        View::composer('components.layouts.settings', function (ViewContract $view): void {
            $view->with([
                'settingsTabs' => Nav::settingsTabs(),
                'navHome' => Nav::home(Auth::user()),
            ]);
        });

        // Settings open over the current page, so the panel data has to be
        // resolved here rather than by whichever controller owns that page.
        View::composer('partials.settings-modal', function (ViewContract $view): void {
            $request = request();
            $tab = SettingsPanel::activeTab($request);
            $locked = $tab === 'security' && ! SettingsPanel::securityUnlocked($request);

            $view->with([
                'settingsTab' => $tab,
                'settingsTabs' => SettingsPanel::tabs($request),
                'settingsCloseHref' => SettingsPanel::closeHref($request),
                'settingsLocked' => $locked,
            ]);

            if ($tab !== null && ! $locked) {
                $view->with($this->settingsPanelData($tab, $request));
            }
        });

        // $errors only exists inside the web middleware group, so read it from
        // the view's own data rather than assuming it is bound.
        View::composer('partials.flash', function (ViewContract $view): void {
            $errors = $view->getData()['errors'] ?? null;

            $view->with('notification', FlashNotification::current(
                $errors instanceof ViewErrorBag ? $errors : null,
            ));
        });

        // Fortify reports a bad recovery code under its own key, so the
        // challenge reopens in recovery mode when that is the field that failed.
        View::composer('auth.two-factor-challenge', function (ViewContract $view): void {
            $errors = $view->getData()['errors'] ?? null;

            $view->with('startInRecovery', filled(old('recovery_code'))
                || ($errors instanceof ViewErrorBag && filled($errors->first('recovery_code'))));
        });
    }

    /**
     * Share the site chrome (brand, navigation, footer) with every blade
     * layout that renders it.
     */
    protected function shareMarketingSite(): void
    {
        // Blade components render in an isolated scope, so every view that
        // reads $site has to be listed here rather than inheriting it.
        View::composer([
            'components.layouts.*',
            'components.marketing.*',
            'partials.head',
        ], function (ViewContract $view): void {
            $view->with('site', once(fn () => app(PageContentService::class)->pageOrDefault(
                'site',
                config('marketing.pages.site', []),
            )));
        });
    }

    /**
     * Asks the owning controller for the open tab's data, so the overlay and
     * the standalone page always render from the same source.
     *
     * @return array<string, mixed>
     */
    protected function settingsPanelData(string $tab, Request $request): array
    {
        return match ($tab) {
            'profile' => app(ProfileController::class)->panelData($request),
            'security' => app(SecurityController::class)->panelData(
                app(TwoFactorAuthenticationRequest::class),
            ),
            'billing' => app(BillingController::class)->panelData(
                $request,
                app(EntitlementService::class),
                app(PayMongoCheckoutService::class),
                app(PayMongoWalletTopupReconciler::class),
                app(PlanService::class),
            ),
            default => [],
        };
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);
        $this->configureHttpCertificateAuthority();

        Gate::before(fn (User $user, string $ability): ?bool => $this->isConfiguredAdmin($user) ? true : null);

        Gate::policy(TranscriptProject::class, TranscriptProjectPolicy::class);
        Gate::policy(Transcript::class, TranscriptPolicy::class);

        UserGates::register();
        APIManagerGates::register();
        CmsGates::register();
        Gate::define('delete-api_manager', fn (User $user): bool => $user->can('API-manage_api'));
        $this->registerBillingReconciliation();

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    protected function registerBillingReconciliation(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            if (! $event->user instanceof User) {
                return;
            }

            try {
                app(PayMongoWalletTopupReconciler::class)->reconcileFor($event->user);
            } catch (Throwable $exception) {
                Log::warning('Wallet top-up reconciliation failed during login.', [
                    'user_id' => $event->user->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        });
    }

    protected function configureHttpCertificateAuthority(): void
    {
        $configuredCaBundle = $this->configuredCertificateAuthorityBundle();

        if ($configuredCaBundle !== null) {
            $this->useCertificateAuthorityBundle($configuredCaBundle, 'configured');

            return;
        }

        $windowsFallback = $this->windowsFallbackCertificateAuthorityBundle();

        if ($windowsFallback !== null) {
            $this->useCertificateAuthorityBundle($windowsFallback, 'local_windows_fallback');

            return;
        }

        if (PHP_OS_FAMILY === 'Windows' && defined('CURLOPT_SSL_OPTIONS') && defined('CURLSSLOPT_NATIVE_CA')) {
            Http::globalOptions([
                'curl' => [
                    constant('CURLOPT_SSL_OPTIONS') => constant('CURLSSLOPT_NATIVE_CA'),
                ],
            ]);

            Log::info('HTTP certificate authority selected.', [
                'source' => 'windows_native_ca',
                'ca_bundle' => null,
            ]);

            return;
        }

        $caBundle = $this->defaultCertificateAuthorityBundle();

        if ($caBundle !== null) {
            $this->useCertificateAuthorityBundle($caBundle, 'guzzle_default');

            return;
        }

        Log::warning('No HTTP certificate authority bundle could be resolved.', [
            'platform' => PHP_OS_FAMILY,
        ]);
    }

    protected function configuredCertificateAuthorityBundle(): ?string
    {
        foreach ([config('services.http.ca_bundle'), ini_get('curl.cainfo'), ini_get('openssl.cafile')] as $caBundle) {
            if (is_string($caBundle) && $caBundle !== '' && is_file($caBundle)) {
                return $caBundle;
            }
        }

        return null;
    }

    protected function windowsFallbackCertificateAuthorityBundle(): ?string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return null;
        }

        $caBundle = config('services.http.windows_fallback_ca_bundle');

        return is_string($caBundle) && $caBundle !== '' && is_file($caBundle)
            ? $caBundle
            : null;
    }

    protected function useCertificateAuthorityBundle(string $caBundle, string $source): void
    {
        Http::globalOptions([
            'verify' => $caBundle,
        ]);

        Log::info('HTTP certificate authority selected.', [
            'source' => $source,
            'ca_bundle' => $caBundle,
        ]);
    }

    protected function defaultCertificateAuthorityBundle(): ?string
    {
        try {
            $caBundle = GuzzleUtils::defaultCaBundle();
        } catch (Throwable) {
            return null;
        }

        return is_file($caBundle) ? $caBundle : null;
    }

    protected function isConfiguredAdmin(User $user): bool
    {
        if (! filter_var(config('admin.access'), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        $adminEmail = trim((string) config('admin.email'));

        return $adminEmail !== '' && strcasecmp($user->email, $adminEmail) === 0;
    }
}
