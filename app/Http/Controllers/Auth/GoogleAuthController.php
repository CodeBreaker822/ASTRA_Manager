<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DashboardAccessService;
use App\Services\LicenseKeyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse|SymfonyRedirectResponse
    {
        if (! $this->isConfigured()) {
            return redirect()
                ->route('login')
                ->withErrors(['google' => 'Google sign-in is not configured yet.']);
        }

        return Socialite::driver('google')
            ->setScopes(['openid', 'email'])
            ->redirect();
    }

    public function callback(
        Request $request,
        LicenseKeyService $licenses,
        DashboardAccessService $dashboardAccess,
    ): RedirectResponse {
        if (! $this->isConfigured()) {
            return redirect()
                ->route('login')
                ->withErrors(['google' => 'Google sign-in is not configured yet.']);
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            return redirect()
                ->route('login')
                ->withErrors(['google' => 'Google sign-in could not be completed. Please try again.']);
        }

        $email = Str::lower(trim((string) $googleUser->getEmail()));
        $rawUser = $googleUser->getRaw();
        $emailIsVerified = filter_var(
            $rawUser['email_verified'] ?? $rawUser['verified_email'] ?? false,
            FILTER_VALIDATE_BOOL,
        );

        if (
            $email === ''
            || strlen($email) > 255
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || ! $emailIsVerified
        ) {
            return redirect()
                ->route('login')
                ->withErrors(['google' => 'Google did not return a verified email address.']);
        }

        $user = User::query()->getConnection()->transaction(function () use ($email, $licenses): User {
            $user = User::query()
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            if (! $user) {
                $user = User::create([
                    'email' => $email,
                    'password' => Str::random(64),
                ]);
            }

            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            if (! in_array($user->user_status, ['banned', 'deactivated'], true)) {
                $licenses->provisionForUser($user);
            }

            return $user;
        });

        if (in_array($user->user_status, ['banned', 'deactivated'], true)) {
            return redirect()
                ->route('login')
                ->withErrors(['google' => 'This account is not available.']);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        $destination = $dashboardAccess->canAccess($user)
            ? route('dashboard', absolute: false)
            : route('workspace.index', absolute: false);

        return redirect()->intended($destination);
    }

    private function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }
}
