<?php

namespace App\Http\Controllers\Api\TranscriptionController;

use App\Models\API;
use App\Services\Transcription\ProviderFallbackLogger;
use App\Services\Transcription\ServiceUserMessage;
use Illuminate\Http\Request;
use Throwable;

trait SummarizesTranscripts
{
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
        $sectionInstruction = $this->summaryInstruction($instruction, $this->summarySectionInstructions());
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
                    $this->summaryInstruction($instruction, $this->summaryCondenseInstructions()),
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

        $final = $this->cleanTextAcrossProviders(
            $providers,
            0,
            $combined,
            $this->summaryInstruction($instruction, $this->summaryFinalInstructions()),
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

                app(ProviderFallbackLogger::class)->success(
                    'text_fixer',
                    'summarize',
                    $provider,
                    $offset,
                    $request,
                    $license,
                );

                return [
                    'result' => $result,
                    'provider' => $provider,
                    'fallback_used' => $offset > 0,
                ];
            } catch (Throwable $exception) {
                $lastException = $exception;
                app(ProviderFallbackLogger::class)->failure(
                    'text_fixer',
                    'summarize',
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

    private function summarizeLargeTextWithCleaner(
        object $cleaner,
        array $provider,
        string $text,
        ?string $instruction,
    ): array {
        $sectionSummaries = [];
        $sectionInstruction = $this->summaryInstruction($instruction, $this->summarySectionInstructions());

        foreach ($this->splitTranscript($text) as $part) {
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
                        'instructions' => $this->summaryInstruction($instruction, $this->summaryCondenseInstructions()),
                    ]),
                    $provider,
                    false,
                )['text'];
            }

            $combined = implode("\n\n", $condensed);
            $condenseRounds++;
        }

        return $this->retryCleanerResponse(
            fn (): array => $cleaner->clean($combined, [], [
                'instructions' => $this->summaryInstruction($instruction, $this->summaryFinalInstructions()),
            ]),
            $provider,
            false,
        );
    }

    private function isSummaryTask(?string $task, ?string $instruction): bool
    {
        if ($task === 'summarize') {
            return true;
        }

        return preg_match('/\b(summary|summarize|summarise|summarized|summarised|summarization|summarisation)\b/i', (string) $instruction) === 1;
    }

    private function summaryInstruction(?string $baseInstruction, string $additionalInstruction): string
    {
        return trim(implode(' ', array_filter([
            is_string($baseInstruction) ? trim($baseInstruction) : '',
            $additionalInstruction,
        ])));
    }

    private function summarySectionInstructions(): string
    {
        return 'Summarize only this transcript section. Preserve important names, facts, numbers, decisions, and action items for the final summary. Keep topic labels clear so the final summary can be organized with Markdown headings.';
    }

    private function summaryCondenseInstructions(): string
    {
        return 'Condense these section summaries while preserving all important facts, decisions, action items, topic labels, and Markdown-style structure.';
    }

    private function summaryFinalInstructions(): string
    {
        return 'Create one complete final summary from these section summaries. Do not mention the sectioning process. Organize the final result by topic. Use Markdown headings with ## or ###, readable paragraphs, and Markdown bullets using - where lists are appropriate.';
    }
}
