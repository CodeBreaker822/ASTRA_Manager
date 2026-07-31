<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Transcript;
use App\Models\TranscriptProject;
use App\Models\User;
use App\Services\ChunkedUploadService;
use App\Services\EntitlementService;
use App\Services\PayMongoWalletTopupReconciler;
use App\Services\Web\TranscriptionWorkflowService;
use App\Services\Web\TranscriptPayloadPresenter;
use App\Services\WebApiTranscriptionClient;
use App\Services\WebTranscriptProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class TranscriptionController extends Controller
{
    public function upload(
        Request $request,
        TranscriptProject $project,
        EntitlementService $entitlements,
        TranscriptPayloadPresenter $payloads,
        TranscriptionWorkflowService $workflow,
    ): JsonResponse {
        $this->authorizeProject($request, $project);

        $audioRules = is_array($request->file('audio'))
            ? [
                'audio' => ['required', 'array', 'min:1', 'max:'.WebApiTranscriptionClient::MAX_BATCH_CLIPS],
                'audio.*' => ['required', 'file', 'max:512000'],
            ]
            : ['audio' => ['required', 'file', 'max:512000']];

        try {
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
        } catch (ValidationException $exception) {
            Log::error('Web audio upload source validation failed.', [
                ...$this->uploadSourceLogContext($request, $project),
                'errors' => $exception->errors(),
            ]);

            return response()->json(['message' => 'Audio upload could not be processed.'], 422);
        }

        $cleanup = null;

        try {
            $prepared = $workflow->prepareUploadClips($request, $validated);
            $clips = $prepared['clips'];
            $cleanup = $prepared['cleanup'];

            if ($workflow->batchIsTooLarge($clips)) {
                return response()->json(['message' => 'Audio is too big.'], 422);
            }

            $durationSeconds = $workflow->durationSeconds($clips, (int) ($validated['duration_seconds'] ?? 0));

            if (! $entitlements->allows($request->user(), 'upload')) {
                return $this->upgradeRequired('Upload transcription is not available for this account.');
            }

            if (! $entitlements->canAfford($request->user(), 'upload', $durationSeconds)) {
                return $this->upgradeRequired('Insufficient balance for transcription. Please add funds to continue.');
            }

            $transcript = $workflow->queueTranscript($request, $project, 'upload', $clips, $durationSeconds);
            $workflow->startApiTranscription(
                $request,
                $transcript,
                is_string($validated['language_code'] ?? null) ? $validated['language_code'] : null,
            );

            return response()->json([
                'message' => 'Upload queued for transcription.',
                'transcript' => $payloads->present($transcript->fresh()),
            ], 202);
        } catch (RuntimeException $exception) {
            $previous = $exception->getPrevious();

            Log::error('Web audio upload processing failed.', [
                ...$this->uploadSourceLogContext($request, $project),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'previous_exception' => $previous ? $previous::class : null,
                'previous_message' => $previous?->getMessage(),
            ]);

            return response()->json(['message' => 'Audio upload could not be processed.'], 422);
        } finally {
            $workflow->cleanupPreparedUpload($cleanup);
        }
    }

    public function chunk(
        Request $request,
        TranscriptProject $project,
        EntitlementService $entitlements,
        TranscriptPayloadPresenter $payloads,
        TranscriptionWorkflowService $workflow,
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
            return $this->upgradeRequired('Live transcription is not available for this account.');
        }

        if (! $entitlements->canAfford($request->user(), 'live', (int) ($validated['duration_seconds'] ?? 0))) {
            return $this->upgradeRequired('Insufficient balance for live transcription. Please add funds to continue.');
        }

        $clips = $workflow->normalizeClips($request, $validated);
        $durationSeconds = $workflow->durationSeconds($clips, (int) ($validated['duration_seconds'] ?? 0));

        if ($workflow->batchIsTooLarge($clips)) {
            return response()->json(['message' => 'Audio is too big.'], 422);
        }

        $transcript = $workflow->queueTranscript($request, $project, 'live', $clips, $durationSeconds);
        $workflow->startApiTranscription($request, $transcript, $validated['language_code'] ?? null);

        return response()->json([
            'message' => 'Live clip queued for transcription.',
            'transcript' => $payloads->present($transcript->fresh()),
        ], 202);
    }

    public function uploadChunk(
        Request $request,
        TranscriptProject $project,
        ChunkedUploadService $chunks,
    ): JsonResponse {
        $this->authorizeProject($request, $project);

        $validated = $request->validate([
            'upload_id' => ['required', 'string', 'min:8', 'max:80'],
            'chunk' => ['required', 'file', 'max:51200'],
            'chunk_index' => ['required', 'integer', 'min:0'],
            'total_chunks' => ['required', 'integer', 'min:1', 'max:200'],
            'total_size' => ['required', 'integer', 'min:1', 'max:'.ChunkedUploadService::MAX_TOTAL_BYTES],
            'filename' => ['required', 'string', 'max:180'],
            'mime_type' => ['nullable', 'string', 'max:120'],
            'chunk_hash' => ['nullable', 'string', 'size:64'],
        ]);

        try {
            $payload = $chunks->storeChunk(
                'workspace-audio',
                $this->chunkOwnerKey($request, $project),
                (string) $validated['upload_id'],
                $request->file('chunk'),
                (int) $validated['chunk_index'],
                (int) $validated['total_chunks'],
                (int) $validated['total_size'],
                (string) $validated['filename'],
                isset($validated['mime_type']) ? (string) $validated['mime_type'] : null,
                isset($validated['chunk_hash']) ? (string) $validated['chunk_hash'] : null,
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($payload);
    }

    public function completeUpload(
        Request $request,
        TranscriptProject $project,
        ChunkedUploadService $chunks,
        EntitlementService $entitlements,
        TranscriptPayloadPresenter $payloads,
        TranscriptionWorkflowService $workflow,
    ): JsonResponse {
        $this->authorizeProject($request, $project);

        $validated = $request->validate([
            'upload_id' => ['required', 'string', 'min:8', 'max:80'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'language_code' => ['nullable', 'string', 'max:20'],
        ]);

        $uploadId = (string) $validated['upload_id'];
        $cleanup = null;

        try {
            $assembled = $chunks->assemble('workspace-audio', $this->chunkOwnerKey($request, $project), $uploadId);
            $file = new UploadedFile(
                $assembled['path'],
                $assembled['filename'],
                $assembled['mime_type'],
                null,
                true,
            );
            $prepared = $workflow->prepareUploadFile($file, [
                ...$validated,
                'server_chunk' => true,
            ]);
            $clips = $prepared['clips'];
            $cleanup = $prepared['cleanup'];

            if ($workflow->batchIsTooLarge($clips)) {
                return response()->json(['message' => 'Audio is too big.'], 422);
            }

            $durationSeconds = $workflow->durationSeconds($clips, (int) ($validated['duration_seconds'] ?? 0));

            if (! $entitlements->allows($request->user(), 'upload')) {
                return $this->upgradeRequired('Upload transcription is not available for this account.');
            }

            if (! $entitlements->canAfford($request->user(), 'upload', $durationSeconds)) {
                return $this->upgradeRequired('Insufficient balance for transcription. Please add funds to continue.');
            }

            $transcript = $workflow->queueTranscript($request, $project, 'upload', $clips, $durationSeconds);
            $workflow->startApiTranscription(
                $request,
                $transcript,
                is_string($validated['language_code'] ?? null) ? $validated['language_code'] : null,
            );

            return response()->json([
                'message' => 'Upload queued for transcription.',
                'transcript' => $payloads->present($transcript->fresh()),
            ], 202);
        } catch (RuntimeException $exception) {
            Log::error('Chunked web audio upload processing failed.', [
                'user_id' => $request->user()?->id,
                'project_id' => $project->id,
                'upload_id' => $uploadId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json(['message' => $exception->getMessage()], 422);
        } finally {
            $workflow->cleanupPreparedUpload($cleanup);
            $chunks->cleanup('workspace-audio', $this->chunkOwnerKey($request, $project), $uploadId);
        }
    }

    public function status(
        Request $request,
        TranscriptProject $project,
        EntitlementService $entitlements,
        PayMongoWalletTopupReconciler $reconciler,
        TranscriptPayloadPresenter $payloads,
        TranscriptionWorkflowService $workflow,
    ): JsonResponse {
        $this->authorizeProject($request, $project);
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $reconciler->reconcileFor($user);
        $workflow->syncApiTranscriptionJobs($request, $project);
        $user->refresh();

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
            'entitlements' => $entitlements->summaryFor($user),
        ]);
    }

    public function cancel(
        Request $request,
        TranscriptProject $project,
        Transcript $transcript,
        TranscriptPayloadPresenter $payloads,
    ): JsonResponse {
        $this->authorizeProject($request, $project);
        $this->authorizeTranscript($project, $transcript);

        $processor = app(WebTranscriptProcessor::class);
        $processor->appendLog($transcript, 'cancelled', 'Cancelled');
        $processor->cleanupTranscriptAudio($transcript->fresh() ?? $transcript);

        return response()->json([
            'message' => 'Cancelled',
            'transcript' => $payloads->present($transcript->fresh()),
        ]);
    }

    private function authorizeProject(Request $request, TranscriptProject $project): void
    {
        $this->authorize('view', $project);
    }

    private function authorizeTranscript(TranscriptProject $project, Transcript $transcript): void
    {
        abort_unless($transcript->project_id === $project->id, 404);
        $this->authorize('update', $transcript);
    }

    private function chunkOwnerKey(Request $request, TranscriptProject $project): string
    {
        return 'user-'.$request->user()?->id.'-project-'.$project->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadSourceLogContext(Request $request, TranscriptProject $project): array
    {
        return [
            'user_id' => $request->user()?->id,
            'project_id' => $project->id,
            'content_length' => $request->server('CONTENT_LENGTH'),
            'content_type' => $request->headers->get('content-type'),
            'post_max_size' => ini_get('post_max_size'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'max_file_uploads' => ini_get('max_file_uploads'),
            'file_keys' => array_keys($request->allFiles()),
            'audio_files' => $this->uploadedAudioLogContext($request->file('audio')),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function uploadedAudioLogContext(mixed $audio): array
    {
        if ($audio instanceof UploadedFile) {
            return [$this->uploadedFileLogContext($audio)];
        }

        if (! is_array($audio)) {
            return [];
        }

        return array_values(array_map(
            fn (UploadedFile $file): array => $this->uploadedFileLogContext($file),
            array_filter($audio, fn (mixed $file): bool => $file instanceof UploadedFile),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadedFileLogContext(UploadedFile $file): array
    {
        return [
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'is_valid' => $file->isValid(),
            'upload_error' => $file->getError(),
            'upload_error_message' => $file->getErrorMessage(),
            'real_path' => $file->getRealPath(),
        ];
    }
}
