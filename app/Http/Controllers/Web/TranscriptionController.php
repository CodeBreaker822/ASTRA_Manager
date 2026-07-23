<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWebTranscriptJob;
use App\Models\Transcript;
use App\Models\TranscriptProject;
use App\Services\EntitlementService;
use App\Services\Web\TranscriptPayloadPresenter;
use App\Services\WebApiTranscriptionClient;
use App\Services\WebAudioChunkerService;
use App\Services\WebTranscriptProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

class TranscriptionController extends Controller
{
    public function upload(
        Request $request,
        TranscriptProject $project,
        EntitlementService $entitlements,
        TranscriptPayloadPresenter $payloads,
    ): JsonResponse {
        $this->authorizeProject($request, $project);

        $audioRules = is_array($request->file('audio'))
            ? [
                'audio' => ['required', 'array', 'min:1', 'max:'.WebApiTranscriptionClient::MAX_BATCH_CLIPS],
                'audio.*' => ['required', 'file', 'max:512000'],
            ]
            : ['audio' => ['required', 'file', 'max:512000']];

        $validated = $request->validate(array_merge($audioRules, [
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'language_code' => ['nullable'],
            'language_code.*' => ['nullable', 'string', 'max:20'],
            'clip_index' => ['nullable'],
            'clip_index.*' => ['nullable', 'integer', 'min:0'],
            'clip_start_ms' => ['nullable'],
            'clip_start_ms.*' => ['nullable', 'integer', 'min:0'],
            'clip_end_ms' => ['nullable'],
            'clip_end_ms.*' => ['nullable', 'integer', 'min:0'],
            'server_chunk' => ['nullable', 'boolean'],
        ]));

        $chunker = app(WebAudioChunkerService::class);
        $cleanup = null;

        try {
            $clips = $this->normalizeClips($request, $validated);

            if (($validated['server_chunk'] ?? false) && $request->file('audio') instanceof UploadedFile) {
                $prepared = $chunker->clipsFromUpload($request->file('audio'), (int) ($validated['duration_seconds'] ?? 0));
                $clips = $prepared['clips'];
                $cleanup = $prepared['cleanup'];
            }

            if (app(WebApiTranscriptionClient::class)->batchIsTooLarge($clips)) {
                return response()->json(['message' => 'Audio is too big.'], 422);
            }

            $durationSeconds = $this->durationSeconds($clips, (int) ($validated['duration_seconds'] ?? 0));

            if (! $entitlements->allows($request->user(), 'upload')) {
                return $this->upgradeRequired('Upload transcription is not included in your current plan.');
            }

            if (! $entitlements->canTranscribe($request->user(), $durationSeconds)) {
                return $this->upgradeRequired('You have reached this month\'s transcription quota.');
            }

            $transcript = $this->createQueuedTranscript($request, $project, 'upload', $clips, $durationSeconds);

            ProcessWebTranscriptJob::dispatch($transcript->id, [
                'language_code' => is_string($validated['language_code'] ?? null) ? $validated['language_code'] : null,
                'clips' => $this->storedClipPayloads($transcript),
            ]);

            return response()->json([
                'message' => 'Upload queued for transcription.',
                'transcript' => $payloads->present($transcript->fresh()),
            ], 202);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Audio upload could not be processed.'], 422);
        } finally {
            $chunker->cleanup($cleanup);
        }
    }

    public function chunk(
        Request $request,
        TranscriptProject $project,
        EntitlementService $entitlements,
        TranscriptPayloadPresenter $payloads,
    ): JsonResponse {
        $this->authorizeProject($request, $project);

        $validated = $request->validate([
            'audio' => ['required', 'file', 'max:512000'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'language_code' => ['nullable', 'string', 'max:20'],
            'clip_index' => ['nullable', 'integer', 'min:0'],
            'clip_start_ms' => ['nullable', 'integer', 'min:0'],
            'clip_end_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        if (! $entitlements->allows($request->user(), 'live')) {
            return $this->upgradeRequired('Live transcription is not included in your current plan.');
        }

        if (! $entitlements->canTranscribe($request->user(), (int) ($validated['duration_seconds'] ?? 0))) {
            return $this->upgradeRequired('You have reached this month\'s transcription quota.');
        }

        $clips = $this->normalizeClips($request, $validated);
        $durationSeconds = $this->durationSeconds($clips, (int) ($validated['duration_seconds'] ?? 0));

        if (app(WebApiTranscriptionClient::class)->batchIsTooLarge($clips)) {
            return response()->json(['message' => 'Audio is too big.'], 422);
        }

        $transcript = $this->createQueuedTranscript($request, $project, 'live', $clips, $durationSeconds);

        ProcessWebTranscriptJob::dispatch($transcript->id, [
            'language_code' => $validated['language_code'] ?? null,
            'clips' => $this->storedClipPayloads($transcript),
        ]);

        return response()->json([
            'message' => 'Live clip queued for transcription.',
            'transcript' => $payloads->present($transcript->fresh()),
        ], 202);
    }

    public function status(
        Request $request,
        TranscriptProject $project,
        EntitlementService $entitlements,
        TranscriptPayloadPresenter $payloads,
    ): JsonResponse {
        $this->authorizeProject($request, $project);

        $project->load(['transcripts.sections' => fn ($query) => $query->orderBy('position')]);

        return response()->json([
            'project' => [
                'id' => $project->id,
                'title' => $project->title,
                'transcripts' => $project->transcripts
                    ->sortByDesc('created_at')
                    ->values()
                    ->map(fn (Transcript $transcript): array => $payloads->present($transcript))
                    ->all(),
            ],
            'entitlements' => $entitlements->summaryFor($request->user()),
        ]);
    }

    public function cancel(
        Request $request,
        TranscriptProject $project,
        Transcript $transcript,
        TranscriptPayloadPresenter $payloads,
    ): JsonResponse {
        $this->authorizeProject($request, $project);
        abort_unless($transcript->project_id === $project->id, 404);

        app(WebTranscriptProcessor::class)->appendLog($transcript, 'cancelled', 'Cancelled');

        return response()->json([
            'message' => 'Cancelled',
            'transcript' => $payloads->present($transcript->fresh()),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $clips
     */
    private function createQueuedTranscript(Request $request, TranscriptProject $project, string $source, array $clips, int $durationSeconds): Transcript
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

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int, array<string, mixed>>
     */
    private function normalizeClips(Request $request, array $validated): array
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
     * @param  array<string, mixed>  $validated
     */
    private function indexedValue(array $validated, string $key, int $index): mixed
    {
        $value = $validated[$key] ?? null;

        return is_array($value) ? ($value[$index] ?? null) : ($index === 0 ? $value : null);
    }

    /**
     * @param  array<int, array<string, mixed>>  $clips
     */
    private function durationSeconds(array $clips, int $fallback): int
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
     * @return array<int, array<string, mixed>>
     */
    private function storedClipPayloads(Transcript $transcript): array
    {
        $log = $transcript->processing_log ?? [];
        $first = $log[0]['context']['clips'] ?? [];

        return is_array($first) ? array_values(array_filter($first, 'is_array')) : [];
    }

    private function authorizeProject(Request $request, TranscriptProject $project): void
    {
        $this->authorize('view', $project);
    }

    private function upgradeRequired(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'upgrade' => true,
        ], 402);
    }
}
