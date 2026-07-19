<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWebTranscriptJob;
use App\Models\Transcript;
use App\Models\TranscriptProject;
use App\Services\EntitlementService;
use App\Services\WebTranscriptProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TranscriptionController extends Controller
{
    public function upload(Request $request, TranscriptProject $project, EntitlementService $entitlements): JsonResponse
    {
        $this->authorizeProject($request, $project);

        $validated = $request->validate([
            'audio' => ['required', 'file', 'max:512000'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'language_code' => ['nullable', 'string', 'max:20'],
        ]);

        if (! $entitlements->allows($request->user(), 'upload')) {
            return $this->upgradeRequired('Upload transcription is not included in your current plan.');
        }

        if (! $entitlements->canTranscribe($request->user(), (int) ($validated['duration_seconds'] ?? 0))) {
            return $this->upgradeRequired('You have reached this month\'s transcription quota.');
        }

        $transcript = $this->createQueuedTranscript($request, $project, 'upload');

        ProcessWebTranscriptJob::dispatch($transcript->id, [
            'language_code' => $validated['language_code'] ?? null,
        ]);

        return response()->json([
            'message' => 'Upload queued for transcription.',
            'transcript' => $this->transcriptPayload($transcript->fresh()),
        ], 202);
    }

    public function chunk(Request $request, TranscriptProject $project, EntitlementService $entitlements): JsonResponse
    {
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

        $transcript = $this->createQueuedTranscript($request, $project, 'live');

        ProcessWebTranscriptJob::dispatch($transcript->id, [
            'language_code' => $validated['language_code'] ?? null,
            'clip_index' => $validated['clip_index'] ?? null,
            'clip_start_ms' => $validated['clip_start_ms'] ?? null,
            'clip_end_ms' => $validated['clip_end_ms'] ?? null,
        ]);

        return response()->json([
            'message' => 'Live clip queued for transcription.',
            'transcript' => $this->transcriptPayload($transcript->fresh()),
        ], 202);
    }

    public function status(Request $request, TranscriptProject $project, EntitlementService $entitlements): JsonResponse
    {
        $this->authorizeProject($request, $project);

        $project->load(['transcripts.sections' => fn ($query) => $query->orderBy('position')]);

        return response()->json([
            'project' => [
                'id' => $project->id,
                'title' => $project->title,
                'transcripts' => $project->transcripts
                    ->sortByDesc('created_at')
                    ->values()
                    ->map(fn (Transcript $transcript): array => $this->transcriptPayload($transcript))
                    ->all(),
            ],
            'entitlements' => $entitlements->summaryFor($request->user()),
        ]);
    }

    private function createQueuedTranscript(Request $request, TranscriptProject $project, string $source): Transcript
    {
        $audio = $request->file('audio');
        $extension = $audio?->getClientOriginalExtension() ?: 'webm';
        $filename = (string) Str::uuid().'.'.$extension;
        $path = $audio->storeAs(
            'web-transcripts/'.$request->user()->id.'/'.$project->id,
            $filename,
            'local',
        );

        $transcript = $project->transcripts()->create([
            'source' => $source,
            'status' => 'queued',
            'duration_seconds' => (int) $request->integer('duration_seconds', 0),
            'audio_path' => $path,
            'processing_log' => [],
        ]);

        app(WebTranscriptProcessor::class)->appendLog($transcript, 'queued', ucfirst($source).' audio queued.');

        return $transcript;
    }

    private function authorizeProject(Request $request, TranscriptProject $project): void
    {
        abort_unless($project->user_id === $request->user()?->id, 404);
    }

    private function upgradeRequired(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'upgrade' => true,
        ], 402);
    }

    /**
     * @return array<string, mixed>
     */
    private function transcriptPayload(?Transcript $transcript): array
    {
        if (! $transcript) {
            return [];
        }

        $transcript->loadMissing(['sections' => fn ($query) => $query->orderBy('position')]);

        return [
            'id' => $transcript->id,
            'source' => $transcript->source,
            'status' => $transcript->status,
            'duration_seconds' => $transcript->duration_seconds,
            'raw_text' => $transcript->raw_text,
            'cleaned_text' => $transcript->cleaned_text,
            'summary_text' => $transcript->summary_text,
            'processing_log' => $transcript->processing_log ?? [],
            'sections' => $transcript->sections
                ->map(fn ($section): array => [
                    'id' => $section->id,
                    'position' => $section->position,
                    'text' => $section->text,
                    'cleaned_text' => $section->cleaned_text,
                    'started_at_ms' => $section->started_at_ms,
                    'ended_at_ms' => $section->ended_at_ms,
                ])
                ->all(),
        ];
    }
}
