<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Transcript;
use App\Models\TranscriptProject;
use App\Services\EntitlementService;
use App\Services\Web\TranscriptionWorkflowService;
use App\Services\Web\TranscriptPayloadPresenter;
use App\Services\WebApiTranscriptionClient;
use App\Services\WebTranscriptProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

            if (! $entitlements->canTranscribe($request->user(), $durationSeconds)) {
                return $this->upgradeRequired('You have used today\'s free transcription minutes. Buy more minutes to continue today.');
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
        } catch (RuntimeException) {
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

        if (! $entitlements->canTranscribe($request->user(), (int) ($validated['duration_seconds'] ?? 0))) {
            return $this->upgradeRequired('You have used today\'s free transcription minutes. Buy more minutes to continue today.');
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

    public function status(
        Request $request,
        TranscriptProject $project,
        EntitlementService $entitlements,
        TranscriptPayloadPresenter $payloads,
        TranscriptionWorkflowService $workflow,
    ): JsonResponse {
        $this->authorizeProject($request, $project);
        $workflow->syncApiTranscriptionJobs($request, $project);

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
        $this->authorizeTranscript($project, $transcript);

        app(WebTranscriptProcessor::class)->appendLog($transcript, 'cancelled', 'Cancelled');

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

    private function upgradeRequired(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'upgrade' => true,
        ], 402);
    }
}
