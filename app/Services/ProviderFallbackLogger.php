<?php

namespace App\Services;

use App\Models\API;
use App\Models\TranscriptionApiRequestLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProviderFallbackLogger
{
    public function failure(
        string $category,
        string $operation,
        array $provider,
        int $position,
        Throwable $exception,
        ?Request $request = null,
        ?API $license = null,
    ): void {
        $status = $this->exceptionStatus($exception);

        $this->write([
            'category' => $category,
            'operation' => $operation,
            'provider' => $provider,
            'position' => $position,
            'request' => $request,
            'license' => $license,
            'status' => 'fallback_failed',
            'severity' => 'high',
            'http_status' => $status,
            'error_message' => $this->safeErrorMessage($status),
            'exception' => class_basename($exception),
        ]);
    }

    public function recovered(
        string $category,
        string $operation,
        array $provider,
        int $position,
        ?Request $request = null,
        ?API $license = null,
    ): void {
        if ($position === 0) {
            return;
        }

        $this->write([
            'category' => $category,
            'operation' => $operation,
            'provider' => $provider,
            'position' => $position,
            'request' => $request,
            'license' => $license,
            'status' => 'fallback_succeeded',
            'severity' => 'low',
            'http_status' => 200,
            'error_message' => null,
            'exception' => null,
        ]);
    }

    private function write(array $data): void
    {
        /** @var Request|null $request */
        $request = $data['request'];
        /** @var API|null $license */
        $license = $data['license'];
        $provider = $data['provider'];

        try {
            TranscriptionApiRequestLog::query()->create([
                'request_id' => (string) Str::uuid(),
                'api_id' => $license?->id,
                'app_name' => $license?->app_name ?? ($data['operation'] === 'chatbot' ? 'JERVA Chatbot' : null),
                'license_token_prefix' => $this->tokenPrefix($request?->bearerToken()),
                'license_token_hash' => $this->tokenHash($request?->bearerToken()),
                'operation' => $data['operation'].'_provider',
                'endpoint' => $request ? '/'.$request->path() : '/chatbot',
                'http_method' => $request?->method() ?? 'INTERNAL',
                'status' => $data['status'],
                'severity' => $data['severity'],
                'http_status' => $data['http_status'],
                'provider' => $provider['provider'] ?? null,
                'model' => $provider['model'] ?? null,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 512, '') : null,
                'request_summary' => [
                    'category' => $data['category'],
                    'fallback_position' => $data['position'] + 1,
                    'failure_type' => $data['exception'],
                ],
                'response_summary' => [
                    'continued_to_fallback' => $data['status'] === 'fallback_failed',
                    'recovered_by_fallback' => $data['status'] === 'fallback_succeeded',
                ],
                'error_message' => $data['error_message'],
            ]);
        } catch (Throwable $loggingException) {
            Log::warning('Unable to write provider fallback log.', [
                'operation' => $data['operation'],
                'provider' => $provider['provider'] ?? null,
                'model' => $provider['model'] ?? null,
                'fallback_position' => $data['position'] + 1,
                'logging_exception' => $loggingException::class,
            ]);
        }

        Log::log($data['status'] === 'fallback_failed' ? 'warning' : 'info', 'Provider fallback attempt recorded.', [
            'operation' => $data['operation'],
            'provider' => $provider['provider'] ?? null,
            'model' => $provider['model'] ?? null,
            'fallback_position' => $data['position'] + 1,
            'status' => $data['status'],
            'http_status' => $data['http_status'],
            'failure_type' => $data['exception'],
        ]);
    }

    private function safeErrorMessage(?int $status): string
    {
        return match (true) {
            in_array($status, [401, 403], true) => 'Provider rejected the configured credentials.',
            $status === 429 => 'Provider rate limit or quota was reached.',
            $status !== null && $status >= 500 => 'Provider service was unavailable.',
            $status !== null => "Provider request failed with HTTP status {$status}.",
            default => 'Provider connection or response processing failed.',
        };
    }

    private function exceptionStatus(Throwable $exception): ?int
    {
        $code = $exception->getCode();

        return is_int($code) && $code >= 100 && $code <= 599 ? $code : null;
    }

    private function tokenPrefix(?string $token): ?string
    {
        return is_string($token) && $token !== '' ? Str::limit($token, 24, '') : null;
    }

    private function tokenHash(?string $token): ?string
    {
        return is_string($token) && $token !== '' ? hash('sha256', $token) : null;
    }
}
