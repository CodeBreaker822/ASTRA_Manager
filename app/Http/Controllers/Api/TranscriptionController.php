<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\API;
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
use App\Services\ServiceUserMessage;
use App\Services\SpeechmaticsSpeechToTextService;
use App\Services\TranscriptionApiRequestLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class TranscriptionController extends Controller
{
    private const RATE_LIMIT_PER_MINUTE = 120;

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
            $validated = $request->validate([
                'audio' => ['required', 'file', 'max:512000'],
                'provider' => ['nullable', 'string', 'max:100'],
                'model' => ['nullable', 'string', 'max:200'],
                'language_code' => ['nullable', 'string', 'max:20'],
                'clip_index' => ['nullable', 'integer', 'min:0'],
                'clip_start_ms' => ['nullable', 'integer', 'min:0'],
                'clip_end_ms' => ['nullable', 'integer', 'min:0'],
            ]);
        } catch (ValidationException $exception) {
            return $this->logAndReturn($request, 'transcribe', $license, $this->validationError($exception), $startedAt);
        }

        $providers = $settings->orderedConnectedProviders('transcriber');
        $attemptedProviders = [];
        $result = null;
        $usedProvider = null;

        foreach ($providers as $position => $provider) {
            $attemptedProviders[] = $provider['provider'];

            try {
                $result = $this->retryEmptyTranscriptionResponse(
                    fn (): array => $this->transcribeUsingProvider($provider, $request, $validated),
                    $provider,
                );
                $usedProvider = $provider;
                app(ProviderFallbackLogger::class)->recovered('transcriber', 'transcribe', $provider, $position, $request, $license);
                break;
            } catch (Throwable $exception) {
                app(ProviderFallbackLogger::class)->failure('transcriber', 'transcribe', $provider, $position, $exception, $request, $license);
                report($exception);
            }
        }

        if ($result === null || $usedProvider === null) {
            return $this->logAndReturn($request, 'transcribe', $license, response()->json([
                'message' => 'All configured transcription providers are unavailable.',
            ], 503), $startedAt, [
                'status' => 'provider_error',
                'severity' => 'high',
                'attempted_providers' => $attemptedProviders,
            ]);
        }

        $response = response()->json([
            'text' => $result['text'] ?? '',
            'timestamps' => $result['timestamps'] ?? [],
            'provider' => AppSettingsService::PUBLIC_PROVIDER_ID,
            'provider_name' => AppSettingsService::PUBLIC_PROVIDER_NAME,
            'model' => AppSettingsService::PUBLIC_MODEL,
            'clip_index' => $validated['clip_index'] ?? null,
            'clip_start_ms' => $validated['clip_start_ms'] ?? null,
            'clip_end_ms' => $validated['clip_end_ms'] ?? null,
            'fallback' => $this->fallbackDetails($attemptedProviders),
        ]);

        return $this->logAndReturn($request, 'transcribe', $license, $response, $startedAt, [
            'provider' => $usedProvider['provider'],
            'model' => $usedProvider['model'],
            'attempted_providers' => $attemptedProviders,
        ]);
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
            ? API::query()->where('app_token', $token)->first()
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

    private function licenseFor(Request $request, string $method): API|JsonResponse
    {
        $token = $request->bearerToken();

        if (! is_string($token) || $token === '') {
            return response()->json(['message' => 'Missing Bearer license key.'], 401);
        }

        $license = API::query()->where('app_token', $token)->first();

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

    private function transcriberUpdate(): array
    {
        $version = null;
        $versionPath = Storage::disk('local')->path('transcriber/version.json');

        if (is_file($versionPath) && is_readable($versionPath)) {
            try {
                $data = json_decode((string) file_get_contents($versionPath), true, 512, JSON_THROW_ON_ERROR);

                if (is_array($data) && array_key_exists('version', $data)) {
                    $version = $data['version'];
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

    private function transcribeUsingProvider(array $provider, Request $request, array $options): array
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
        };

        return $service->transcribe($request->file('audio'), $options);
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
