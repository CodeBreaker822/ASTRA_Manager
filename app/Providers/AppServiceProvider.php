<?php

namespace App\Providers;

use App\Gates\APIManagerGates;
use App\Gates\CmsGates;
use App\Gates\UserGates;
use App\Models\Transcript;
use App\Models\TranscriptProject;
use App\Models\User;
use App\Policies\TranscriptPolicy;
use App\Policies\TranscriptProjectPolicy;
use Carbon\CarbonImmutable;
use GuzzleHttp\Utils as GuzzleUtils;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
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

    protected function configureHttpCertificateAuthority(): void
    {
        $configuredCaBundle = $this->configuredCertificateAuthorityBundle();

        if ($configuredCaBundle !== null) {
            Http::globalOptions([
                'verify' => $configuredCaBundle,
            ]);

            return;
        }

        if (defined('CURLOPT_SSL_OPTIONS') && defined('CURLSSLOPT_NATIVE_CA')) {
            Http::globalOptions([
                'curl' => [
                    constant('CURLOPT_SSL_OPTIONS') => constant('CURLSSLOPT_NATIVE_CA'),
                ],
            ]);

            return;
        }

        $caBundle = $this->defaultCertificateAuthorityBundle();

        if ($caBundle !== null) {
            Http::globalOptions([
                'verify' => $caBundle,
            ]);
        }
    }

    protected function configuredCertificateAuthorityBundle(): ?string
    {
        foreach ([ini_get('curl.cainfo'), ini_get('openssl.cafile')] as $caBundle) {
            if (is_string($caBundle) && $caBundle !== '' && is_file($caBundle)) {
                return $caBundle;
            }
        }

        return null;
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
