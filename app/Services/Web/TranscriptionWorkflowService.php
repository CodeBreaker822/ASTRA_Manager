<?php

namespace App\Services\Web;

use App\Models\Transcript;
use App\Models\TranscriptProject;
use App\Services\WebApiTranscriptionClient;
use App\Services\WebAudioChunkerService;
use App\Services\WebTranscriptProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

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
            return $this->chunker->clipsFromUpload(
                $request->file('audio'),
                (int) ($validated['duration_seconds'] ?? 0),
            );
        }

        return [
            'clips' => $clips,
            'cleanup' => null,
        ];
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
        try {
            $payload = $this->transcriptionClient->queue(
                $request->user(),
                $this->storedClipPayloads($transcript),
                $languageCode,
            );
        } catch (\Throwable) {
            $this->processor->failTranscription($transcript);

            return;
        }

        $this->appendProcessingLog($transcript, 'queued', 'Queued', [
            'api_job_id' => (string) $payload['job_id'],
            'api_job_status_url' => (string) ($payload['status_url'] ?? ''),
        ]);
    }

    public function syncApiTranscriptionJobs(Request $request, TranscriptProject $project): void
    {
        $user = $request->user();

        foreach ($project->transcripts()->whereIn('status', ['queued', 'processing'])->get() as $transcript) {
            $apiJobId = $this->apiJobId($transcript);

            if ($apiJobId === '') {
                continue;
            }

            try {
                $payload = $this->transcriptionClient->jobStatus($user, $apiJobId);
            } catch (\Throwable) {
                continue;
            }

            $status = (string) ($payload['status'] ?? '');

            if ($status === 'completed') {
                $result = $payload['result'] ?? [];

                if (is_array($result)) {
                    $this->processor->completeTranscription($transcript, $result);
                }

                continue;
            }

            if ($status === 'failed') {
                $this->processor->failTranscription($transcript);

                continue;
            }

            if ($status === 'processing' && $transcript->status !== 'processing') {
                $this->processor->appendLog($transcript, 'processing', 'Processing');
            }
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

    private function apiJobId(Transcript $transcript): string
    {
        $log = array_reverse($transcript->processing_log ?? []);

        foreach ($log as $entry) {
            $jobId = $entry['context']['api_job_id'] ?? null;

            if (is_string($jobId) && $jobId !== '') {
                return $jobId;
            }
        }

        return '';
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
