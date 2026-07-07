<?php

namespace App\Services;

use App\Models\API;
use App\Models\TranscriptionApiRequestLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class TranscriptionApiRequestLogger
{
    public function record(
        Request $request,
        string $operation,
        ?API $license,
        JsonResponse $response,
        float $startedAt,
        array $context = [],
    ): void {
        try {
            $token = $request->bearerToken();
            $payload = $response->getData(true);
            $status = $context['status'] ?? $this->statusFor($response->getStatusCode(), $payload);

            TranscriptionApiRequestLog::query()->create([
                'request_id' => (string) Str::uuid(),
                'api_id' => $license?->id,
                'app_name' => $license?->app_name,
                'license_token_prefix' => $this->tokenPrefix($token),
                'license_token_hash' => $this->tokenHash($token),
                'operation' => $operation,
                'endpoint' => '/'.$request->path(),
                'http_method' => $request->method(),
                'status' => $status,
                'severity' => $context['severity'] ?? $this->severityFor($status, $response->getStatusCode()),
                'http_status' => $response->getStatusCode(),
                'provider' => $context['provider'] ?? $this->requestString($request, 'provider'),
                'model' => $context['model'] ?? $this->responseString($payload, 'model'),
                'language_code' => $this->requestString($request, 'language_code'),
                'clip_index' => $this->requestInteger($request, 'clip_index'),
                'clip_start_ms' => $this->requestInteger($request, 'clip_start_ms'),
                'clip_end_ms' => $this->requestInteger($request, 'clip_end_ms'),
                'audio_file_name' => $this->audioFileName($request->file('audio')),
                'audio_mime_type' => $this->audioMimeType($request->file('audio')),
                'audio_size_bytes' => $this->audioSize($request->file('audio')),
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 512, ''),
                'duration_ms' => $this->durationMs($startedAt),
                'request_summary' => $this->requestSummary($request, $operation),
                'response_summary' => $this->responseSummary($payload),
                'error_message' => $this->errorMessage($payload),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Unable to write transcription API request log.', [
                'operation' => $operation,
                'path' => $request->path(),
                'status' => $response->getStatusCode(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function statusFor(int $httpStatus, array $payload = []): string
    {
        if ($httpStatus >= 200 && $httpStatus < 300) {
            return 'success';
        }

        return match ($httpStatus) {
            401 => 'invalid_license',
            403 => str_contains(strtolower((string) ($payload['message'] ?? '')), 'blocked')
                ? 'blocked'
                : 'denied',
            422 => 'validation_error',
            429 => 'rate_limited',
            default => $httpStatus >= 500 ? 'server_error' : 'provider_error',
        };
    }

    public function severityFor(string $status, int $httpStatus): string
    {
        return match ($status) {
            'success' => 'low',
            'validation_error' => 'medium',
            'provider_error', 'rate_limited' => 'high',
            'invalid_license', 'denied', 'blocked', 'server_error' => 'critical',
            default => $httpStatus >= 500 ? 'critical' : 'medium',
        };
    }

    private function requestSummary(Request $request, string $operation): array
    {
        return array_filter([
            'content_type' => $request->headers->get('content-type'),
            'accept' => $request->headers->get('accept'),
            'has_audio' => $request->hasFile('audio'),
            'chunk_count' => is_array($request->input('chunks')) ? count($request->input('chunks')) : null,
            'text_length' => is_string($request->input('text')) ? strlen((string) $request->input('text')) : null,
            'instruction_length' => is_string($request->input('instruction')) ? strlen((string) $request->input('instruction')) : null,
            'operation' => $operation,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function responseSummary(array $payload): array
    {
        return array_filter([
            'text_length' => is_string($payload['text'] ?? null) ? strlen((string) $payload['text']) : null,
            'timestamp_count' => is_array($payload['timestamps'] ?? null) ? count($payload['timestamps']) : null,
            'chunk_count' => is_array($payload['chunks'] ?? null) ? count($payload['chunks']) : null,
            'retry_after' => $payload['retry_after'] ?? ($payload['rate_limit']['retry_after'] ?? null),
            'valid' => $payload['valid'] ?? null,
            'active' => $payload['active'] ?? null,
            'rate_limited' => $payload['rate_limited'] ?? null,
            'message' => isset($payload['message']) ? Str::limit((string) $payload['message'], 255, '') : null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function errorMessage(array $payload): ?string
    {
        if (! isset($payload['message'])) {
            return null;
        }

        return Str::limit((string) $payload['message'], 255, '');
    }

    private function requestString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_scalar($value) && trim((string) $value) !== ''
            ? Str::limit(trim((string) $value), 255, '')
            : null;
    }

    private function requestInteger(Request $request, string $key): ?int
    {
        $value = $request->input($key);

        return is_numeric($value) ? (int) $value : null;
    }

    private function responseString(array $payload, string $key): ?string
    {
        return isset($payload[$key]) && is_scalar($payload[$key])
            ? Str::limit((string) $payload[$key], 255, '')
            : null;
    }

    private function audioFileName(mixed $file): ?string
    {
        return $file instanceof UploadedFile
            ? Str::limit($file->getClientOriginalName() ?: $file->getFilename(), 255, '')
            : null;
    }

    private function audioMimeType(mixed $file): ?string
    {
        return $file instanceof UploadedFile
            ? Str::limit((string) $file->getMimeType(), 120, '')
            : null;
    }

    private function audioSize(mixed $file): ?int
    {
        return $file instanceof UploadedFile ? $file->getSize() : null;
    }

    private function tokenPrefix(?string $token): ?string
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        return Str::limit($token, 24, '');
    }

    private function tokenHash(?string $token): ?string
    {
        return is_string($token) && $token !== '' ? hash('sha256', $token) : null;
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
