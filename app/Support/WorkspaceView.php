<?php

namespace App\Support;

/**
 * View-state the workspace used to derive in the browser: which capture mode a
 * project is in, the heading, and the empty-state copy. Resolved server-side so
 * blade can render it directly.
 */
class WorkspaceView
{
    /**
     * The project payload is a presenter array, so narrow the transcript list
     * to something typed before working with it.
     *
     * @param  array<string, mixed>|null  $project
     * @return array<int, array<string, mixed>>
     */
    private static function transcripts(?array $project): array
    {
        $transcripts = $project['transcripts'] ?? null;

        if (! is_array($transcripts)) {
            return [];
        }

        return array_values(array_filter($transcripts, is_array(...)));
    }

    /**
     * @param  array<string, mixed>|null  $project
     */
    public static function mode(?array $project): string
    {
        $sources = array_column(self::transcripts($project), 'source');

        if (in_array('live', $sources, true)) {
            return 'live';
        }

        return in_array('upload', $sources, true) ? 'upload' : 'choose';
    }

    /**
     * @param  array<string, mixed>|null  $project
     */
    public static function title(?array $project, string $mode): string
    {
        if (! $project) {
            return 'Welcome';
        }

        $title = (string) ($project['title'] ?? '');

        return match ($mode) {
            'upload' => $title.' - Upload transcript',
            'live' => $title.' - Live transcript',
            default => $title,
        };
    }

    /**
     * @param  array<string, mixed>|null  $project
     * @return array{eyebrow: string, title: string, copy: string}
     */
    public static function emptyPanel(?array $project, string $mode): array
    {
        if (! $project) {
            return [
                'eyebrow' => 'Transcription workspace',
                'title' => 'Hi, what are we transcribing today?',
                'copy' => "Start a transcript from the left, then choose Live or Upload Audio. I'll keep the transcript here so you can polish, summarize, export, or review the processing log when it's ready.",
            ];
        }

        return match ($mode) {
            'live' => [
                'eyebrow' => 'Live transcript',
                'title' => 'Ready when you are.',
                'copy' => 'Press Live below to start capturing audio. Your transcript will appear here as each section finishes.',
            ],
            'upload' => [
                'eyebrow' => 'Upload transcript',
                'title' => "Drop in an audio file and I'll organize the transcript.",
                'copy' => 'Choose Upload Audio below, browse for a file, and the finished transcript will appear here.',
            ],
            default => [
                'eyebrow' => 'Transcription workspace',
                'title' => 'Great. How do you want to add audio?',
                'copy' => "Choose Live if you're recording now, or Upload Audio if the file is already on your computer.",
            ],
        };
    }

    /**
     * The transcript the action bar operates on.
     *
     * @param  array<string, mixed>|null  $project
     * @return array<string, mixed>|null
     */
    public static function primaryTranscript(?array $project, string $mode): ?array
    {
        $transcripts = self::transcripts($project);

        $first = function (callable $matches) use ($transcripts): ?array {
            foreach ($transcripts as $transcript) {
                if ($matches($transcript)) {
                    return $transcript;
                }
            }

            return null;
        };

        return $first(fn (array $t): bool => ($t['source'] ?? null) === $mode && ($t['status'] ?? null) === 'completed')
            ?? $first(fn (array $t): bool => ($t['source'] ?? null) === $mode)
            ?? $first(fn (array $t): bool => ($t['status'] ?? null) === 'completed')
            ?? ($transcripts[0] ?? null);
    }

    /**
     * @param  array<string, mixed>|null  $project
     */
    public static function hasPendingWork(?array $project): bool
    {
        foreach (self::transcripts($project) as $transcript) {
            $pending = in_array($transcript['status'] ?? null, ['queued', 'processing'], true)
                || ($transcript['polish_status'] ?? null) === 'processing'
                || ($transcript['summary_status'] ?? null) === 'processing';

            if ($pending) {
                return true;
            }
        }

        return false;
    }

    /**
     * Everything the transcript pane renders. Built here because the pane is
     * returned both with the full page and on its own from the status poll.
     *
     * @param  array<string, mixed>|null  $project
     * @return array{rows: array<int, array{range: string, text: string}>, hasFailed: bool, emptyPanel: array{eyebrow: string, title: string, copy: string}}
     */
    public static function transcriptPane(?array $project, string $mode): array
    {
        return [
            'rows' => self::transcriptRows($project),
            'hasFailed' => self::hasFailed($project),
            'emptyPanel' => self::emptyPanel($project, $mode),
        ];
    }

    /**
     * Flattens transcripts into display rows: a polished transcript becomes one
     * row spanning the whole recording, otherwise one row per section, otherwise
     * a single row of whatever text exists.
     *
     * @param  array<string, mixed>|null  $project
     * @return array<int, array{range: string, text: string}>
     */
    public static function transcriptRows(?array $project): array
    {
        $rows = [];

        foreach (self::transcripts($project) as $transcript) {
            $sections = self::sections($transcript);

            $hasContent = filled($transcript['raw_text'] ?? null)
                || filled($transcript['cleaned_text'] ?? null)
                || filled($transcript['summary_text'] ?? null)
                || $sections !== [];

            if (! $hasContent) {
                continue;
            }

            $cleaned = trim((string) ($transcript['cleaned_text'] ?? ''));

            if ($cleaned !== '') {
                $first = $sections[0] ?? null;
                $last = $sections === [] ? null : $sections[array_key_last($sections)];

                $rows[] = [
                    'range' => $first && $last
                        ? self::timecode($first['started_at_ms'] ?? null).'-'.self::timecode($last['ended_at_ms'] ?? null)
                        : '',
                    'text' => $cleaned,
                ];

                continue;
            }

            if ($sections !== []) {
                foreach ($sections as $section) {
                    $rows[] = [
                        'range' => self::sectionRange($section),
                        'text' => (string) ($section['cleaned_text'] ?? $section['text'] ?? ''),
                    ];
                }

                continue;
            }

            $rows[] = [
                'range' => '',
                'text' => (string) ($transcript['cleaned_text']
                    ?? $transcript['raw_text']
                    ?? $transcript['summary_text']
                    ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>|null  $project
     */
    public static function hasFailed(?array $project): bool
    {
        foreach (self::transcripts($project) as $transcript) {
            if (($transcript['status'] ?? null) === 'failed') {
                return true;
            }
        }

        return false;
    }

    /**
     * The action bar only appears once a transcript exists for the active mode.
     *
     * @param  array<string, mixed>|null  $project
     */
    public static function showActions(?array $project, string $mode): bool
    {
        if ($mode === 'choose') {
            return false;
        }

        foreach (self::transcripts($project) as $transcript) {
            if (($transcript['source'] ?? null) === $mode) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the transcript still has the original text to restore or export.
     *
     * @param  array<string, mixed>|null  $transcript
     */
    public static function hasRawText(?array $transcript): bool
    {
        if ($transcript === null) {
            return false;
        }

        return trim((string) ($transcript['raw_text'] ?? '')) !== ''
            || self::sections($transcript) !== [];
    }

    /**
     * Status line shown at the top of the summary modal.
     *
     * @param  array<string, mixed>|null  $transcript
     */
    public static function summaryStatusMessage(?array $transcript): string
    {
        $fallback = 'No summary has been created for this project.';

        return match ($transcript['summary_status'] ?? null) {
            'processing' => 'Creating the summary…',
            'complete' => 'Summary ready.',
            'failed' => (string) ($transcript['summary_error_message'] ?: 'The transcript could not be summarized.'),
            default => $fallback,
        };
    }

    /**
     * @param  array<string, mixed>  $transcript
     * @return array<int, array<string, mixed>>
     */
    private static function sections(array $transcript): array
    {
        $sections = $transcript['sections'] ?? null;

        if (! is_array($sections)) {
            return [];
        }

        return array_values(array_filter($sections, is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private static function sectionRange(array $section): string
    {
        if (($section['started_at_ms'] ?? null) === null && ($section['ended_at_ms'] ?? null) === null) {
            return '';
        }

        return self::timecode($section['started_at_ms'] ?? null).'-'.self::timecode($section['ended_at_ms'] ?? null);
    }

    private static function timecode(?int $milliseconds): string
    {
        $seconds = max(0, intdiv((int) $milliseconds, 1000));

        return str_pad((string) intdiv($seconds, 60), 2, '0', STR_PAD_LEFT)
            .':'.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    }
}
