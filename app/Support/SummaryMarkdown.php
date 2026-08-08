<?php

namespace App\Support;

/**
 * Renders the small markdown subset summaries use (headings, bullets, bold).
 * Ported from the client so the summary markup is produced server-side.
 */
class SummaryMarkdown
{
    private const EMPTY = '<p class="text-blue-900">No summary has been created for this project.</p>';

    public static function render(string $value): string
    {
        $lines = preg_split('/\r\n?|\n/', $value);

        if ($lines === false) {
            return self::EMPTY;
        }

        $html = [];
        $listOpen = false;

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            $isBullet = preg_match('/^[-*]\s+(.+)$/', $line, $bullet) === 1;

            // Any line that is not a bullet ends the current list.
            if ($listOpen && ! $isBullet) {
                $html[] = '</ul>';
                $listOpen = false;
            }

            if ($line === '') {
                continue;
            }

            if ($isBullet) {
                if (! $listOpen) {
                    $html[] = '<ul class="my-3 ml-5 list-disc space-y-2">';
                    $listOpen = true;
                }

                $html[] = '<li>'.self::inline($bullet[1]).'</li>';

                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $heading) === 1) {
                $level = min(4, max(3, strlen($heading[1])));
                $html[] = "<h{$level} class=\"mt-5 first:mt-0 text-sm font-semibold uppercase text-blue-700\">"
                    .self::inline($heading[2])."</h{$level}>";

                continue;
            }

            $html[] = '<p class="my-3 first:mt-0 last:mb-0">'.self::inline($line).'</p>';
        }

        if ($listOpen) {
            $html[] = '</ul>';
        }

        return implode('', $html) ?: self::EMPTY;
    }

    private static function inline(string $value): string
    {
        return preg_replace(
            '/\*\*(.+?)\*\*/',
            '<strong class="font-semibold text-black">$1</strong>',
            e($value),
        ) ?? e($value);
    }
}
