<?php

namespace App\Http\Controllers\Api\TranscriptionController;

use App\Http\Controllers\Controller;
use App\Models\API;
use App\Services\Transcription\TranscriptionApiRequestLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

abstract class BaseTranscriptionApiController extends Controller
{
    protected const RATE_LIMIT_PER_MINUTE = 120;

    protected function licenseFor(Request $request, string $method): API|JsonResponse
    {
        $token = $request->bearerToken();

        if (! is_string($token) || $token === '') {
            return response()->json(['message' => 'Missing Bearer license key.'], 401);
        }

        $license = API::findByPlainToken($token);

        if (! $license) {
            return response()->json(['message' => 'Invalid license key.'], 401);
        }

        if (! $license->is_active) {
            return response()->json(['message' => 'License key is inactive.'], 403);
        }

        $methodColumn = 'can_'.$method;

        if (! (bool) $license->{$methodColumn}) {
            return response()->json(['message' => 'License key cannot use '.strtoupper($method).' requests.'], 403);
        }

        if ($this->isBlocked($request, $license)) {
            return response()->json(['message' => 'License key is blocked for this client or route.'], 403);
        }

        return $license;
    }

    protected function hitRateLimit(API $license): ?JsonResponse
    {
        $key = $this->rateLimitKey($license);

        if (RateLimiter::tooManyAttempts($key, self::RATE_LIMIT_PER_MINUTE)) {
            return response()->json([
                'message' => 'License key is rate-limited.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        RateLimiter::hit($key, 60);

        return null;
    }

    protected function rateLimitKey(API $license): string
    {
        return 'transcription-api-license:'.$license->id;
    }

    protected function validationError(ValidationException $exception): JsonResponse
    {
        $message = collect($exception->errors())->flatten()->first()
            ?: 'The given data was invalid.';

        return response()->json([
            'message' => $message,
            'errors' => $exception->errors(),
        ], 422);
    }

    protected function logAndReturn(
        Request $request,
        string $operation,
        ?API $license,
        JsonResponse $response,
        float $startedAt,
        array $context = [],
    ): JsonResponse {
        app(TranscriptionApiRequestLogger::class)->record(
            $request,
            $operation,
            $license,
            $response,
            $startedAt,
            $context,
        );

        return $response;
    }

    private function isBlocked(Request $request, API $license): bool
    {
        $blockedIps = $this->normalizeList($license->blacklisted_ips);
        $blockedRoutes = $this->normalizeList($license->blacklisted_routes);
        $path = '/'.$request->path();

        return in_array($request->ip(), $blockedIps, true)
            || in_array($path, $blockedRoutes, true)
            || in_array($request->path(), $blockedRoutes, true);
    }

    /**
     * @return array<int, string>
     */
    private function normalizeList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $item): string => trim((string) $item),
            $value,
        )));
    }
}
