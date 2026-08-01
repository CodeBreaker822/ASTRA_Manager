<?php

namespace App\Services\Web;

use App\Models\Transcript;
use App\Models\TranscriptProject;
use App\Services\WebApiTranscriptionClient;
use App\Services\WebAudioChunkerService;
use App\Services\WebTranscriptProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class TranscriptionWorkflowService
{
    public function __construct(
        private readonly WebApiTranscriptionClient $transcriptionClient,
        private readonly WebAudioChunkerService $chunker,
        private readonly WebTranscriptProcessor $processor,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array{clips: array<int, array<string, mixed>>, cleanup: string|null}
     */
    public function prepareUploadClips(Request $request, array $validated): array
    {
        $clips = $this->normalizeClips($request, $validated);

        if (($validated['server_chunk'] ?? false) && $request->file('audio') instanceof UploadedFile) {
            return $this->prepareUploadFile($request->file('audio'), $validated);
        }

        return [
            'clips' => $clips,
            'cleanup' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{clips: array<int, array<string, mixed>>, cleanup: string|null}
     */
    public function prepareUploadFile(UploadedFile $file, array $validated): array
    {
        return $this->chunker->clipsFromUpload(
            $file,
            (int) ($validated['duration_seconds'] ?? 0),
        );
    }

    public function cleanupPreparedUpload(?string $directory): void
    {
        $this->chunker->cleanup($directory);
    }

    /**
     * @param  array<int, array<string, mixed>>  $clips
     */
    public function batchIsTooLarge(array $clips): bool
    {
        return $this->transcriptionClient->batchIsTooLarge($clips);
    }

    /**
     * @param  array<int, array<string, mixed>>  $clips
     */
    public function durationSeconds(array $clips, int $fallback): int
    {
        $durationMs = 0;

        foreach ($clips as $clip) {
            $startMs = $clip['clip_start_ms'] ?? null;
            $endMs = $clip['clip_end_ms'] ?? null;

            if (! is_numeric($startMs) || ! is_numeric($endMs)) {
                return $fallback;
            }

            $durationMs += max(0, (int) $endMs - (int) $startMs);
        }

        return $durationMs > 0 ? (int) ceil($durationMs / 1000) : $fallback;
    }

    /**
     * @param  array<int, array<string, mixed>>  $clips
     */
    public function queueTranscript(Request $request, TranscriptProject $project, string $source, array $clips, int $durationSeconds): Transcript
    {
        $transcript = $project->transcripts()->create([
            'source' => $source,
            'status' => 'queued',
            'duration_seconds' => $durationSeconds,
            'processing_log' => [],
        ]);

        $storedClips = [];

        foreach ($clips as $queueIndex => $clip) {
            $audio = $clip['audio'];
            $extension = $audio?->getClientOriginalExtension() ?: 'webm';
            $filename = 'clip-'.$queueIndex.'-'.Str::uuid().'.'.$extension;
            $path = $audio->storeAs(
                'web-transcripts/'.$request->user()->id.'/'.$project->id.'/'.$transcript->id,
                $filename,
                'local',
            );

            $storedClips[] = [
                ...$clip,
                'audio' => null,
                'path' => $path,
                'name' => $audio?->getClientOriginalName() ?: $filename,
            ];
        }

        $transcript->forceFill([
            'audio_path' => (string) ($storedClips[0]['path'] ?? ''),
            'processing_log' => [
                [
                    'status' => 'queued',
                    'message' => 'Queued',
                    'context' => ['clips' => $storedClips],
                    'created_at' => now()->toISOString(),
                ],
            ],
        ])->save();

        return $transcript;
    }

    public function startApiTranscription(Request $request, Transcript $transcript, ?string $languageCode): void
    {
        $apiJobs = [];

        try {
            foreach ($this->storedClipBatches($transcript) as $queueIndex => $clips) {
                $payload = $this->transcriptionClient->queue(
                    $request->user(),
                    $clips,
                    $languageCode,
                    $transcript->source === 'live' ? 'live' : 'upload',
                );

                $apiJobs[] = [
                    'job_id' => (string) $payload['job_id'],
                    'status_url' => (string) ($payload['status_url'] ?? ''),
                    'status' => (string) ($payload['status'] ?? 'queued'),
                    'queue_index' => $queueIndex,
                    'clip_count' => count($clips),
                    'clip_indexes' => array_map(
                        fn (array $clip): int => (int) ($clip['clip_index'] ?? 0),
                        $clips,
                    ),
                    'result' => null,
                ];
            }
        } catch (Throwable $exception) {
            Log::error('Web audio async transcription job could not be started.', [
                'user_id' => $request->user()?->id,
                'transcript_id' => $transcript->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $this->processor->failTranscription($transcript);

            return;
        }

        $this->appendProcessingLog($transcript, 'queued', 'Queued', [
            'api_jobs' => $apiJobs,
        ]);
    }

    public function syncApiTranscriptionJobs(Request $request, TranscriptProject $project): void
    {
        $user = $request->user();

        foreach ($project->transcripts()->whereIn('status', ['queued', 'processing'])->get() as $transcript) {
            $apiJobs = $this->apiJobs($transcript);
            $pendingIndex = $this->pendingApiJobIndex($apiJobs);

            if ($pendingIndex === null) {
                if ($apiJobs !== [] && $this->apiJobsCompleted($apiJobs)) {
                    $this->processor->completeTranscription($transcript, $this->combinedApiJobResult($apiJobs));
                }

                continue;
            }

            $apiJobId = (string) ($apiJobs[$pendingIndex]['job_id'] ?? '');

            if ($apiJobId === '') {
                $this->processor->failTranscription($transcript);

                continue;
            }

            try {
                $payload = $this->transcriptionClient->jobStatus($user, $apiJobId);
            } catch (Throwable $exception) {
                Log::error('Web audio async transcription status sync failed.', [
                    'user_id' => $user?->id,
                    'project_id' => $project->id,
                    'transcript_id' => $transcript->id,
                    'api_job_id' => $apiJobId,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);

                continue;
            }

            $status = (string) ($payload['status'] ?? '');
            $apiJobs[$pendingIndex]['status'] = $status;

            if ($status === 'completed') {
                $result = $payload['result'] ?? [];

                if (! is_array($result)) {
                    $apiJobs[$pendingIndex]['status'] = 'failed';
                    $this->persistApiJobs($transcript, $apiJobs, 'processing');
                    $this->processor->failTranscription($transcript);

                    continue;
                }

                $apiJobs[$pendingIndex]['result'] = $result;

                $this->persistApiJobs($transcript, $apiJobs, 'processing');
                $combinedResult = $this->combinedApiJobResult($apiJobs);

                if ($this->apiJobsCompleted($apiJobs)) {
                    $this->processor->completeTranscription($transcript, $combinedResult);
                } else {
                    $this->processor->updatePartialTranscription($transcript, $combinedResult);
                }

                continue;
            }

            if ($status === 'failed') {
                Log::error('Web audio async transcription job returned failed status.', [
                    'user_id' => $user?->id,
                    'project_id' => $project->id,
                    'transcript_id' => $transcript->id,
                    'api_job_id' => $apiJobId,
                    'api_status_code' => $payload['status_code'] ?? null,
                    'message' => $payload['message'] ?? null,
                ]);

                $this->persistApiJobs($transcript, $apiJobs, 'processing');
                $this->processor->failTranscription($transcript);

                continue;
            }

            $this->persistApiJobs(
                $transcript,
                $apiJobs,
                $status === 'processing' ? 'processing' : $transcript->status,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int, array<string, mixed>>
     */
    public function normalizeClips(Request $request, array $validated): array
    {
        $audio = $request->file('audio');
        $files = is_array($audio) ? array_values($audio) : [$audio];

        return array_map(function ($file, int $index) use ($validated): array {
            return [
                'audio' => $file,
                'clip_index' => $this->indexedValue($validated, 'clip_index', $index) ?? $index,
                'clip_start_ms' => $this->indexedValue($validated, 'clip_start_ms', $index) ?? 0,
                'clip_end_ms' => $this->indexedValue($validated, 'clip_end_ms', $index) ?? (int) ($validated['duration_seconds'] ?? 0) * 1000,
                'language_code' => $this->indexedValue($validated, 'language_code', $index),
            ];
        }, $files, array_keys($files));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function storedClipPayloads(Transcript $transcript): array
    {
        $log = $transcript->processing_log ?? [];
        $first = $log[0]['context']['clips'] ?? [];

        return is_array($first) ? array_values(array_filter($first, 'is_array')) : [];
    }

    /**
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function storedClipBatches(Transcript $transcript): array
    {
        $batches = [];
        $batch = [];
        $batchDurationMs = 0;

        foreach ($this->storedClipPayloads($transcript) as $clip) {
            $durationMs = $this->clipDurationMs($clip);
            $wouldExceedDuration = $batch !== []
                && ($batchDurationMs + $durationMs) > WebApiTranscriptionClient::MAX_BATCH_DURATION_MS;

            if (count($batch) >= WebApiTranscriptionClient::MAX_BATCH_CLIPS || $wouldExceedDuration) {
                $batches[] = $batch;
                $batch = [];
                $batchDurationMs = 0;
            }

            $batch[] = $clip;
            $batchDurationMs += $durationMs;
        }

        if ($batch !== []) {
            $batches[] = $batch;
        }

        return $batches;
    }

    /**
     * @param  array<string, mixed>  $clip
     */
    private function clipDurationMs(array $clip): int
    {
        $startMs = $clip['clip_start_ms'] ?? null;
        $endMs = $clip['clip_end_ms'] ?? null;

        if (! is_numeric($startMs) || ! is_numeric($endMs)) {
            return WebApiTranscriptionClient::MAX_BATCH_DURATION_MS;
        }

        return max(0, (int) $endMs - (int) $startMs);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function appendProcessingLog(Transcript $transcript, string $status, string $message, array $context = []): void
    {
        $log = $transcript->processing_log ?? [];
        $log[] = [
            'status' => $status,
            'message' => $message,
            'context' => $context,
            'created_at' => now()->toISOString(),
        ];

        $transcript->forceFill([
            'status' => $status,
            'processing_log' => $log,
        ])->save();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function apiJobs(Transcript $transcript): array
    {
        $log = array_reverse($transcript->processing_log ?? []);

        foreach ($log as $entry) {
            $jobs = $entry['context']['api_jobs'] ?? null;

            if (is_array($jobs)) {
                return array_values(array_filter($jobs, 'is_array'));
            }

            $jobId = $entry['context']['api_job_id'] ?? null;

            if (is_string($jobId) && $jobId !== '') {
                return [[
                    'job_id' => $jobId,
                    'status_url' => (string) ($entry['context']['api_job_status_url'] ?? ''),
                    'status' => $transcript->status,
                    'queue_index' => 0,
                    'clip_count' => 1,
                    'clip_indexes' => [0],
                    'result' => null,
                ]];
            }
        }

        return [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $apiJobs
     */
    private function pendingApiJobIndex(array $apiJobs): ?int
    {
        foreach ($apiJobs as $index => $apiJob) {
            if (($apiJob['status'] ?? null) !== 'completed') {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $apiJobs
     */
    private function apiJobsCompleted(array $apiJobs): bool
    {
        return $apiJobs !== [] && collect($apiJobs)->every(
            fn (array $apiJob): bool => ($apiJob['status'] ?? null) === 'completed'
                && is_array($apiJob['result'] ?? null)
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $apiJobs
     */
    private function persistApiJobs(Transcript $transcript, array $apiJobs, string $status): void
    {
        $log = $transcript->processing_log ?? [];
        $apiJobsEntry = null;

        for ($index = count($log) - 1; $index >= 0; $index--) {
            if (is_array($log[$index]['context']['api_jobs'] ?? null)) {
                $apiJobsEntry = $index;

                break;
            }
        }

        if ($apiJobsEntry === null) {
            $log[] = [
                'status' => 'queued',
                'message' => 'Queued',
                'context' => ['api_jobs' => $apiJobs],
                'created_at' => now()->toISOString(),
            ];
        } else {
            $log[$apiJobsEntry]['context']['api_jobs'] = $apiJobs;
        }

        if ($status === 'processing' && $transcript->status !== 'processing') {
            $log[] = [
                'status' => 'processing',
                'message' => 'Processing',
                'context' => [],
                'created_at' => now()->toISOString(),
            ];
        }

        $transcript->forceFill([
            'status' => $status,
            'processing_log' => $log,
        ])->save();
    }

    /**
     * @param  array<int, array<string, mixed>>  $apiJobs
     * @return array<string, mixed>
     */
    private function combinedApiJobResult(array $apiJobs): array
    {
        usort($apiJobs, fn (array $left, array $right): int => ((int) ($left['queue_index'] ?? 0)) <=> ((int) ($right['queue_index'] ?? 0)));

        $clips = [];

        foreach ($apiJobs as $apiJob) {
            if (($apiJob['status'] ?? null) !== 'completed' || ! is_array($apiJob['result'] ?? null)) {
                continue;
            }

            $result = $apiJob['result'];
            $resultClips = array_values(array_filter($result['clips'] ?? [], 'is_array'));

            if ($resultClips === [] && $result !== []) {
                $resultClips[] = $result;
            }

            foreach ($resultClips as $clip) {
                $clips[] = $clip;
            }
        }

        return [
            'text' => collect($clips)->pluck('text')->filter()->implode("\n\n"),
            'duration_ms' => collect($clips)->sum(fn (array $clip): int => (int) ($clip['duration_ms'] ?? 0)),
            'clips' => $clips,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function indexedValue(array $validated, string $key, int $index): mixed
    {
        $value = $validated[$key] ?? null;

        return is_array($value) ? ($value[$index] ?? null) : ($index === 0 ? $value : null);
    }
}
