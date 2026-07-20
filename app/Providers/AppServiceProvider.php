<?php

namespace App\Providers;

use App\Gates\APIManagerGates;
use App\Gates\CmsGates;
use App\Gates\UserGates;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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

        Gate::before(fn (User $user, string $ability): ?bool => $this->isConfiguredAdmin($user) ? true : null);

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

    protected function isConfiguredAdmin(User $user): bool
    {
        if (! filter_var(config('admin.access'), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        $adminEmail = trim((string) config('admin.email'));

        return $adminEmail !== '' && strcasecmp($user->email, $adminEmail) === 0;
    }
}
