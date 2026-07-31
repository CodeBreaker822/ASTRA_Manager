<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\LicenseKeyService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request, LicenseKeyService $licenses, EntitlementService $entitlements): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $email = Str::lower((string) $validated['email']);
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check((string) $validated['password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'These login details do not match our records.',
            ]);
        }

        if (in_array($user->user_status, ['banned', 'deactivated'], true)) {
            return response()->json([
                'message' => 'This account cannot use the desktop app.',
            ], 403);
        }

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Please verify your email before signing in.',
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
                'email' => $user->email,
            ],
            'account' => $this->accountPayload($user, $entitlements),
            'license' => [
                'key' => $license->app_token,
                'suffix' => $license->app_token_suffix,
                'active' => (bool) $license->is_active,
            ],
        ]);
    }

    public function me(Request $request, LicenseKeyService $licenses, EntitlementService $entitlements): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $license = $licenses->provisionForUser($user);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
            ],
            'account' => $this->accountPayload($user, $entitlements),
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

    /**
     * @return array<string, mixed>
     */
    private function accountPayload(User $user, EntitlementService $entitlements): array
    {
        $summary = $entitlements->summaryFor($user->refresh());
        $walletBalanceCents = (int) data_get($summary, 'usage.wallet_balance_cents', 0);

        return [
            'credits' => [
                'wallet_balance' => (float) data_get($summary, 'usage.wallet_balance', 0),
                'wallet_balance_cents' => $walletBalanceCents,
                'currency' => 'USD',
                'label' => '$'.number_format($walletBalanceCents / 100, 2),
            ],
            'entitlements' => $summary,
        ];
    }
}
