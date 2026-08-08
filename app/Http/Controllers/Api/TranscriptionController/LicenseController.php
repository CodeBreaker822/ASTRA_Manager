<?php

namespace App\Http\Controllers\Api\TranscriptionController;

use App\Models\API;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use App\Services\Transcription\AppSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class LicenseController extends BaseTranscriptionApiController
{
    private const MAX_TRANSCRIBE_BATCH_DURATION_MS = 20 * 60 * 1000;

    private const MAX_TRANSCRIBE_BATCH_CLIPS = 20;

    public function licenseStatus(Request $request, AppSettingsService $settings, EntitlementService $entitlements): JsonResponse
    {
        $startedAt = microtime(true);
        $token = $request->bearerToken();
        $license = is_string($token) && $token !== ''
            ? API::findByPlainToken($token)
            : null;

        if (! $license) {
            return $this->logAndReturn($request, 'license_status', null, response()->json([
                'valid' => false,
                'active' => false,
                'expired' => false,
                'rate_limited' => false,
                'message' => 'Invalid or missing license key.',
            ], 401), $startedAt);
        }

        $canPost = (bool) $license->can_post && (bool) $license->is_active;
        $rateLimited = RateLimiter::tooManyAttempts($this->rateLimitKey($license), self::RATE_LIMIT_PER_MINUTE);
        $transcriptionProvider = $settings->publicProviderCapability('transcriber');
        $polishingProvider = $settings->publicProviderCapability('text_fixer');

        $response = response()->json([
            'valid' => true,
            'active' => (bool) $license->is_active,
            'expired' => false,
            'rate_limited' => $rateLimited,
            'app_name' => $license->app_name,
            'rate_limit' => [
                'limit_per_minute' => self::RATE_LIMIT_PER_MINUTE,
                'retry_after' => $rateLimited ? RateLimiter::availableIn($this->rateLimitKey($license)) : 0,
            ],
            'allowed_methods' => [
                'post' => (bool) $license->can_post,
                'get' => (bool) $license->can_get,
                'put' => (bool) $license->can_put,
                'patch' => (bool) $license->can_patch,
                'delete' => (bool) $license->can_delete,
            ],
            'apis' => [
                'license_status' => [
                    'method' => 'GET',
                    'path' => '/api/license/status',
                    'allowed' => true,
                ],
                'transcribe' => [
                    'method' => 'POST',
                    'path' => '/api/transcribe',
                    'allowed' => $canPost && ! $rateLimited && $transcriptionProvider['connected'],
                    'providers' => [AppSettingsService::PUBLIC_PROVIDER_ID],
                    'supports_batch' => true,
                    'max_batch_clips' => self::MAX_TRANSCRIBE_BATCH_CLIPS,
                    'max_batch_duration_ms' => self::MAX_TRANSCRIBE_BATCH_DURATION_MS,
                    'max_batch_duration_minutes' => intdiv(self::MAX_TRANSCRIBE_BATCH_DURATION_MS, 60 * 1000),
                    'fields' => [
                        'audio',
                        'language_code',
                        'clip_index',
                        'clip_start_ms',
                        'clip_end_ms',
                    ],
                ],
                'polish' => [
                    'method' => 'POST',
                    'path' => '/api/polish',
                    'allowed' => $canPost && ! $rateLimited && $polishingProvider['connected'],
                    'provider' => AppSettingsService::PUBLIC_PROVIDER_ID,
                    'model' => AppSettingsService::PUBLIC_MODEL,
                    'providers' => [AppSettingsService::PUBLIC_PROVIDER_ID],
                    'fields' => [
                        'text',
                        'timestamps',
                        'chunks',
                        'instruction',
                        'task',
                    ],
                ],
            ],
            'providers' => [
                'transcription' => [$transcriptionProvider],
                'polishing' => [$polishingProvider],
            ],
            ...($license->user ? ['account' => $this->accountPayload($license->user, $entitlements)] : []),
        ]);

        return $this->logAndReturn($request, 'license_status', $license, $response, $startedAt, [
            'status' => ! $license->is_active ? 'denied' : ($rateLimited ? 'rate_limited' : 'success'),
            'severity' => ! $license->is_active ? 'critical' : ($rateLimited ? 'high' : 'low'),
        ]);
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
