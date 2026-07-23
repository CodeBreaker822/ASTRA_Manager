<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessAsyncTranscriptionJob;
use App\Models\API;
use App\Models\ApiTranscriptionJob;
use App\Services\AppSettingsService;
use App\Services\AssemblyAiSpeechToTextService;
use App\Services\AwsTranscribeSpeechToTextService;
use App\Services\AzureSpeechToTextService;
use App\Services\CerebrasTranscriptCleanerService;
use App\Services\CloudflareTranscriptCleanerService;
use App\Services\DeepgramSpeechToTextService;
use App\Services\DeepSeekTranscriptCleanerService;
use App\Services\ElevenLabsSpeechToTextService;
use App\Services\GeminiTranscriptCleanerService;
use App\Services\GladiaSpeechToTextService;
use App\Services\GoogleCloudSpeechToTextService;
use App\Services\GroqSpeechToTextService;
use App\Services\GroqTranscriptCleanerService;
use App\Services\MistralTranscriptCleanerService;
use App\Services\OpenAICompatibleTranscriptCleanerService;
use App\Services\OpenRouterTranscriptCleanerService;
use App\Services\ProviderFallbackLogger;
use App\Services\RunPodSpeechToTextService;
use App\Services\ServiceUserMessage;
use App\Services\SpeechmaticsSpeechToTextService;
use App\Services\TranscriptionApiRequestLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class TranscriptionController extends Controller
{
    private const RUNPOD_API_BASE_URL = 'https://api.runpod.ai/v2';

    private const RATE_LIMIT_PER_MINUTE = 120;

    private const MAX_TRANSCRIBE_BATCH_DURATION_MS = 20 * 60 * 1000;

    private const MAX_TRANSCRIBE_BATCH_CLIPS = 20;

    public function transcribe(Request $request, AppSettingsService $settings): JsonResponse
    {
        $startedAt = microtime(true);
        $license = null;

        $license = $this->licenseFor($request, 'post');

        if ($license instanceof JsonResponse) {
            return $this->logAndReturn($request, 'transcribe', null, $license, $startedAt);
        }

        $rateLimited = $this->hitRateLimit($license);

        if ($rateLimited instanceof JsonResponse) {
            return $this->logAndReturn($request, 'transcribe', $license, $rateLimited, $startedAt, [
                'status' => 'rate_limited',
                'severity' => 'high',
            ]);
        }

        try {
            $audioRules = is_array($request->file('audio'))
                ? [
                    'audio' => ['required', 'array', 'min:1', 'max:'.self::MAX_TRANSCRIBE_BATCH_CLIPS],
                    'audio.*' => ['required', 'file', 'max:512000'],
                ]
                : ['audio' => ['required', 'file', 'max:512000']];

            $validated = $request->validate(array_merge($audioRules, [
                'provider' => ['nullable', 'string', 'max:100'],
                'model' => ['nullable', 'string', 'max:200'],
                'language_code' => ['nullable'],
                'language_code.*' => ['nullable', 'string', 'max:20'],
                'clip_index' => ['nullable'],
                'clip_index.*' => ['nullable', 'integer', 'min:0'],
                'clip_start_ms' => ['nullable'],
                'clip_start_ms.*' => ['nullable', 'integer', 'min:0'],
                'clip_end_ms' => ['nullable'],
                'clip_end_ms.*' => ['nullable', 'integer', 'min:0'],
                'clips' => ['nullable', 'array'],
                'clips.*' => ['array'],
                'clips.*.language_code' => ['nullable', 'string', 'max:20'],
                'clips.*.clip_index' => ['nullable', 'integer', 'min:0'],
                'clips.*.clip_start_ms' => ['nullable', 'integer', 'min:0'],
                'clips.*.clip_end_ms' => ['nullable', 'integer', 'min:0'],
                'response_mode' => ['nullable', 'string', 'in:sync,async'],
            ]));
        } catch (ValidationException $exception) {
            return $this->logAndReturn($request, 'transcribe', $license, $this->validationError($exception), $startedAt);
        }

        $queuedClips = $this->normalizeTranscribeClips($request, $validated);

        if ($this->transcribeBatchDurationTooLarge($queuedClips)) {
            return $this->logAndReturn($request, 'transcribe', $license, response()->json([
                'message' => 'Audio is too big.',
            ], 422), $startedAt, [
                'status' => 'validation_error',
                'severity' => 'low',
            ]);
        }

        if (($validated['response_mode'] ?? null) === 'async') {
            return $this->createAsyncTranscriptionJob($request, $license, $queuedClips, $startedAt);
        }

        $providers = $settings->orderedConnectedProviders('transcriber');
        $providerCount = count($providers);

        if ($providerCount === 0) {
            return $this->logAndReturn($request, 'transcribe', $license, response()->json([
                'message' => 'All configured transcription providers are unavailable.',
            ], 503), $startedAt, [
                'status' => 'provider_error',
                'severity' => 'high',
                'attempted_providers' => [],
            ]);
        }

        $clipTranscripts = [];
        $attemptedProviders = [];
        $usedProviders = [];
        $batchResult = $this->transcribeBatchWithFirstProvider($providers, $queuedClips, $request, $license);

        if ($batchResult !== null) {
            $clipTranscripts = $batchResult['clips'];
            $attemptedProviders = $batchResult['attempted_providers'];
            $usedProviders[] = $batchResult['provider']['provider'];
        }

        foreach ($batchResult === null ? $queuedClips : [] as $queueIndex => $clip) {
            $clipResult = $this->transcribeClipAcrossProviders(
                $providers,
                0,
                $clip,
                $request,
                $license,
            );

            if ($clipResult === null) {
                return $this->logAndReturn($request, 'transcribe', $license, response()->json([
                    'message' => 'All configured transcription providers are unavailable.',
                    'clip_index' => $clip['clip_index'],
                ], 503), $startedAt, [
                    'status' => 'provider_error',
                    'severity' => 'high',
                    'attempted_providers' => array_values(array_unique($attemptedProviders)),
                ]);
            }

            $attemptedProviders = array_merge($attemptedProviders, $clipResult['attempted_providers']);
            $usedProviders[] = $clipResult['provider']['provider'];
            $clipTranscripts[] = $this->clipTranscript($clip, $clipResult['result'], $clipResult['attempted_providers']);
        }

        $firstClip = $clipTranscripts[0];
        $attemptedProviders = array_values(array_unique($attemptedProviders));
        $fallback = [
            'used' => collect($clipTranscripts)->contains(fn (array $clip): bool => (bool) ($clip['fallback']['used'] ?? false)),
        ];

        $response = response()->json([
            'text' => count($clipTranscripts) === 1
                ? $firstClip['text']
                : collect($clipTranscripts)->pluck('text')->filter()->implode("\n\n"),
            'timestamps' => count($clipTranscripts) === 1 ? $firstClip['timestamps'] : [],
            'provider' => AppSettingsService::PUBLIC_PROVIDER_ID,
            'provider_name' => AppSettingsService::PUBLIC_PROVIDER_NAME,
            'model' => AppSettingsService::PUBLIC_MODEL,
            'clip_index' => $firstClip['clip_index'],
            'clip_start_ms' => $firstClip['clip_start_ms'],
            'clip_end_ms' => count($clipTranscripts) === 1 ? $firstClip['clip_end_ms'] : collect($clipTranscripts)->max('clip_end_ms'),
            'duration_ms' => collect($clipTranscripts)->sum(fn (array $clip): int => (int) ($clip['duration_ms'] ?? 0)),
            'clips' => $clipTranscripts,
            'fallback' => $fallback,
        ]);

        return $this->logAndReturn($request, 'transcribe', $license, $response, $startedAt, [
            'provider' => implode(',', array_values(array_unique($usedProviders))),
            'attempted_providers' => $attemptedProviders,
        ]);
    }

    public function transcriptionJobStatus(Request $request, string $job): JsonResponse
    {
        $license = $this->licenseFor($request, 'get');

        if ($license instanceof JsonResponse) {
            return $license;
        }

        $transcriptionJob = ApiTranscriptionJob::query()
            ->whereKey($job)
            ->where('api_id', $license->id)
            ->first();

        if (! $transcriptionJob) {
            return response()->json(['message' => 'Transcription job was not found.'], 404);
        }

        if (in_array($transcriptionJob->status, ['queued', 'processing'], true)) {
            $this->refreshAsyncTranscriptionJob($transcriptionJob, $request, $license);
            $transcriptionJob->refresh();
        }

        $payload = [
            'job_id' => $transcriptionJob->id,
            'status' => $transcriptionJob->status,
            'status_code' => $transcriptionJob->status_code,
            'created_at' => $transcriptionJob->created_at?->toISOString(),
            'started_at' => $transcriptionJob->started_at?->toISOString(),
            'finished_at' => $transcriptionJob->finished_at?->toISOString(),
        ];

        if ($transcriptionJob->status === 'completed') {
            $payload['result'] = $transcriptionJob->result_payload ?? [];
        }

        if ($transcriptionJob->status === 'failed') {
            $payload['message'] = $transcriptionJob->error_message ?: 'Transcription job failed.';
        }

        return response()->json($payload, $transcriptionJob->status === 'failed'
            ? max(400, (int) ($transcriptionJob->status_code ?: 500))
            : 200);
    }

    public function polish(Request $request, AppSettingsService $settings): JsonResponse
    {
        $startedAt = microtime(true);
        $license = null;

        $license = $this->licenseFor($request, 'post');

        if ($license instanceof JsonResponse) {
            return $this->logAndReturn($request, 'polish', null, $license, $startedAt);
        }

        $rateLimited = $this->hitRateLimit($license);

        if ($rateLimited instanceof JsonResponse) {
            return $this->logAndReturn($request, 'polish', $license, $rateLimited, $startedAt, [
                'status' => 'rate_limited',
                'severity' => 'high',
            ]);
        }

        try {
            $validated = $request->validate([
                'provider' => ['nullable', 'string', 'max:100'],
                'model' => ['nullable', 'string', 'max:200'],
                'text' => ['nullable', 'string'],
                'timestamps' => ['nullable', 'array'],
                'chunks' => ['nullable', 'array'],
                'chunks.*' => ['array'],
                'chunks.*.audio_chunk_id' => ['nullable', 'integer'],
                'chunks.*.clip_index' => ['nullable', 'integer'],
                'chunks.*.range_label' => ['nullable', 'string', 'max:100'],
                'chunks.*.text' => ['nullable', 'string'],
                'chunks.*.timestamps' => ['nullable', 'array'],
                'instruction' => ['nullable', 'string', 'max:4000'],
                'task' => ['nullable', 'string', 'in:polish,summarize'],
            ]);
        } catch (ValidationException $exception) {
            return $this->logAndReturn($request, 'polish', $license, $this->validationError($exception), $startedAt);
        }

        if (blank($validated['text'] ?? null) && empty($validated['chunks'] ?? [])) {
            return $this->logAndReturn($request, 'polish', $license, response()->json([
                'message' => 'Provide text or transcript chunks to polish.',
            ], 422), $startedAt);
        }

        $providers = $settings->orderedConnectedProviders('text_fixer');
        $attemptedProviders = [];
        $result = null;
        $usedProvider = null;
        $hasChunks = ! empty($validated['chunks']);
        $normalizedChunks = $hasChunks ? $this->normalizeChunks($validated['chunks']) : [];
        $fallbackUsed = false;

        if (
            ! $hasChunks
            && count($providers) > 1
            && $this->isSummaryTask($validated['task'] ?? null, $validated['instruction'] ?? null)
            && mb_strlen((string) ($validated['text'] ?? '')) > $this->polishChunkCharacters()
        ) {
            try {
                $distributed = $this->summarizeAcrossProviders(
                    $providers,
                    (string) $validated['text'],
                    $validated['instruction'] ?? null,
                    $request,
                    $license,
                );
                $result = $distributed['result'];
                $usedProvider = $distributed['final_provider'];
                $attemptedProviders = $distributed['attempted_providers'];
                $fallbackUsed = $distributed['fallback_used'];
            } catch (Throwable $exception) {
                report($exception);
            }
        } else {
            foreach ($providers as $position => $provider) {
                $attemptedProviders[] = $provider['provider'];

                try {
                    $cleaner = $this->cleanerForProvider($provider);
                    $result = $this->polishUsingCleaner(
                        $cleaner,
                        $provider,
                        (string) ($validated['text'] ?? ''),
                        $validated['timestamps'] ?? [],
                        $normalizedChunks,
                        $validated['instruction'] ?? null,
                        $validated['task'] ?? null,
                    );
                    $usedProvider = $provider;
                    $fallbackUsed = $position > 0;
                    app(ProviderFallbackLogger::class)->recovered('text_fixer', 'polish', $provider, $position, $request, $license);
                    break;
                } catch (Throwable $exception) {
                    app(ProviderFallbackLogger::class)->failure('text_fixer', 'polish', $provider, $position, $exception, $request, $license);
                    report($exception);
                }
            }
        }

        if ($result === null || $usedProvider === null) {
            return $this->logAndReturn($request, 'polish', $license, response()->json([
                'message' => 'All configured text-fixer providers are unavailable.',
            ], 503), $startedAt, [
                'status' => 'provider_error',
                'severity' => 'high',
                'attempted_providers' => $attemptedProviders,
            ]);
        }

        $responseData = $hasChunks
            ? ['chunks' => $result['chunks']]
            : [
                'text' => $result['text'],
                'timestamps' => $result['timestamps'],
            ];

        $responseData = array_merge($responseData, [
            'provider' => AppSettingsService::PUBLIC_PROVIDER_ID,
            'provider_name' => AppSettingsService::PUBLIC_PROVIDER_NAME,
            'model' => AppSettingsService::PUBLIC_MODEL,
            'fallback' => ['used' => $fallbackUsed],
        ]);

        return $this->logAndReturn($request, 'polish', $license, response()->json($responseData), $startedAt, [
            'provider' => $usedProvider['provider'],
            'model' => $usedProvider['model'],
            'attempted_providers' => $attemptedProviders,
        ]);
    }

    public function licenseStatus(Request $request, AppSettingsService $settings): JsonResponse
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
        $canGet = (bool) $license->can_get && (bool) $license->is_active;
        $rateLimited = RateLimiter::tooManyAttempts($this->rateLimitKey($license), self::RATE_LIMIT_PER_MINUTE);
        $update = $this->transcriberUpdate();
        $updateAllowed = $canGet && $update['zipfile'] !== null;
        $transcriptionProvider = $settings->publicProviderCapability('transcriber');
        $polishingProvider = $settings->publicProviderCapability('text_fixer');

        $response = response()->json([
            'valid' => true,
            'active' => (bool) $license->is_active,
            'expired' => false,
            'rate_limited' => $rateLimited,
            'app_name' => $license->app_name,
            'version' => $update['version'],
            'zipfile' => $update['zipfile'],
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
                'transcriber_update' => [
                    'method' => 'GET',
                    'path' => ! $updateAllowed
                        ? null
                        : '/transcriber/'.rawurlencode($update['zipfile']),
                    'allowed' => $updateAllowed,
                    'zipfile' => $update['zipfile'],
                ],
            ],
            'providers' => [
                'transcription' => [$transcriptionProvider],
                'polishing' => [$polishingProvider],
            ],
        ]);

        return $this->logAndReturn($request, 'license_status', $license, $response, $startedAt, [
            'status' => ! $license->is_active ? 'denied' : ($rateLimited ? 'rate_limited' : 'success'),
            'severity' => ! $license->is_active ? 'critical' : ($rateLimited ? 'high' : 'low'),
        ]);
    }

    public function downloadUpdate(Request $request, ?string $zipfile = null): JsonResponse|BinaryFileResponse
    {
        $license = $this->licenseFor($request, 'get');

        if ($license instanceof JsonResponse) {
            return $license;
        }

        $zipPath = $this->transcriberZipPath();

        if ($zipPath === null) {
            return response()->json([
                'message' => 'Transcriber update ZIP file is not available.',
            ], 404);
        }

        if ($zipfile !== null && ! hash_equals(basename($zipPath), $zipfile)) {
            return response()->json([
                'message' => 'Transcriber update ZIP file is not available.',
            ], 404);
        }

        return response()->download($zipPath, basename($zipPath), [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function temporaryRunPodAudio(string $file): JsonResponse|BinaryFileResponse
    {
        $file = basename($file);
        $path = Storage::disk('local')->path('runpod-audio/'.$file);

        if (! is_file($path) || ! is_readable($path)) {
            return response()->json(['message' => 'Audio file not found.'], 404);
        }

        return response()->file($path, [
            'Content-Type' => mime_content_type($path) ?: 'application/octet-stream',
        ])->deleteFileAfterSend(true);
    }

    private function licenseFor(Request $request, string $method): API|JsonResponse
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

    /**
     * @return array{version: string|null, zipfile: string|null}
     */
    private function transcriberUpdate(): array
    {
        $version = null;
        $versionPath = Storage::disk('local')->path('transcriber/version.json');

        if (is_file($versionPath) && is_readable($versionPath)) {
            try {
                $data = json_decode((string) file_get_contents($versionPath), true, 512, JSON_THROW_ON_ERROR);

                if (is_array($data) && array_key_exists('version', $data)) {
                    $version = is_string($data['version']) ? $data['version'] : null;
                }
            } catch (Throwable) {
                // A bad manually uploaded file should not break the status endpoint.
            }
        }

        $zipPath = $this->transcriberZipPath();

        return [
            'version' => $version,
            'zipfile' => $zipPath === null ? null : basename($zipPath),
        ];
    }

    private function transcriberZipPath(): ?string
    {
        $directory = Storage::disk('local')->path('transcriber');
        $files = is_dir($directory) ? scandir($directory) : false;

        if ($files === false) {
            return null;
        }

        $zipFiles = array_values(array_filter($files, function (string $file) use ($directory): bool {
            return strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'zip'
                && is_file($directory.DIRECTORY_SEPARATOR.$file);
        }));

        natcasesort($zipFiles);
        $zipFile = reset($zipFiles);

        return $zipFile === false
            ? null
            : $directory.DIRECTORY_SEPARATOR.$zipFile;
    }

    private function hitRateLimit(API $license): ?JsonResponse
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

    private function rateLimitKey(API $license): string
    {
        return 'transcription-api-license:'.$license->id;
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

    private function normalizeChunks(array $chunks): array
    {
        return array_values(array_map(function (array $chunk, int $index): array {
            return [
                'id' => (int) ($chunk['audio_chunk_id'] ?? $chunk['clip_index'] ?? $index),
                'range_label' => $chunk['range_label'] ?? null,
                'text' => (string) ($chunk['text'] ?? ''),
                'timestamps' => array_values(array_filter($chunk['timestamps'] ?? [], 'is_array')),
            ];
        }, $chunks, array_keys($chunks)));
    }

    private function validationError(ValidationException $exception): JsonResponse
    {
        $message = collect($exception->errors())->flatten()->first()
            ?: 'The given data was invalid.';

        return response()->json([
            'message' => $message,
            'errors' => $exception->errors(),
        ], 422);
    }

    private function logAndReturn(
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

    /**
     * @param  array<int, array<string, mixed>>  $queuedClips
     */
    private function createAsyncTranscriptionJob(
        Request $request,
        API $license,
        array $queuedClips,
        float $startedAt,
    ): JsonResponse {
        try {
            $jobId = (string) Str::uuid();
            $storedClips = [];
            $providers = app(AppSettingsService::class)->orderedConnectedProviders('transcriber');

            if ($providers === []) {
                return $this->logAndReturn($request, 'transcribe', $license, response()->json([
                    'message' => 'All configured transcription providers are unavailable.',
                ], 503), $startedAt, [
                    'status' => 'provider_error',
                    'severity' => 'high',
                    'attempted_providers' => [],
                ]);
            }

            foreach ($queuedClips as $clip) {
                $audio = $clip['audio'];
                $path = $audio?->getRealPath();

                if (! is_string($path) || ! is_file($path)) {
                    throw new \RuntimeException(ServiceUserMessage::audioReadFailed());
                }

                $extension = $audio?->getClientOriginalExtension() ?: pathinfo((string) $audio?->getClientOriginalName(), PATHINFO_EXTENSION);
                $extension = $extension ?: 'audio';
                $storedPath = 'transcription-jobs/'.$jobId.'/clip-'.$clip['queue_index'].'.'.$extension;
                $contents = file_get_contents($path);

                if ($contents === false) {
                    throw new \RuntimeException(ServiceUserMessage::audioReadFailed());
                }

                Storage::disk('local')->put($storedPath, $contents);

                $storedClips[] = [
                    ...$clip,
                    'audio' => null,
                    'audio_path' => $storedPath,
                    'audio_name' => $audio?->getClientOriginalName() ?: basename($storedPath),
                ];
            }

            $requestPayload = [
                'clips' => $storedClips,
                'mode' => 'queue',
            ];
            $status = 'queued';
            $dispatchQueueJob = true;

            if (($providers[0]['provider'] ?? null) === AppSettingsService::PROVIDER_RUNPOD) {
                try {
                    $runPodJob = $this->runPodService($providers[0])->submitBatchAsync($this->storedClipsWithAudio($storedClips));
                    $requestPayload = [
                        ...$requestPayload,
                        'mode' => 'runpod_async',
                        'runpod_job_id' => $runPodJob['job_id'],
                        'runpod_temporary_paths' => $runPodJob['temporary_paths'],
                    ];
                } catch (Throwable $exception) {
                    app(ProviderFallbackLogger::class)->failure('transcriber', 'transcribe', $providers[0], 0, $exception, $request, $license);
                    report($exception);

                    $requestPayload = [
                        ...$requestPayload,
                        'mode' => 'provider_fallback',
                        'initial_error' => $exception->getMessage(),
                    ];
                }

                $status = 'processing';
                $dispatchQueueJob = false;
            }

            $transcriptionJob = ApiTranscriptionJob::query()->create([
                'id' => $jobId,
                'api_id' => $license->id,
                'status' => $status,
                'request_payload' => $requestPayload,
                'started_at' => $status === 'processing' ? now() : null,
            ]);

            if ($dispatchQueueJob) {
                ProcessAsyncTranscriptionJob::dispatch($transcriptionJob->id);
            }

            $response = response()->json([
                'job_id' => $transcriptionJob->id,
                'status' => $transcriptionJob->status,
                'status_url' => url('/api/transcribe/jobs/'.$transcriptionJob->id),
            ], 202);

            return $this->logAndReturn($request, 'transcribe', $license, $response, $startedAt, [
                'status' => 'queued',
                'async' => true,
                'clip_count' => count($storedClips),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return $this->logAndReturn($request, 'transcribe', $license, response()->json([
                'message' => 'Transcription job could not be created.',
            ], 500), $startedAt, [
                'status' => 'job_create_failed',
                'severity' => 'high',
            ]);
        }
    }

    public function processAsyncTranscriptionJob(ApiTranscriptionJob $job): void
    {
        $claimed = ApiTranscriptionJob::query()
            ->whereKey($job->id)
            ->where('status', 'queued')
            ->update([
                'status' => 'processing',
                'started_at' => $job->started_at ?: now(),
                'error_message' => null,
                'status_code' => null,
            ]);

        if ($claimed !== 1) {
            return;
        }

        $job->refresh();

        $payload = is_array($job->request_payload) ? $job->request_payload : [];
        $license = API::query()->find($job->api_id);

        if (! $license) {
            $job->forceFill([
                'status' => 'failed',
                'error_message' => 'License key for this transcription job no longer exists.',
                'status_code' => 404,
                'finished_at' => now(),
            ])->save();

            return;
        }

        $clips = $this->storedClipsWithAudio($payload['clips'] ?? []);

        $request = Request::create('/api/transcribe/jobs/'.$job->id, 'POST');
        $response = $this->transcribeQueuedClips(
            $clips,
            app(AppSettingsService::class),
            $request,
            $license,
        );
        $responsePayload = json_decode((string) $response->getContent(), true);
        $responsePayload = is_array($responsePayload) ? $responsePayload : [];
        $statusCode = $response->getStatusCode();

        $job->forceFill([
            'status' => $statusCode >= 400 ? 'failed' : 'completed',
            'result_payload' => $statusCode >= 400 ? null : $responsePayload,
            'error_message' => $statusCode >= 400
                ? (string) ($responsePayload['message'] ?? 'Transcription job failed.')
                : null,
            'status_code' => $statusCode,
            'finished_at' => now(),
        ])->save();

        $this->deleteAsyncTranscriptionAudio($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function deleteAsyncTranscriptionAudio(array $payload): void
    {
        foreach (array_values(array_filter($payload['clips'] ?? [], 'is_array')) as $clip) {
            $path = (string) ($clip['audio_path'] ?? '');

            if ($path !== '') {
                Storage::disk('local')->delete($path);
            }
        }

        foreach (array_values(array_filter($payload['runpod_temporary_paths'] ?? [], 'is_string')) as $path) {
            Storage::disk('local')->delete($path);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $storedClips
     * @return array<int, array<string, mixed>>
     */
    private function storedClipsWithAudio(array $storedClips): array
    {
        return array_map(function (array $clip): array {
            $audioPath = (string) ($clip['audio_path'] ?? '');
            $absolutePath = Storage::disk('local')->path($audioPath);

            if ($audioPath === '' || ! is_file($absolutePath)) {
                throw new \RuntimeException(ServiceUserMessage::audioReadFailed());
            }

            return [
                ...$clip,
                'audio' => $absolutePath,
            ];
        }, array_values(array_filter($storedClips, 'is_array')));
    }

    private function refreshAsyncTranscriptionJob(ApiTranscriptionJob $job, Request $request, API $license): void
    {
        $payload = is_array($job->request_payload) ? $job->request_payload : [];

        if (($payload['mode'] ?? null) !== 'runpod_async') {
            if (($payload['mode'] ?? null) === 'queue' && $job->status === 'queued') {
                $this->processAsyncTranscriptionJob($job);
            }

            if (($payload['mode'] ?? null) === 'provider_fallback') {
                $this->completeAsyncTranscriptionWithFallback(
                    $job,
                    $payload,
                    $request,
                    $license,
                    new \RuntimeException((string) ($payload['initial_error'] ?? ServiceUserMessage::transcriptionFailed('RunPod'))),
                );
            }

            return;
        }

        $provider = $this->runPodProvider(app(AppSettingsService::class)->orderedConnectedProviders('transcriber'));
        $runPodJobId = trim((string) ($payload['runpod_job_id'] ?? ''));

        if (! is_array($provider) || $runPodJobId === '') {
            $this->completeAsyncTranscriptionWithFallback(
                $job,
                $payload,
                $request,
                $license,
                new \RuntimeException('RunPod async job metadata is missing.'),
            );

            return;
        }

        $service = $this->runPodService($provider);

        try {
            $runPodPayload = $service->status($runPodJobId);
        } catch (Throwable $exception) {
            Log::warning('RunPod async status check failed; keeping transcription job pending.', [
                'job_id' => $job->id,
                'runpod_job_id' => $runPodJobId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $job->forceFill([
                'status' => $job->status === 'queued' ? 'queued' : 'processing',
                'started_at' => $job->started_at ?: now(),
                'error_message' => null,
                'status_code' => null,
            ])->save();

            return;
        }

        $state = strtoupper((string) data_get($runPodPayload, 'status', ''));

        if (! in_array($state, ['COMPLETED', 'FAILED', 'CANCELLED', 'TIMED_OUT'], true)) {
            $job->forceFill([
                'status' => $state === 'IN_QUEUE' ? 'queued' : 'processing',
                'started_at' => $job->started_at ?: now(),
                'error_message' => null,
                'status_code' => null,
            ])->save();

            return;
        }

        if ($state !== 'COMPLETED') {
            $exception = new \RuntimeException(ServiceUserMessage::transcriptionFailed('RunPod'));
            app(ProviderFallbackLogger::class)->failure('transcriber', 'transcribe', $provider, 0, $exception, $request, $license);
            report($exception);

            $this->completeAsyncTranscriptionWithFallback($job, $payload, $request, $license, $exception);

            return;
        }

        try {
            $clips = $this->storedClipsWithAudio($payload['clips'] ?? []);
            $results = $service->normalizeSubmittedBatch($runPodPayload, $clips);

            if (! collect($results)->contains(fn (mixed $result): bool => is_array($result) && trim((string) ($result['text'] ?? '')) !== '')) {
                throw new \RuntimeException(ServiceUserMessage::emptyTranscriptionResponse('RunPod'));
            }

            $clipTranscripts = array_map(function (array $clip, int $index) use ($results): array {
                $result = $this->resultForBatchClip($results, $clip, $index);

                return $this->clipTranscript($clip, $result, [AppSettingsService::PROVIDER_RUNPOD]);
            }, $clips, array_keys($clips));

            $job->forceFill([
                'status' => 'completed',
                'result_payload' => $this->transcriptionPayloadFromClips(
                    $clipTranscripts,
                    [AppSettingsService::PROVIDER_RUNPOD],
                    [AppSettingsService::PROVIDER_RUNPOD],
                ),
                'error_message' => null,
                'status_code' => 200,
                'finished_at' => now(),
            ])->save();

            $this->deleteAsyncTranscriptionAudio($payload);
        } catch (Throwable $exception) {
            app(ProviderFallbackLogger::class)->failure('transcriber', 'transcribe', $provider, 0, $exception, $request, $license);
            report($exception);

            $this->completeAsyncTranscriptionWithFallback($job, $payload, $request, $license, $exception);
        }
    }

    private function completeAsyncTranscriptionWithFallback(
        ApiTranscriptionJob $job,
        array $payload,
        Request $request,
        API $license,
        Throwable $runPodException,
    ): void {
        $settings = app(AppSettingsService::class);
        $providers = array_values(array_filter(
            $settings->orderedConnectedProviders('transcriber'),
            fn (array $provider): bool => ($provider['provider'] ?? null) !== AppSettingsService::PROVIDER_RUNPOD,
        ));

        if ($providers === []) {
            $job->forceFill([
                'status' => 'failed',
                'result_payload' => null,
                'error_message' => $runPodException->getMessage() ?: ServiceUserMessage::transcriptionFailed('RunPod'),
                'status_code' => max(500, (int) $runPodException->getCode()),
                'finished_at' => now(),
            ])->save();

            $this->deleteAsyncTranscriptionAudio($payload);

            return;
        }

        try {
            $clips = $this->storedClipsWithAudio($payload['clips'] ?? []);
            $response = $this->transcribeQueuedClips($clips, $settings, $request, $license, $providers);
            $responsePayload = json_decode((string) $response->getContent(), true);
            $responsePayload = is_array($responsePayload) ? $responsePayload : [];
            $statusCode = $response->getStatusCode();

            if ($statusCode < 400) {
                $attempted = array_values(array_unique([
                    AppSettingsService::PROVIDER_RUNPOD,
                    ...array_values(array_filter($responsePayload['attempted_providers'] ?? [], 'is_string')),
                ]));

                $responsePayload['attempted_providers'] = $attempted;
                $responsePayload['fallback'] = ['used' => true];
            }

            $job->forceFill([
                'status' => $statusCode >= 400 ? 'failed' : 'completed',
                'result_payload' => $statusCode >= 400 ? null : $responsePayload,
                'error_message' => $statusCode >= 400
                    ? (string) ($responsePayload['message'] ?? 'Transcription job failed.')
                    : null,
                'status_code' => $statusCode,
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            report($exception);

            $job->forceFill([
                'status' => 'failed',
                'result_payload' => null,
                'error_message' => $exception->getMessage() ?: 'Transcription job failed.',
                'status_code' => max(500, (int) $exception->getCode()),
                'finished_at' => now(),
            ])->save();
        } finally {
            $this->deleteAsyncTranscriptionAudio($payload);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $queuedClips
     * @param  array<int, array<string, mixed>>|null  $providersOverride
     */
    private function transcribeQueuedClips(
        array $queuedClips,
        AppSettingsService $settings,
        Request $request,
        API $license,
        ?array $providersOverride = null,
    ): JsonResponse {
        $providers = $providersOverride ?? $settings->orderedConnectedProviders('transcriber');
        $providerCount = count($providers);

        if ($providerCount === 0) {
            return response()->json([
                'message' => 'All configured transcription providers are unavailable.',
            ], 503);
        }

        $clipTranscripts = [];
        $attemptedProviders = [];
        $usedProviders = [];
        $batchResult = $this->transcribeBatchWithFirstProvider($providers, $queuedClips, $request, $license);

        if ($batchResult !== null) {
            $clipTranscripts = $batchResult['clips'];
            $attemptedProviders = $batchResult['attempted_providers'];
            $usedProviders[] = $batchResult['provider']['provider'];
        }

        foreach ($batchResult === null ? $queuedClips : [] as $queueIndex => $clip) {
            $clipResult = $this->transcribeClipAcrossProviders(
                $providers,
                0,
                $clip,
                $request,
                $license,
            );

            if ($clipResult === null) {
                return response()->json([
                    'message' => 'All configured transcription providers are unavailable.',
                    'clip_index' => $clip['clip_index'],
                ], 503);
            }

            $attemptedProviders = array_merge($attemptedProviders, $clipResult['attempted_providers']);
            $usedProviders[] = $clipResult['provider']['provider'];
            $clipTranscripts[] = $this->clipTranscript($clip, $clipResult['result'], $clipResult['attempted_providers']);
        }

        return response()->json($this->transcriptionPayloadFromClips($clipTranscripts, $attemptedProviders, $usedProviders));
    }

    /**
     * @param  array<int, array<string, mixed>>  $clipTranscripts
     * @param  array<int, string>  $attemptedProviders
     * @param  array<int, string>  $usedProviders
     * @return array<string, mixed>
     */
    private function transcriptionPayloadFromClips(array $clipTranscripts, array $attemptedProviders, array $usedProviders): array
    {
        $firstClip = $clipTranscripts[0];
        $fallback = [
            'used' => collect($clipTranscripts)->contains(fn (array $clip): bool => (bool) ($clip['fallback']['used'] ?? false)),
        ];

        return [
            'text' => count($clipTranscripts) === 1
                ? $firstClip['text']
                : collect($clipTranscripts)->pluck('text')->filter()->implode("\n\n"),
            'timestamps' => count($clipTranscripts) === 1 ? $firstClip['timestamps'] : [],
            'provider' => AppSettingsService::PUBLIC_PROVIDER_ID,
            'provider_name' => AppSettingsService::PUBLIC_PROVIDER_NAME,
            'model' => AppSettingsService::PUBLIC_MODEL,
            'clip_index' => $firstClip['clip_index'],
            'clip_start_ms' => $firstClip['clip_start_ms'],
            'clip_end_ms' => count($clipTranscripts) === 1 ? $firstClip['clip_end_ms'] : collect($clipTranscripts)->max('clip_end_ms'),
            'duration_ms' => collect($clipTranscripts)->sum(fn (array $clip): int => (int) ($clip['duration_ms'] ?? 0)),
            'clips' => $clipTranscripts,
            'fallback' => $fallback,
            'attempted_providers' => array_values(array_unique($attemptedProviders)),
            'used_providers' => array_values(array_unique($usedProviders)),
        ];
    }

    private function transcribeUsingProvider(array $provider, mixed $audio, array $options): array
    {
        $service = match ($provider['provider']) {
            AppSettingsService::PROVIDER_DEEPGRAM => new DeepgramSpeechToTextService(
                apiKey: $provider['api_key'],
                modelId: $provider['model'],
            ),
            AppSettingsService::PROVIDER_ELEVENLABS => new ElevenLabsSpeechToTextService(
                apiKey: $provider['api_key'],
                modelId: $provider['model'],
            ),
            AppSettingsService::PROVIDER_SPEECHMATICS => new SpeechmaticsSpeechToTextService(
                apiKey: $provider['api_key'],
                modelId: $provider['model'],
            ),
            AppSettingsService::PROVIDER_GROQ_TRANSCRIPTION => new GroqSpeechToTextService(
                apiKey: $provider['api_key'],
                modelId: $provider['model'],
            ),
            AppSettingsService::PROVIDER_GLADIA => new GladiaSpeechToTextService($provider['api_key'], $provider['model']),
            AppSettingsService::PROVIDER_ASSEMBLYAI => new AssemblyAiSpeechToTextService($provider['api_key'], $provider['model']),
            AppSettingsService::PROVIDER_AZURE_SPEECH => new AzureSpeechToTextService($provider['api_key'], $provider['model']),
            AppSettingsService::PROVIDER_GOOGLE_SPEECH => new GoogleCloudSpeechToTextService($provider['api_key'], $provider['model']),
            AppSettingsService::PROVIDER_AWS_TRANSCRIBE => new AwsTranscribeSpeechToTextService($provider['api_key'], $provider['model']),
            AppSettingsService::PROVIDER_RUNPOD => $this->runPodService($provider),
        };

        return $service->transcribe($audio, $options);
    }

    /**
     * @param  array<int, array<string, mixed>>  $providers
     * @return array<string, mixed>|null
     */
    private function runPodProvider(array $providers): ?array
    {
        foreach ($providers as $provider) {
            if (($provider['provider'] ?? null) === AppSettingsService::PROVIDER_RUNPOD) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $provider
     */
    private function runPodService(array $provider): RunPodSpeechToTextService
    {
        return new RunPodSpeechToTextService(
            $provider['api_key'],
            $provider['model'],
            $this->runPodRunsyncUrl($provider['metadata'] ?? []),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $providers
     * @param  array<int, array<string, mixed>>  $clips
     * @return array{clips: array<int, array<string, mixed>>, provider: array<string, mixed>, attempted_providers: array<int, string>}|null
     */
    private function transcribeBatchWithFirstProvider(
        array $providers,
        array $clips,
        Request $request,
        API $license,
    ): ?array {
        $provider = $providers[0] ?? null;

        if (count($clips) < 2 || ! is_array($provider) || $provider['provider'] !== AppSettingsService::PROVIDER_RUNPOD) {
            return null;
        }

        try {
            $service = new RunPodSpeechToTextService(
                $provider['api_key'],
                $provider['model'],
                $this->runPodRunsyncUrl($provider['metadata'] ?? []),
            );
            $results = $service->transcribeBatch(array_map(
                fn (array $clip): array => [
                    'audio' => $clip['audio'],
                    'language_code' => $clip['language_code'],
                    'clip_index' => $clip['clip_index'],
                    'clip_start_ms' => $clip['clip_start_ms'],
                    'clip_end_ms' => $clip['clip_end_ms'],
                ],
                $clips,
            ));

            if (! collect($results)->contains(fn (mixed $result): bool => is_array($result) && trim((string) ($result['text'] ?? '')) !== '')) {
                throw new \RuntimeException(ServiceUserMessage::emptyTranscriptionResponse((string) $provider['provider']));
            }

            return [
                'clips' => array_map(function (array $clip, int $index) use ($results): array {
                    $result = $this->resultForBatchClip($results, $clip, $index);

                    return $this->clipTranscript($clip, $result, [AppSettingsService::PROVIDER_RUNPOD]);
                }, $clips, array_keys($clips)),
                'provider' => $provider,
                'attempted_providers' => [AppSettingsService::PROVIDER_RUNPOD],
            ];
        } catch (Throwable $exception) {
            app(ProviderFallbackLogger::class)->failure('transcriber', 'transcribe', $provider, 0, $exception, $request, $license);
            report($exception);

            return null;
        }
    }

    private function resultForBatchClip(array $results, array $clip, int $index): array
    {
        $clipIndex = $clip['clip_index'] ?? null;

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            if (isset($result['clip_index']) && is_numeric($clipIndex) && (int) $result['clip_index'] === (int) $clipIndex) {
                return $result;
            }

            if (isset($result['queue_index']) && (int) $result['queue_index'] === $index) {
                return $result;
            }
        }

        return is_array($results[$index] ?? null) ? $results[$index] : ['text' => '', 'timestamps' => []];
    }

    private function runPodRunsyncUrl(array $metadata): ?string
    {
        $runsyncUrl = trim((string) ($metadata['runsync_url'] ?? ''));

        if ($runsyncUrl !== '') {
            return $runsyncUrl;
        }

        $endpointId = trim((string) ($metadata['endpoint_id'] ?? ''));

        return $endpointId === ''
            ? null
            : self::RUNPOD_API_BASE_URL.'/'.$endpointId.'/runsync';
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int, array<string, mixed>>
     */
    private function normalizeTranscribeClips(Request $request, array $validated): array
    {
        $audio = $request->file('audio');
        $files = is_array($audio) ? array_values($audio) : [$audio];
        $clips = [];

        foreach ($files as $index => $file) {
            $metadata = is_array($validated['clips'][$index] ?? null) ? $validated['clips'][$index] : [];

            $clips[] = [
                'audio' => $file,
                'queue_index' => $index,
                'provider' => $validated['provider'] ?? null,
                'model' => $validated['model'] ?? null,
                'language_code' => $metadata['language_code'] ?? $this->indexedValue($validated, 'language_code', $index),
                'clip_index' => $metadata['clip_index'] ?? $this->indexedValue($validated, 'clip_index', $index),
                'clip_start_ms' => $metadata['clip_start_ms'] ?? $this->indexedValue($validated, 'clip_start_ms', $index),
                'clip_end_ms' => $metadata['clip_end_ms'] ?? $this->indexedValue($validated, 'clip_end_ms', $index),
            ];
        }

        return $clips;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function indexedValue(array $validated, string $key, int $index): mixed
    {
        $value = $validated[$key] ?? null;

        if (is_array($value)) {
            return $value[$index] ?? null;
        }

        return $index === 0 ? $value : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $providers
     * @param  array<string, mixed>  $clip
     * @return array{result: array<string, mixed>, provider: array<string, mixed>, attempted_providers: array<int, string>}|null
     */
    private function transcribeClipAcrossProviders(
        array $providers,
        int $startingIndex,
        array $clip,
        Request $request,
        API $license,
    ): ?array {
        $providerCount = count($providers);
        $attemptedProviders = [];

        for ($offset = 0; $offset < $providerCount; $offset++) {
            $provider = $providers[($startingIndex + $offset) % $providerCount];
            $attemptedProviders[] = $provider['provider'];

            try {
                $result = $this->retryEmptyTranscriptionResponse(
                    fn (): array => $this->transcribeUsingProvider($provider, $clip['audio'], $clip),
                    $provider,
                );

                if ($offset > 0) {
                    app(ProviderFallbackLogger::class)->recovered('transcriber', 'transcribe', $provider, $offset, $request, $license);
                }

                return [
                    'result' => $result,
                    'provider' => $provider,
                    'attempted_providers' => $attemptedProviders,
                ];
            } catch (Throwable $exception) {
                app(ProviderFallbackLogger::class)->failure('transcriber', 'transcribe', $provider, $offset, $exception, $request, $license);
                report($exception);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $clip
     * @param  array<string, mixed>  $result
     * @param  array<int, string>  $attemptedProviders
     * @return array<string, mixed>
     */
    private function clipTranscript(array $clip, array $result, array $attemptedProviders): array
    {
        return [
            'queue_index' => $clip['queue_index'],
            'clip_index' => is_numeric($clip['clip_index']) ? (int) $clip['clip_index'] : null,
            'clip_start_ms' => is_numeric($clip['clip_start_ms']) ? (int) $clip['clip_start_ms'] : null,
            'clip_end_ms' => is_numeric($clip['clip_end_ms']) ? (int) $clip['clip_end_ms'] : null,
            'duration_ms' => $this->clipDurationMs($clip),
            'text' => $result['text'] ?? '',
            'timestamps' => $result['timestamps'] ?? [],
            'provider' => AppSettingsService::PUBLIC_PROVIDER_ID,
            'provider_name' => AppSettingsService::PUBLIC_PROVIDER_NAME,
            'model' => AppSettingsService::PUBLIC_MODEL,
            'attempted_providers' => $attemptedProviders,
            'fallback' => $this->fallbackDetails($attemptedProviders),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $clips
     */
    private function transcribeBatchDurationTooLarge(array $clips): bool
    {
        $totalDurationMs = 0;

        foreach ($clips as $clip) {
            $clipStartMs = $clip['clip_start_ms'] ?? null;
            $clipEndMs = $clip['clip_end_ms'] ?? null;

            if (! is_numeric($clipStartMs) || ! is_numeric($clipEndMs)) {
                return count($clips) > 1;
            }

            $durationMs = max(0, (int) $clipEndMs - (int) $clipStartMs);

            if ((int) $clipEndMs > self::MAX_TRANSCRIBE_BATCH_DURATION_MS || $durationMs > self::MAX_TRANSCRIBE_BATCH_DURATION_MS) {
                return true;
            }

            $totalDurationMs += $durationMs;
        }

        return $totalDurationMs > self::MAX_TRANSCRIBE_BATCH_DURATION_MS;
    }

    /**
     * @param  array<string, mixed>  $clip
     */
    private function clipDurationMs(array $clip): ?int
    {
        $clipStartMs = $clip['clip_start_ms'] ?? null;
        $clipEndMs = $clip['clip_end_ms'] ?? null;

        if (! is_numeric($clipStartMs) || ! is_numeric($clipEndMs)) {
            return null;
        }

        return max(0, (int) $clipEndMs - (int) $clipStartMs);
    }

    private function retryEmptyTranscriptionResponse(callable $callback, array $provider): array
    {
        $attempts = max(1, (int) config('services.transcription_processing.response_attempts', 3));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $result = $callback();

            if (trim((string) ($result['text'] ?? '')) !== '') {
                return $result;
            }

            if ($attempt < $attempts) {
                Log::warning('Transcription provider returned empty text; retrying the same provider.', [
                    'provider' => $provider['provider'],
                    'model' => $provider['model'],
                    'attempt' => $attempt,
                    'max_attempts' => $attempts,
                ]);
                usleep(250000 * $attempt);
            }
        }

        throw new \RuntimeException(ServiceUserMessage::emptyTranscriptionResponse((string) $provider['provider']));
    }

    private function summarizeAcrossProviders(
        array $providers,
        string $text,
        ?string $instruction,
        Request $request,
        API $license,
    ): array {
        $providerCount = count($providers);
        $sectionCharacters = min(
            $this->polishChunkCharacters(),
            max(2000, (int) ceil(mb_strlen($text) / $providerCount)),
        );
        $parts = $this->splitTranscript($text, $sectionCharacters);
        $attemptedProviders = [];
        $fallbackUsed = false;
        $sectionInstruction = trim(($instruction ? $instruction.' ' : '')
            .'Summarize only this transcript section. Preserve important names, facts, numbers, decisions, and action items for the final summary.');
        $sectionSummaries = [];

        foreach ($parts as $index => $part) {
            $section = $this->cleanTextAcrossProviders(
                $providers,
                $index % $providerCount,
                $part,
                $sectionInstruction,
                $request,
                $license,
                $attemptedProviders,
            );
            $sectionSummaries[] = $section['result']['text'];
            $fallbackUsed = $fallbackUsed || $section['fallback_used'];
        }

        $combined = implode("\n\n", $sectionSummaries);
        $condenseRounds = 0;

        while (mb_strlen($combined) > $this->polishChunkCharacters() && $condenseRounds < 3) {
            $condensed = [];

            foreach ($this->splitTranscript($combined) as $index => $part) {
                $section = $this->cleanTextAcrossProviders(
                    $providers,
                    $index % $providerCount,
                    $part,
                    'Condense these section summaries while preserving all important facts, decisions, and action items.',
                    $request,
                    $license,
                    $attemptedProviders,
                );
                $condensed[] = $section['result']['text'];
                $fallbackUsed = $fallbackUsed || $section['fallback_used'];
            }

            $combined = implode("\n\n", $condensed);
            $condenseRounds++;
        }

        $finalInstruction = trim(($instruction ? $instruction.' ' : '')
            .'Create one complete final summary from these section summaries. Do not mention the sectioning process.');
        $final = $this->cleanTextAcrossProviders(
            $providers,
            0,
            $combined,
            $finalInstruction,
            $request,
            $license,
            $attemptedProviders,
        );

        return [
            'result' => $final['result'],
            'final_provider' => $final['provider'],
            'attempted_providers' => $attemptedProviders,
            'fallback_used' => $fallbackUsed || $final['fallback_used'],
        ];
    }

    private function cleanTextAcrossProviders(
        array $providers,
        int $startingIndex,
        string $text,
        string $instruction,
        Request $request,
        API $license,
        array &$attemptedProviders,
    ): array {
        $providerCount = count($providers);
        $lastException = null;

        for ($offset = 0; $offset < $providerCount; $offset++) {
            $providerIndex = ($startingIndex + $offset) % $providerCount;
            $provider = $providers[$providerIndex];
            $attemptedProviders[] = $provider['provider'];

            try {
                $cleaner = $this->cleanerForProvider($provider);
                $result = $this->retryCleanerResponse(
                    fn (): array => $cleaner->clean($text, [], ['instructions' => $instruction]),
                    $provider,
                    false,
                );

                if ($offset > 0) {
                    app(ProviderFallbackLogger::class)->recovered(
                        'text_fixer',
                        'polish',
                        $provider,
                        $offset,
                        $request,
                        $license,
                    );
                }

                return [
                    'result' => $result,
                    'provider' => $provider,
                    'fallback_used' => $offset > 0,
                ];
            } catch (Throwable $exception) {
                $lastException = $exception;
                app(ProviderFallbackLogger::class)->failure(
                    'text_fixer',
                    'polish',
                    $provider,
                    $offset,
                    $exception,
                    $request,
                    $license,
                );
            }
        }

        throw $lastException ?? new \RuntimeException(ServiceUserMessage::cleanerFailed());
    }

    private function polishUsingCleaner(
        object $cleaner,
        array $provider,
        string $text,
        array $timestamps,
        array $chunks,
        ?string $instruction,
        ?string $task,
    ): array {
        if ($chunks !== []) {
            return $this->cleanChunkBatches($cleaner, $provider, $chunks, $instruction);
        }

        return $this->cleanLargeText($cleaner, $provider, $text, $timestamps, $instruction, $task);
    }

    private function cleanLargeText(
        object $cleaner,
        array $provider,
        string $text,
        array $timestamps,
        ?string $instruction,
        ?string $task,
    ): array {
        $parts = $this->splitTranscript($text);

        if (count($parts) === 1) {
            return $this->retryCleanerResponse(
                fn (): array => $cleaner->clean($text, $timestamps, ['instructions' => $instruction]),
                $provider,
                false,
            );
        }

        if ($this->isSummaryTask($task, $instruction)) {
            $sectionInstruction = trim(($instruction ? $instruction.' ' : '')
                .'Summarize only this transcript section. Preserve important names, facts, numbers, decisions, and action items for the final summary.');
            $sectionSummaries = [];

            foreach ($parts as $part) {
                $sectionSummaries[] = $this->retryCleanerResponse(
                    fn (): array => $cleaner->clean($part, [], ['instructions' => $sectionInstruction]),
                    $provider,
                    false,
                )['text'];
            }

            $combined = implode("\n\n", $sectionSummaries);
            $condenseRounds = 0;

            while (mb_strlen($combined) > $this->polishChunkCharacters() && $condenseRounds < 3) {
                $condensed = [];

                foreach ($this->splitTranscript($combined) as $part) {
                    $condensed[] = $this->retryCleanerResponse(
                        fn (): array => $cleaner->clean($part, [], [
                            'instructions' => 'Condense these section summaries while preserving all important facts, decisions, and action items.',
                        ]),
                        $provider,
                        false,
                    )['text'];
                }

                $combined = implode("\n\n", $condensed);
                $condenseRounds++;
            }

            $finalInstruction = trim(($instruction ? $instruction.' ' : '')
                .'Create one complete final summary from these section summaries. Do not mention the sectioning process.');

            return $this->retryCleanerResponse(
                fn (): array => $cleaner->clean($combined, [], ['instructions' => $finalInstruction]),
                $provider,
                false,
            );
        }

        $cleanedParts = [];
        $model = null;

        foreach ($parts as $part) {
            $cleaned = $this->retryCleanerResponse(
                fn (): array => $cleaner->clean($part, [], ['instructions' => $instruction]),
                $provider,
                false,
            );
            $cleanedParts[] = $cleaned['text'];
            $model = $cleaned['model'] ?? $model;
        }

        return [
            'text' => implode("\n\n", $cleanedParts),
            'timestamps' => $timestamps,
            'model' => $model,
        ];
    }

    private function cleanChunkBatches(object $cleaner, array $provider, array $chunks, ?string $instruction): array
    {
        $batches = [];
        $batch = [];
        $batchCharacters = 0;
        $limit = $this->polishChunkCharacters();

        foreach ($chunks as $chunk) {
            $chunkCharacters = strlen((string) json_encode($chunk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            if ($batch !== [] && $batchCharacters + $chunkCharacters > $limit) {
                $batches[] = $batch;
                $batch = [];
                $batchCharacters = 0;
            }

            $batch[] = $chunk;
            $batchCharacters += $chunkCharacters;
        }

        if ($batch !== []) {
            $batches[] = $batch;
        }

        $cleanedById = [];
        $model = null;

        foreach ($batches as $chunkBatch) {
            $result = $this->retryCleanerResponse(
                fn (): array => $cleaner->cleanChunks($chunkBatch, ['instructions' => $instruction]),
                $provider,
                true,
            );

            foreach ($result['chunks'] as $cleanedChunk) {
                $cleanedById[(int) $cleanedChunk['audio_chunk_id']] = $cleanedChunk;
            }

            $model = $result['model'] ?? $model;
        }

        return [
            'chunks' => array_map(
                fn (array $chunk): array => $cleanedById[(int) $chunk['id']],
                $chunks,
            ),
            'model' => $model,
        ];
    }

    private function retryCleanerResponse(callable $callback, array $provider, bool $expectsChunks): array
    {
        $attempts = max(1, (int) config('services.transcript_polishing.response_attempts', 3));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $result = $callback();
                $this->assertNonEmptyCleanerResult($result, $expectsChunks);

                return $result;
            } catch (Throwable $exception) {
                if (! $this->isRetryableCleanerResponse($exception) || $attempt === $attempts) {
                    throw $exception;
                }

                Log::warning('Text fixer returned an unusable response; retrying the same provider.', [
                    'provider' => $provider['provider'],
                    'model' => $provider['model'],
                    'attempt' => $attempt,
                    'max_attempts' => $attempts,
                    'failure_type' => $exception::class,
                ]);

                usleep(250000 * $attempt);
            }
        }

        throw new \RuntimeException(ServiceUserMessage::cleanerFailed());
    }

    private function assertNonEmptyCleanerResult(array $result, bool $expectsChunks): void
    {
        if (! $expectsChunks) {
            if (trim((string) ($result['text'] ?? '')) === '') {
                throw new \RuntimeException(ServiceUserMessage::emptyCleanerResponse('Text fixer'));
            }

            return;
        }

        $chunks = $result['chunks'] ?? null;

        if (! is_array($chunks) || $chunks === []) {
            throw new \RuntimeException(ServiceUserMessage::invalidCleanerResponse('Text fixer'));
        }

        foreach ($chunks as $chunk) {
            if (! is_array($chunk) || trim((string) ($chunk['text'] ?? '')) === '') {
                throw new \RuntimeException(ServiceUserMessage::emptyCleanerResponse('Text fixer'));
            }
        }
    }

    private function isRetryableCleanerResponse(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'empty polishing response')
            || str_contains($message, 'invalid polishing response')
            || str_contains($message, 'did not return every transcript chunk');
    }

    private function isSummaryTask(?string $task, ?string $instruction): bool
    {
        if ($task === 'summarize') {
            return true;
        }

        return preg_match('/\b(summary|summarize|summarise|summarized|summarised|summarization|summarisation)\b/i', (string) $instruction) === 1;
    }

    private function splitTranscript(string $text, ?int $maximumCharacters = null): array
    {
        $text = trim($text);
        $limit = $maximumCharacters ?? $this->polishChunkCharacters();

        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        $units = preg_split('/(?<=[.!?])\s+|\R{2,}/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];
        $parts = [];
        $current = '';

        foreach ($units as $unit) {
            $unit = trim($unit);

            while (mb_strlen($unit) > $limit) {
                if ($current !== '') {
                    $parts[] = $current;
                    $current = '';
                }

                $parts[] = trim(mb_substr($unit, 0, $limit));
                $unit = trim(mb_substr($unit, $limit));
            }

            if ($current !== '' && mb_strlen($current.' '.$unit) > $limit) {
                $parts[] = $current;
                $current = '';
            }

            $current = trim($current === '' ? $unit : $current.' '.$unit);
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    private function polishChunkCharacters(): int
    {
        return max(2000, (int) config('services.transcript_polishing.chunk_characters', 16000));
    }

    private function cleanerForProvider(array $provider): GeminiTranscriptCleanerService|GroqTranscriptCleanerService|DeepSeekTranscriptCleanerService|OpenAICompatibleTranscriptCleanerService
    {
        return match ($provider['provider']) {
            AppSettingsService::PROVIDER_GEMINI => new GeminiTranscriptCleanerService(
                apiKey: $provider['api_key'],
                model: $provider['model'],
            ),
            AppSettingsService::PROVIDER_GROQ_TEXT_FIXER => new GroqTranscriptCleanerService(
                apiKey: $provider['api_key'],
                model: $provider['model'],
            ),
            AppSettingsService::PROVIDER_DEEPSEEK => new DeepSeekTranscriptCleanerService(
                apiKey: $provider['api_key'],
                model: $provider['model'],
            ),
            AppSettingsService::PROVIDER_CEREBRAS => new CerebrasTranscriptCleanerService(
                apiKey: $provider['api_key'],
                model: $provider['model'],
            ),
            AppSettingsService::PROVIDER_MISTRAL => new MistralTranscriptCleanerService(
                apiKey: $provider['api_key'],
                model: $provider['model'],
            ),
            AppSettingsService::PROVIDER_OPENROUTER => new OpenRouterTranscriptCleanerService(
                apiKey: $provider['api_key'],
                model: $provider['model'],
            ),
            AppSettingsService::PROVIDER_CLOUDFLARE => new CloudflareTranscriptCleanerService(
                apiKey: $provider['api_key'],
                model: $provider['model'],
                endpoint: app(AppSettingsService::class)->cloudflareChatCompletionsUrl($provider['metadata']['account_id'] ?? null),
            ),
        };
    }

    private function fallbackDetails(array $attemptedProviders): array
    {
        return [
            'used' => count($attemptedProviders) > 1,
        ];
    }
}
