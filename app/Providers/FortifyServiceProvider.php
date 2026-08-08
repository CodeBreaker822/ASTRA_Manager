<?php

namespace App\Providers;

use App\Actions\Fortify\AuthenticateUser;
use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use App\Services\DashboardAccessService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, function () {
            return new class implements LoginResponseContract
            {
                public function toResponse($request)
                {
                    $home = app(DashboardAccessService::class)->canAccess($request->user())
                        ? route('dashboard', absolute: false)
                        : route('workspace.index', absolute: false);

                    return redirect()->intended($home);
                }
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = app(AuthenticateUser::class)->authenticate(
                (string) $request->input(Fortify::username()),
                (string) $request->input('password'),
            );

            if ($user && in_array($user->user_status, ['banned', 'deactivated'], true)) {
                return null;
            }

            return $user;
        });

        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => view('auth.login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'googleAuthAvailable' => $this->googleAuthAvailable(),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => view('auth.forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => view('auth.verify-email', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => view('auth.register', [
            'googleAuthAvailable' => $this->googleAuthAvailable(),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));

        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by(
                (string) ($request->session()->get('login.id') ?? $request->ip())
            );
        });

        RateLimiter::for('login', function (Request $request) {
            $email = Str::transliterate(
                Str::lower((string) $request->input(Fortify::username()))
            );

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                Limit::perHour(50)->by($request->ip()),
            ];
        });

        RateLimiter::for('desktop-login', function (Request $request) {
            $email = Str::transliterate(
                Str::lower((string) $request->input('email'))
            );

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                Limit::perHour(50)->by($request->ip()),
            ];
        });

        RateLimiter::for('password.reset', function (Request $request) {
            return Limit::perMinute(5)->by(
                Str::lower((string) ($request->input('email') ?? $request->ip()))
            );
        });

        RateLimiter::for('email.verify', function (Request $request) {
            $identifier = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(6)->by((string) $identifier);
        });

    }

    private function googleAuthAvailable(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }
}
