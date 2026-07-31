<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DashboardAccessService;
use App\Services\LicenseKeyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

class GoogleAuthController extends Controller
{
    public function redirect(Request $request): RedirectResponse|SymfonyRedirectResponse
    {
        if (! $this->isConfigured()) {
            return redirect()
                ->route('login')
                ->withErrors(['google' => 'Google sign-in is not configured yet.']);
        }

        $canonicalOrigin = $this->canonicalOrigin();

        if (
            $canonicalOrigin !== null
            && strcasecmp($request->getHttpHost(), (string) parse_url($canonicalOrigin, PHP_URL_HOST).$this->canonicalPort($canonicalOrigin)) !== 0
        ) {
            return redirect()->away($canonicalOrigin.'/auth/google/redirect');
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
        } catch (InvalidStateException $exception) {
            Log::warning('Google OAuth callback state validation failed.', [
                'exception' => $exception::class,
                'callback_host' => $request->getHttpHost(),
                'configured_redirect_host' => parse_url((string) config('services.google.redirect'), PHP_URL_HOST),
                'session_has_oauth_state' => $request->session()->has('state'),
            ]);

            return redirect()
                ->route('login')
                ->withErrors([
                    'google' => 'Your Google sign-in session could not be verified. Please start again from the same JERVA address.',
                ]);
        } catch (Throwable $exception) {
            Log::warning('Google OAuth callback could not be completed.', [
                'exception' => $exception::class,
                'callback_host' => $request->getHttpHost(),
                'configured_redirect_host' => parse_url((string) config('services.google.redirect'), PHP_URL_HOST),
            ]);

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
                ->whereRaw('LOWER(email) = ?', [$email])
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

    private function canonicalOrigin(): ?string
    {
        $redirect = (string) config('services.google.redirect');
        $scheme = parse_url($redirect, PHP_URL_SCHEME);
        $host = parse_url($redirect, PHP_URL_HOST);

        if (! is_string($scheme) || ! is_string($host) || $scheme === '' || $host === '') {
            return null;
        }

        return $scheme.'://'.$host.$this->canonicalPort($redirect);
    }

    private function canonicalPort(string $url): string
    {
        $port = parse_url($url, PHP_URL_PORT);

        return is_int($port) ? ':'.$port : '';
    }
}
