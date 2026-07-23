<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWebPolishJob;
use App\Jobs\ProcessWebSummarizeJob;
use App\Models\Transcript;
use App\Models\TranscriptProject;
use App\Services\EntitlementService;
use App\Services\TranscriptExportService;
use App\Services\Web\TranscriptPayloadPresenter;
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
        TranscriptPayloadPresenter $payloads,
    ): JsonResponse {
        $this->authorizeTranscript($request, $project, $transcript);

        if (! $entitlements->allows($request->user(), 'polish')) {
            return $this->upgradeRequired('Transcript polishing is not available for this account.');
        }

        if (! $this->hasRawTranscript($transcript)) {
            return response()->json(['message' => 'No raw transcript is ready to polish yet.'], 422);
        }

        $validated = $request->validate([
            'instruction' => ['nullable', 'string', 'max:4000'],
            'preset' => ['nullable', 'string', 'in:english,filipino,grammar,translate_fix,custom'],
        ]);

        $instruction = $this->polishInstruction(
            (string) ($validated['preset'] ?? 'grammar'),
            (string) ($validated['instruction'] ?? ''),
        );

        if (trim($instruction) === '' || mb_strlen(trim($instruction)) < 3) {
            return response()->json(['message' => 'Enter instructions before polishing.'], 422);
        }

        if (! $entitlements->canPolish($request->user(), mb_strlen($this->sourceText($transcript)))) {
            return $this->upgradeRequired('Daily free polishing is used up. Add polish character credits to continue.');
        }

        $transcript->forceFill([
            'polish_status' => 'processing',
            'polish_error_message' => null,
        ])->save();
        $processor->appendLog($transcript, 'polishing', 'Processing');
        ProcessWebPolishJob::dispatchAfterResponse($transcript->id, $instruction);

        return response()->json([
            'message' => 'Polishing',
            'transcript' => $payloads->present($transcript->fresh()),
        ], 202);
    }

    public function summarize(
        Request $request,
        TranscriptProject $project,
        Transcript $transcript,
        EntitlementService $entitlements,
        WebTranscriptProcessor $processor,
        TranscriptPayloadPresenter $payloads,
    ): JsonResponse {
        $this->authorizeTranscript($request, $project, $transcript);

        if (! $entitlements->allows($request->user(), 'summarize')) {
            return $this->upgradeRequired('Transcript summaries are not available for this account.');
        }

        if (! $this->hasRawTranscript($transcript)) {
            return response()->json(['message' => 'The transcript could not be summarized.'], 422);
        }

        $validated = $request->validate([
            'source' => ['nullable', 'string', 'in:raw,cleaned'],
        ]);
        $source = (string) ($validated['source'] ?? 'raw');

        if (! $entitlements->canSummarize($request->user(), mb_strlen($this->summarySourceText($transcript, $source)))) {
            return $this->upgradeRequired('Daily free summarizing is used up. Add summarize character credits to continue.');
        }

        $transcript->forceFill([
            'summary_status' => 'processing',
            'summary_error_message' => null,
        ])->save();
        $processor->appendLog($transcript, 'summarizing', 'Processing');
        ProcessWebSummarizeJob::dispatchAfterResponse($transcript->id, (string) ($validated['source'] ?? 'raw'));

        return response()->json([
            'message' => 'Summarizing...',
            'transcript' => $payloads->present($transcript->fresh()),
        ], 202);
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
            return $this->upgradeRequired('This export format is not available for this account.');
        }

        $file = $exports->export($transcript, $format, (string) ($validated['source'] ?? 'raw'));

        app(WebTranscriptProcessor::class)->appendLog($transcript, 'exported', strtoupper($format).' export generated.');

        return response()->download($file['path'], $file['name'], [
            'Content-Type' => $file['mime'],
        ])->deleteFileAfterSend();
    }

    private function authorizeTranscript(Request $request, TranscriptProject $project, Transcript $transcript): void
    {
        $this->authorize('view', $project);
        abort_unless($transcript->project_id === $project->id, 404);
        $this->authorize('view', $transcript);
    }

    private function upgradeRequired(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'upgrade' => true,
        ], 402);
    }

    private function hasRawTranscript(Transcript $transcript): bool
    {
        return filled($transcript->raw_text)
            || $transcript->sections()->whereNotNull('text')->exists();
    }

    private function sourceText(Transcript $transcript): string
    {
        return trim((string) ($transcript->raw_text ?: $transcript->sections()->orderBy('position')->pluck('text')->implode("\n\n")));
    }

    private function summarySourceText(Transcript $transcript, string $source): string
    {
        return $source === 'cleaned'
            ? trim((string) ($transcript->cleaned_text ?? $transcript->raw_text))
            : trim((string) $transcript->raw_text);
    }

    private function polishInstruction(string $preset, string $custom): string
    {
        return match ($preset) {
            'english' => 'Translate every non-English part of the transcript into clear English. Treat Cebuano, Bisaya, Filipino, Tagalog, and mixed code-switching as source language. Do not leave source-language words untranslated unless they are names, offices, agencies, titles, acronyms, places, or proper nouns. Preserve meaning, speaker intent, numbers, and time order.',
            'filipino' => 'Translate every non-Filipino part of the transcript into clear Filipino. Treat English, Cebuano, Bisaya, and mixed code-switching as source language. Do not leave source-language words untranslated unless they are names, offices, agencies, titles, acronyms, places, or proper nouns. Preserve meaning, speaker intent, numbers, and time order.',
            'translate_fix' => 'Translate every non-English sentence, phrase, or word into polished English, then fix grammar, spelling, punctuation, capitalization, and obvious speech-to-text mistakes. Preserve meaning, speaker intent, names, titles, numbers, and time order.',
            'custom' => trim($custom),
            default => 'Fix grammar, spelling, punctuation, capitalization, and obvious speech-to-text mistakes without translating the transcript. Preserve the original language choices, meaning, names, titles, numbers, and time order.',
        };
    }
}
