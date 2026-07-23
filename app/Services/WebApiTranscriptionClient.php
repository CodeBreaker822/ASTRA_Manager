<?php

namespace App\Services;

use App\Http\Controllers\Api\TranscriptionController as ApiTranscriptionController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class WebApiTranscriptionClient
{
    public const MAX_BATCH_CLIPS = 20;

    public const MAX_BATCH_DURATION_MS = 1_200_000;

    public function __construct(
        private readonly LicenseKeyService $licenses,
        private readonly ApiTranscriptionController $api,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $clips
     * @return array<string, mixed>
     */
    public function transcribe(User $user, array $clips, ?string $languageCode = null): array
    {
        $license = $this->licenses->provisionForUser($user);

        $response = $this->api->transcribe(
            $this->transcribeRequest($clips, $license->app_token, $languageCode, 'sync'),
            app(AppSettingsService::class),
        );
        $payload = $this->payload($response);

        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException('Audio upload could not be processed.');
        }

        return $payload;
    }

    /**
     * @param  array<int, array<string, mixed>>  $clips
     * @return array<string, mixed>
     */
    public function queue(User $user, array $clips, ?string $languageCode = null): array
    {
        $license = $this->licenses->provisionForUser($user);

        $response = $this->api->transcribe(
            $this->transcribeRequest($clips, $license->app_token, $languageCode, 'async'),
            app(AppSettingsService::class),
        );
        $payload = $this->payload($response);

        if ($response->getStatusCode() !== 202 || blank($payload['job_id'] ?? null)) {
            throw new \RuntimeException('Transcription job could not be created.');
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function jobStatus(User $user, string $jobId): array
    {
        $license = $this->licenses->provisionForUser($user);
        $response = $this->api->transcriptionJobStatus(
            Request::create('/api/transcribe/jobs/'.$jobId, 'GET', [], [], [], $this->server($license->app_token)),
            $jobId,
        );
        $payload = $this->payload($response);

        if ($response->getStatusCode() >= 500 && ($payload['status'] ?? null) !== 'failed') {
            throw new \RuntimeException('Audio upload could not be processed.');
        }

        return $payload;
    }

    /**
     * @param  array<int, array<string, mixed>>  $chunks
     * @return array<string, mixed>
     */
    public function polish(User $user, string $text, array $chunks, string $instruction, string $task = 'polish'): array
    {
        $license = $this->licenses->provisionForUser($user);
        $response = $this->api->polish(
            Request::create('/api/polish', 'POST', [
                'text' => $text,
                'chunks' => $chunks,
                'instruction' => $instruction,
                'task' => $task,
            ], [], [], $this->server($license->app_token)),
            app(AppSettingsService::class),
        );
        $payload = $this->payload($response);

        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException('Transcript could not be polished.');
        }

        return $payload;
    }

    /**
     * @param  array<int, array<string, mixed>>  $clips
     */
    public function batchIsTooLarge(array $clips): bool
    {
        if (count($clips) > self::MAX_BATCH_CLIPS) {
            return true;
        }

        $totalDurationMs = 0;

        foreach ($clips as $clip) {
            $startMs = $clip['clip_start_ms'] ?? null;
            $endMs = $clip['clip_end_ms'] ?? null;

            if (! is_numeric($startMs) || ! is_numeric($endMs)) {
                return count($clips) > 1;
            }

            $durationMs = max(0, (int) $endMs - (int) $startMs);

            if ($durationMs > self::MAX_BATCH_DURATION_MS) {
                return true;
            }

            $totalDurationMs += $durationMs;
        }

        return $totalDurationMs > self::MAX_BATCH_DURATION_MS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $clips
     */
    private function transcribeRequest(array $clips, string $licenseKey, ?string $languageCode, string $responseMode): Request
    {
        $files = [];
        $clipIndex = [];
        $clipStartMs = [];
        $clipEndMs = [];
        $languages = [];

        foreach (array_values($clips) as $clip) {
            $path = (string) ($clip['path'] ?? '');
            $absolutePath = Storage::disk('local')->path($path);

            if ($path === '' || ! is_file($absolutePath)) {
                throw new \RuntimeException(ServiceUserMessage::audioReadFailed());
            }

            $files[] = new UploadedFile(
                $absolutePath,
                (string) ($clip['name'] ?? basename($path)),
                mime_content_type($absolutePath) ?: 'application/octet-stream',
                null,
                true,
            );
            $clipIndex[] = (int) ($clip['clip_index'] ?? count($clipIndex));
            $clipStartMs[] = (int) ($clip['clip_start_ms'] ?? 0);
            $clipEndMs[] = (int) ($clip['clip_end_ms'] ?? 0);
            $languages[] = (string) ($clip['language_code'] ?? $languageCode ?? '');
        }

        return Request::create('/api/transcribe', 'POST', [
            'response_mode' => $responseMode,
            'clip_index' => $clipIndex,
            'clip_start_ms' => $clipStartMs,
            'clip_end_ms' => $clipEndMs,
            'language_code' => $languages,
        ], [], [
            'audio' => $files,
        ], $this->server($licenseKey));
    }

    /**
     * @return array<string, string>
     */
    private function server(string $licenseKey): array
    {
        return [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$licenseKey,
            'REMOTE_ADDR' => '127.0.0.1',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(JsonResponse $response): array
    {
        $payload = json_decode((string) $response->getContent(), true);

        return is_array($payload) ? $payload : [];
    }
}
