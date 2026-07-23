<?php

namespace App\Services\Web;

use App\Models\Transcript;

class TranscriptPayloadPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(?Transcript $transcript): array
    {
        if (! $transcript instanceof Transcript) {
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
            'polish_status' => $transcript->polish_status,
            'polish_error_message' => $transcript->polish_error_message ? 'Transcript could not be polished.' : null,
            'summary_status' => $transcript->summary_status,
            'summary_error_message' => $transcript->summary_error_message ? 'The transcript could not be summarized.' : null,
            'processing_log' => $this->safeProcessingLog($transcript->processing_log ?? []),
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

    /**
     * @param  array<int, array<string, mixed>>  $log
     * @return array<int, array<string, mixed>>
     */
    private function safeProcessingLog(array $log): array
    {
        return array_map(function (array $entry): array {
            $status = (string) ($entry['status'] ?? '');

            return [
                'status' => $status,
                'message' => $this->safeProcessingMessage($status),
                'created_at' => $entry['created_at'] ?? null,
            ];
        }, array_values($log));
    }

    private function safeProcessingMessage(string $status): string
    {
        return match ($status) {
            'queued' => 'Queued',
            'processing' => 'Processing',
            'completed' => 'Complete',
            'cancelled' => 'Cancelled',
            'polishing' => 'Processing',
            'polished' => 'Transcript polished.',
            'polish_failed' => 'Transcript could not be polished.',
            'summarizing' => 'Processing',
            'summarized' => 'Transcript summarized.',
            'summary_failed' => 'The transcript could not be summarized.',
            'exported' => 'Export generated.',
            'failed' => 'Audio upload could not be processed.',
            default => 'Processing',
        };
    }
}
