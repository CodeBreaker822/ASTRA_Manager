<?php

namespace App\Http\Controllers\Api\TranscriptionController;

use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\API;
use App\Models\ApiTranscriptionJob;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use App\Services\Transcription\AppSettingsService;
use App\Services\Transcription\AssemblyAiSpeechToTextService;
use App\Services\Transcription\AwsTranscribeSpeechToTextService;
use App\Services\Transcription\AzureSpeechToTextService;
use App\Services\Transcription\DeepgramSpeechToTextService;
use App\Services\Transcription\ElevenLabsSpeechToTextService;
use App\Services\Transcription\GladiaSpeechToTextService;
use App\Services\Transcription\GoogleCloudSpeechToTextService;
use App\Services\Transcription\GroqSpeechToTextService;
use App\Services\Transcription\MistralSpeechToTextService;
use App\Services\Transcription\ProviderFallbackLogger;
use App\Services\Transcription\RunPodSpeechToTextService;
use App\Services\Transcription\ServiceUserMessage;
use App\Services\Transcription\SpeechmaticsSpeechToTextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class TranscriptionController extends BaseTranscriptionApiController
{
    use InteractsWithRuntimeProviders;

    private const RUNPOD_API_BASE_URL = 'https://api.runpod.ai/v2';

    private const MAX_TRANSCRIBE_BATCH_DURATION_MS = 20 * 60 * 1000;

    private const MAX_TRANSCRIBE_BATCH_CLIPS = 20;

    // How long a dispatched job may sit unclaimed before the status endpoint
    // assumes no worker is running and transcribes it inline instead.
    private const QUEUE_WORKER_PICKUP_SECONDS = 90;

    // How long a claimed job may run before its worker is presumed dead. A
    // batch caps at 20 minutes of audio, so this leaves generous headroom.
    private const QUEUE_WORKER_STALL_SECONDS = 1800;

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
                'billing_feature' => ['nullable', 'string', 'in:upload,live'],
            ]));
        } catch (ValidationException $exception) {
            return $this->logAndReturn($request, 'transcribe', $license, $this->validationError($exception), $startedAt);
        }

        $queuedClips = $this->normalizeTranscribeClips($request, $validated);
        $billingFeature = $this->billingFeature($validated['billing_feature'] ?? null);
        $billingSeconds = $this->billableDurationSeconds($queuedClips);

        $billingError = $this->transcriptionBillingPreflight($license, $billingFeature, $billingSeconds);

        if ($billingError instanceof JsonResponse) {
            return $this->logAndReturn($request, 'transcribe', $license, $billingError, $startedAt, [
                'status' => 'billing_denied',
                'severity' => 'high',
                'billing_feature' => $billingFeature,
                'billing_seconds' => $billingSeconds,
            ]);
        }

        if (($validated['response_mode'] ?? null) === 'async') {
            return $this->createAsyncTranscriptionJob($request, $license, $queuedClips, $startedAt, $billingFeature, $billingSeconds);
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

        $billingError = $this->chargeTranscriptionUsage($license, $billingFeature, $billingSeconds);

        if ($billingError instanceof JsonResponse) {
            return $this->logAndReturn($request, 'transcribe', $license, $billingError, $startedAt, [
                'status' => 'billing_failed',
                'severity' => 'high',
                'billing_feature' => $billingFeature,
                'billing_seconds' => $billingSeconds,
            ]);
        }

        return $this->logAndReturn($request, 'transcribe', $license, $response, $startedAt, [
            'provider' => implode(',', array_values(array_unique($usedProviders))),
            'attempted_providers' => $attemptedProviders,
            'billing_feature' => $billingFeature,
            'billing_seconds' => $billingSeconds,
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

    private function billingFeature(mixed $value): string
    {
        return $value === 'live' ? 'live' : 'upload';
    }

    /**
     * @param  array<int, array<string, mixed>>  $clips
     */
    private function billableDurationSeconds(array $clips): int
    {
        $durationMs = 0;

        foreach ($clips as $clip) {
            $clipDurationMs = $this->clipDurationMs($clip);

            if ($clipDurationMs !== null && $clipDurationMs > 0) {
                $durationMs += $clipDurationMs;
            }
        }

        return $durationMs > 0 ? (int) ceil($durationMs / 1000) : 0;
    }

    private function billingUser(API $license): ?User
    {
        if (! $license->user_id) {
            return null;
        }

        if ($license->relationLoaded('user')) {
            return $license->user instanceof User ? $license->user : null;
        }

        return $license->user()->first();
    }

    private function transcriptionBillingPreflight(API $license, string $feature, int $seconds): ?JsonResponse
    {
        $user = $this->billingUser($license);

        if (! $user instanceof User) {
            return response()->json([
                'message' => 'Transcription requires an account license for billing.',
            ], 403);
        }

        if ($seconds <= 0) {
            return response()->json([
                'message' => 'Transcription duration is required for account billing.',
            ], 422);
        }

        $entitlements = app(EntitlementService::class);

        try {
            if (! $entitlements->allows($user, $feature)) {
                return response()->json([
                    'message' => ucfirst($feature).' transcription is not available for this account.',
                ], 403);
            }

            if (! $entitlements->canAfford($user, $feature, $seconds)) {
                return response()->json([
                    'message' => 'Insufficient balance for transcription. Please add funds to continue.',
                ], 402);
            }
        } catch (Throwable $exception) {
            Log::error('Transcription billing preflight failed.', [
                'license_id' => $license->id,
                'user_id' => $user->id,
                'feature' => $feature,
                'seconds' => $seconds,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            report($exception);

            return response()->json([
                'message' => 'Billing could not be verified. Please try again.',
            ], 500);
        }

        return null;
    }

    private function chargeTranscriptionUsage(API $license, string $feature, int $seconds): ?JsonResponse
    {
        $user = $this->billingUser($license);

        if (! $user instanceof User) {
            return response()->json([
                'message' => 'Transcription requires an account license for billing.',
            ], 403);
        }

        if ($seconds <= 0) {
            return response()->json([
                'message' => 'Transcription duration is required for account billing.',
            ], 422);
        }

        try {
            app(EntitlementService::class)->charge($user, $feature, $seconds);
        } catch (InsufficientWalletBalanceException) {
            return response()->json([
                'message' => 'Insufficient balance for transcription. Please add funds to continue.',
            ], 402);
        } catch (Throwable $exception) {
            Log::error('Transcription billing charge failed.', [
                'license_id' => $license->id,
                'user_id' => $user->id,
                'feature' => $feature,
                'seconds' => $seconds,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            report($exception);

            return response()->json([
                'message' => 'Billing could not be completed. Please try again.',
            ], 500);
        }

        return null;
    }

    private function billCompletedAsyncTranscriptionJob(ApiTranscriptionJob $job, API $license): bool
    {
        return DB::transaction(function () use ($job, $license): bool {
            $lockedJob = ApiTranscriptionJob::query()
                ->whereKey($job->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedJob->billed_at !== null) {
                return true;
            }

            $payload = is_array($lockedJob->request_payload) ? $lockedJob->request_payload : [];
            $feature = $this->billingFeature($lockedJob->billing_feature ?? $payload['billing_feature'] ?? null);
            $seconds = (int) ($lockedJob->billing_seconds ?: ($payload['billing_seconds'] ?? 0));

            if ($seconds <= 0) {
                $seconds = $this->billableDurationSeconds(array_values(array_filter($payload['clips'] ?? [], 'is_array')));
            }

            $billingError = $this->chargeTranscriptionUsage($license, $feature, $seconds);

            if ($billingError instanceof JsonResponse) {
                $errorPayload = json_decode((string) $billingError->getContent(), true);
                $message = is_array($errorPayload) && is_string($errorPayload['message'] ?? null)
                    ? $errorPayload['message']
                    : 'Billing could not be completed.';

                $lockedJob->forceFill([
                    'status' => 'failed',
                    'result_payload' => null,
                    'error_message' => $message,
                    'status_code' => $billingError->getStatusCode(),
                    'billing_feature' => $feature,
                    'billing_seconds' => $seconds,
                    'finished_at' => now(),
                ])->save();

                return false;
            }

            $lockedJob->forceFill([
                'billing_feature' => $feature,
                'billing_seconds' => $seconds,
                'billed_at' => now(),
            ])->save();

            return true;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $queuedClips
     */
    private function createAsyncTranscriptionJob(
        Request $request,
        API $license,
        array $queuedClips,
        float $startedAt,
        string $billingFeature,
        int $billingSeconds,
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
                    Log::error('Async transcription upload source file is not readable.', [
                        'license_id' => $license->id,
                        'job_id' => $jobId,
                        'clip_index' => $clip['queue_index'] ?? null,
                        'original_name' => $audio?->getClientOriginalName(),
                        'real_path' => $path,
                    ]);

                    throw new \RuntimeException(ServiceUserMessage::audioReadFailed());
                }

                $extension = $audio?->getClientOriginalExtension() ?: pathinfo((string) $audio?->getClientOriginalName(), PATHINFO_EXTENSION);
                $extension = $extension ?: 'audio';
                $storedPath = 'transcription-jobs/'.$jobId.'/clip-'.$clip['queue_index'].'.'.$extension;
                $contents = file_get_contents($path);

                if ($contents === false) {
                    Log::error('Async transcription upload source file could not be read.', [
                        'license_id' => $license->id,
                        'job_id' => $jobId,
                        'clip_index' => $clip['queue_index'] ?? null,
                        'original_name' => $audio?->getClientOriginalName(),
                        'real_path' => $path,
                        'size' => is_file($path) ? filesize($path) : null,
                    ]);

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
                'billing_feature' => $billingFeature,
                'billing_seconds' => $billingSeconds,
            ];
            $status = 'queued';

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
            }

            $transcriptionJob = ApiTranscriptionJob::query()->create([
                'id' => $jobId,
                'api_id' => $license->id,
                'status' => $status,
                'request_payload' => $requestPayload,
                'billing_feature' => $billingFeature,
                'billing_seconds' => $billingSeconds,
                'started_at' => $status === 'processing' ? now() : null,
            ]);

            $response = response()->json([
                'job_id' => $transcriptionJob->id,
                'status' => $transcriptionJob->status,
                'status_url' => url('/api/transcribe/jobs/'.$transcriptionJob->id),
            ], 202);

            return $this->logAndReturn($request, 'transcribe', $license, $response, $startedAt, [
                'status' => 'queued',
                'async' => true,
                'clip_count' => count($storedClips),
                'billing_feature' => $billingFeature,
                'billing_seconds' => $billingSeconds,
            ]);
        } catch (Throwable $exception) {
            Log::error('Async transcription job creation failed.', [
                'license_id' => $license->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

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

        if ($statusCode < 400 && ! $this->billCompletedAsyncTranscriptionJob($job, $license)) {
            $this->deleteAsyncTranscriptionAudio($payload);

            return;
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
                Log::error('Async transcription stored audio file is not readable.', [
                    'stored_path' => $audioPath,
                    'absolute_path' => $absolutePath,
                    'clip_index' => $clip['clip_index'] ?? null,
                    'queue_index' => $clip['queue_index'] ?? null,
                    'audio_name' => $clip['audio_name'] ?? null,
                ]);

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

            if (($payload['mode'] ?? null) === 'queue_worker') {
                $this->refreshQueueWorkerTranscriptionJob($job);
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

            $clipTranscripts = array_map(function (array $clip, int $index) use ($results): array {
                $result = $this->resultForBatchClip($results, $clip, $index);

                return $this->clipTranscript($clip, $result, [AppSettingsService::PROVIDER_RUNPOD]);
            }, $clips, array_keys($clips));
            $responsePayload = $this->transcriptionPayloadFromClips(
                $clipTranscripts,
                [AppSettingsService::PROVIDER_RUNPOD],
                [AppSettingsService::PROVIDER_RUNPOD],
            );

            if (! $this->billCompletedAsyncTranscriptionJob($job, $license)) {
                $this->deleteAsyncTranscriptionAudio($payload);

                return;
            }

            $job->forceFill([
                'status' => 'completed',
                'result_payload' => $responsePayload,
                'error_message' => null,
                'status_code' => 200,
                'finished_at' => now(),
            ])->save();

            app(ProviderFallbackLogger::class)->success('transcriber', 'transcribe', $provider, 0, $request, $license);

            $this->deleteAsyncTranscriptionAudio($payload);
        } catch (Throwable $exception) {
            app(ProviderFallbackLogger::class)->failure('transcriber', 'transcribe', $provider, 0, $exception, $request, $license);
            report($exception);

            $this->completeAsyncTranscriptionWithFallback($job, $payload, $request, $license, $exception);
        }

    }

    /**
     * Keeps a worker-owned job honest without doing its work.
     *
     * The worker transcribes; this only watches the clock. A job no worker
     * claimed is transcribed inline so a deployment running no worker at all
     * still finishes, and a job whose worker died mid-run is failed instead of
     * pinning the caller's progress bar forever.
     */
    private function refreshQueueWorkerTranscriptionJob(ApiTranscriptionJob $job): void
    {
        if ($job->status === 'queued') {
            if ($job->created_at?->gt(now()->subSeconds(self::QUEUE_WORKER_PICKUP_SECONDS))) {
                return;
            }

            Log::warning('No queue worker claimed this transcription job; transcribing inline.', [
                'job_id' => $job->id,
                'created_at' => $job->created_at?->toISOString(),
            ]);

            $this->processAsyncTranscriptionJob($job);

            return;
        }

        if ($job->status !== 'processing') {
            return;
        }

        $runningSince = $job->started_at ?? $job->created_at;

        if (! $runningSince || $runningSince->gt(now()->subSeconds(self::QUEUE_WORKER_STALL_SECONDS))) {
            return;
        }

        Log::error('Queue worker stopped reporting on this transcription job; failing it.', [
            'job_id' => $job->id,
            'started_at' => $job->started_at?->toISOString(),
        ]);

        $job->forceFill([
            'status' => 'failed',
            'result_payload' => null,
            'error_message' => 'Transcription job failed.',
            'status_code' => 500,
            'finished_at' => now(),
        ])->save();

        $this->deleteAsyncTranscriptionAudio(is_array($job->request_payload) ? $job->request_payload : []);
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
                if (! $this->billCompletedAsyncTranscriptionJob($job, $license)) {
                    return;
                }

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
                endpoint: $this->providerMetadataString($provider, 'listen_url'),
                modelId: $provider['model'],
                timeout: $this->providerMetadataInt($provider, 'timeout'),
            ),
            AppSettingsService::PROVIDER_ELEVENLABS => new ElevenLabsSpeechToTextService(
                apiKey: $provider['api_key'],
                endpoint: $this->providerMetadataString($provider, 'speech_to_text_url'),
                modelId: $provider['model'],
                timeout: $this->providerMetadataInt($provider, 'timeout'),
            ),
            AppSettingsService::PROVIDER_SPEECHMATICS => new SpeechmaticsSpeechToTextService(
                apiKey: $provider['api_key'],
                baseUrl: $this->providerMetadataString($provider, 'base_url'),
                modelId: $provider['model'],
                timeout: $this->providerMetadataInt($provider, 'timeout'),
                pollIntervalMs: $this->providerMetadataInt($provider, 'poll_interval_ms'),
                maxWaitSeconds: $this->providerMetadataInt($provider, 'max_wait_seconds'),
            ),
            AppSettingsService::PROVIDER_GROQ_TRANSCRIPTION => new GroqSpeechToTextService(
                apiKey: $provider['api_key'],
                endpoint: $this->providerMetadataString($provider, 'transcription_url'),
                modelId: $provider['model'],
                modelsUrl: $this->providerMetadataString($provider, 'models_url'),
                timeout: $this->providerMetadataInt($provider, 'timeout'),
            ),
            AppSettingsService::PROVIDER_MISTRAL_TRANSCRIPTION => new MistralSpeechToTextService(
                apiKey: $provider['api_key'],
                endpoint: $this->providerMetadataString($provider, 'transcription_url'),
                modelId: $provider['model'],
                modelsUrl: $this->providerMetadataString($provider, 'models_url'),
                timeout: $this->providerMetadataInt($provider, 'timeout'),
            ),
            AppSettingsService::PROVIDER_GLADIA => new GladiaSpeechToTextService(
                $provider['api_key'],
                $provider['model'],
                $this->providerMetadataString($provider, 'base_url'),
                $this->providerMetadataInt($provider, 'poll_interval_ms'),
                $this->providerMetadataInt($provider, 'max_wait_seconds'),
                $this->providerMetadataInt($provider, 'timeout'),
            ),
            AppSettingsService::PROVIDER_ASSEMBLYAI => new AssemblyAiSpeechToTextService(
                $provider['api_key'],
                $provider['model'],
                $this->providerMetadataString($provider, 'base_url'),
                $this->providerMetadataInt($provider, 'poll_interval_ms'),
                $this->providerMetadataInt($provider, 'max_wait_seconds'),
                $this->providerMetadataInt($provider, 'timeout'),
            ),
            AppSettingsService::PROVIDER_AZURE_SPEECH => new AzureSpeechToTextService(
                $provider['api_key'],
                $provider['model'],
                $this->providerMetadataString($provider, 'fast_transcription_url'),
            ),
            AppSettingsService::PROVIDER_GOOGLE_SPEECH => new GoogleCloudSpeechToTextService(
                $provider['api_key'],
                $provider['model'],
                $this->providerMetadataString($provider, 'base_url'),
                $this->providerMetadataString($provider, 'token_url'),
            ),
            AppSettingsService::PROVIDER_AWS_TRANSCRIBE => new AwsTranscribeSpeechToTextService(
                $provider['api_key'],
                $provider['model'],
                pollIntervalMs: $this->providerMetadataInt($provider, 'poll_interval_ms'),
                maxWaitSeconds: $this->providerMetadataInt($provider, 'max_wait_seconds'),
            ),
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
            $this->providerMetadataInt($provider, 'timeout'),
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
                $this->providerMetadataInt($provider, 'timeout'),
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

            app(ProviderFallbackLogger::class)->success('transcriber', 'transcribe', $provider, 0, $request, $license);

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
                $result = $this->transcribeUsingProvider($provider, $clip['audio'], $clip);

                app(ProviderFallbackLogger::class)->success('transcriber', 'transcribe', $provider, $offset, $request, $license);

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
}
