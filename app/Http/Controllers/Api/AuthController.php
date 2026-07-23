<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LicenseKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request, LicenseKeyService $licenses): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $email = Str::lower((string) $validated['email']);
        $rateLimitKey = 'desktop-login:'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            return response()->json([
                'message' => 'Too many login attempts. Please wait and try again.',
                'retry_after' => RateLimiter::availableIn($rateLimitKey),
            ], 429);
        }

        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check((string) $validated['password'], (string) $user->password)) {
            RateLimiter::hit($rateLimitKey, 60);

            throw ValidationException::withMessages([
                'email' => 'These login details do not match our records.',
            ]);
        }

        RateLimiter::clear($rateLimitKey);

        if (in_array($user->user_status, ['banned', 'deactivated'], true)) {
            return response()->json([
                'message' => 'This account cannot use the desktop app.',
            ], 403);
        }

        $license = $licenses->provisionForUser($user);
        $deviceName = trim((string) ($validated['device_name'] ?? 'AITranscriber'));
        $token = $user->createToken($deviceName === '' ? 'AITranscriber' : $deviceName, ['desktop'])->plainTextToken;

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'license' => [
                'key' => $license->app_token,
                'suffix' => $license->app_token_suffix,
                'active' => (bool) $license->is_active,
            ],
        ]);
    }

    public function me(Request $request, LicenseKeyService $licenses): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $license = $licenses->provisionForUser($user);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'license' => [
                'key' => $license->app_token,
                'suffix' => $license->app_token_suffix,
                'active' => (bool) $license->is_active,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }
}
