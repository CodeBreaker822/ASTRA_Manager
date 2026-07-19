<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Transcript;
use App\Models\TranscriptProject;
use App\Services\EntitlementService;
use App\Services\TranscriptExportService;
use App\Services\WebTranscriptProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TranscriptActionController extends Controller
{
    public function polish(
        Request $request,
        TranscriptProject $project,
        Transcript $transcript,
        EntitlementService $entitlements,
        WebTranscriptProcessor $processor,
    ): JsonResponse {
        $this->authorizeTranscript($request, $project, $transcript);

        if (! $entitlements->allows($request->user(), 'polish')) {
            return $this->upgradeRequired('Transcript polishing is not included in your current plan.');
        }

        $validated = $request->validate([
            'instruction' => ['nullable', 'string', 'max:4000'],
            'preset' => ['nullable', 'string', 'in:english,filipino,grammar,translate_fix,custom'],
        ]);

        $instruction = $this->polishInstruction(
            (string) ($validated['preset'] ?? 'grammar'),
            (string) ($validated['instruction'] ?? ''),
        );

        return response()->json([
            'message' => 'Transcript polished.',
            'text' => $processor->polish($transcript, $instruction),
        ]);
    }

    public function summarize(
        Request $request,
        TranscriptProject $project,
        Transcript $transcript,
        EntitlementService $entitlements,
        WebTranscriptProcessor $processor,
    ): JsonResponse {
        $this->authorizeTranscript($request, $project, $transcript);

        if (! $entitlements->allows($request->user(), 'summarize')) {
            return $this->upgradeRequired('Transcript summaries are not included in your current plan.');
        }

        $validated = $request->validate([
            'source' => ['nullable', 'string', 'in:raw,cleaned'],
        ]);

        return response()->json([
            'message' => 'Transcript summarized.',
            'text' => $processor->summarize($transcript, (string) ($validated['source'] ?? 'raw')),
        ]);
    }

    public function export(
        Request $request,
        TranscriptProject $project,
        Transcript $transcript,
        EntitlementService $entitlements,
        TranscriptExportService $exports,
    ): BinaryFileResponse|JsonResponse {
        $this->authorizeTranscript($request, $project, $transcript);

        $validated = $request->validate([
            'format' => ['required', 'string', 'in:txt,docx,xlsx'],
            'source' => ['nullable', 'string', 'in:raw,cleaned,summary'],
        ]);
        $format = (string) $validated['format'];

        if (! $entitlements->allowsExport($request->user(), $format)) {
            return $this->upgradeRequired('This export format is not included in your current plan.');
        }

        $file = $exports->export($transcript, $format, (string) ($validated['source'] ?? 'raw'));

        app(WebTranscriptProcessor::class)->appendLog($transcript, 'exported', strtoupper($format).' export generated.');

        return response()->download($file['path'], $file['name'], [
            'Content-Type' => $file['mime'],
        ])->deleteFileAfterSend();
    }

    private function authorizeTranscript(Request $request, TranscriptProject $project, Transcript $transcript): void
    {
        abort_unless(
            $project->user_id === $request->user()?->id && $transcript->project_id === $project->id,
            404,
        );
    }

    private function upgradeRequired(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'upgrade' => true,
        ], 402);
    }

    private function polishInstruction(string $preset, string $custom): string
    {
        return match ($preset) {
            'english' => 'Translate this transcript to English. Preserve names, timestamps, meaning, and speaker intent.',
            'filipino' => 'Translate this transcript to Filipino. Preserve names, timestamps, meaning, and speaker intent.',
            'translate_fix' => 'Translate this transcript to English and fix grammar while preserving meaning and names.',
            'custom' => trim($custom) !== '' ? trim($custom) : 'Polish this transcript while preserving meaning and names.',
            default => 'Fix grammar, punctuation, and readability while preserving meaning and names.',
        };
    }
}
