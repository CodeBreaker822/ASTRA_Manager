<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use App\Models\User;
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

        Gate::define('API-manage_api', fn (User $user): bool => $this->isConfiguredAdmin($user));
        Gate::define('delete-api_manager', fn (User $user): bool => $this->isConfiguredAdmin($user));
        Gate::define('viewAny', fn (User $user, string $model): bool => $model === User::class && $this->isConfiguredAdmin($user));
        Gate::define('create', fn (User $user, string $model): bool => $model === User::class && $this->isConfiguredAdmin($user));
        Gate::define('update', fn (User $user, mixed $model): bool => $model instanceof User && $this->isConfiguredAdmin($user));
        Gate::define('delete', fn (User $user, mixed $model): bool => $model instanceof User && $this->isConfiguredAdmin($user));

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
