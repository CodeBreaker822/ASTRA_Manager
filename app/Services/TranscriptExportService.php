<?php

namespace App\Services;

use App\Models\Transcript;
use App\Models\TranscriptSection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use ZipArchive;

class TranscriptExportService
{
    private const NO_SPEECH_VALUES = ['', 'no speech detected', 'no speech detected.'];

    /**
     * @return array{path: string, name: string, mime: string}
     */
    public function export(Transcript $transcript, string $format, string $source): array
    {
        if ($source === 'summary') {
            return $this->summaryExport($transcript, $format);
        }

        $rows = $this->transcriptRows($transcript, $source);

        if ($rows === []) {
            throw new RuntimeException('No transcription is ready to export yet.');
        }

        $projectTitle = $this->projectTitle($transcript);
        $variantLabel = $source === 'cleaned' ? 'Cleaned' : 'Raw';
        $mode = $source === 'cleaned' ? 'clean' : 'raw';
        $baseName = $this->slugify($projectTitle).'-'.$mode.'-transcription';
        $directory = $this->exportDirectory();

        return match ($format) {
            'txt' => $this->transcriptTxt($directory, $baseName, $rows),
            'docx' => $this->transcriptDocx($directory, $baseName, $projectTitle, $variantLabel, $rows),
            'xlsx' => $this->transcriptXlsx($directory, $baseName, $projectTitle, $variantLabel, $rows),
            default => throw new \InvalidArgumentException('Unsupported export format.'),
        };
    }

    /**
     * @return list<array{
     *     range_label: string,
     *     display_text: string,
     *     speaker_labels: list<string>,
     *     speaker_turns: list<array{speakerId: string, speakerLabel: string, text: string}>
     * }>
     */
    private function transcriptRows(Transcript $transcript, string $source): array
    {
        $transcript->loadMissing(['sections' => fn ($query) => $query->orderBy('position')]);

        if ($source === 'cleaned' && filled($transcript->cleaned_text)) {
            $cleanedSections = $transcript->sections
                ->filter(fn (TranscriptSection $section): bool => filled($section->cleaned_text));

            if ($cleanedSections->isEmpty()) {
                return $this->singleTranscriptRow(
                    $transcript,
                    (string) $transcript->cleaned_text,
                );
            }
        }

        $rows = $transcript->sections
            ->map(function (TranscriptSection $section) use ($source): array {
                $text = (string) ($source === 'cleaned'
                    ? ($section->cleaned_text ?: $section->text)
                    : $section->text);

                return $this->transcriptRow(
                    $this->rangeLabel($section->started_at_ms, $section->ended_at_ms),
                    $text,
                    $section->speaker_timestamps ?? [],
                );
            })
            ->filter(fn (array $row): bool => $this->isUsefulText($row['display_text']))
            ->values()
            ->all();

        if ($rows !== []) {
            return $rows;
        }

        $text = $source === 'cleaned'
            ? (string) ($transcript->cleaned_text ?: $transcript->raw_text)
            : (string) $transcript->raw_text;

        return $this->singleTranscriptRow($transcript, $text);
    }

    /**
     * @return list<array{
     *     range_label: string,
     *     display_text: string,
     *     speaker_labels: list<string>,
     *     speaker_turns: list<array{speakerId: string, speakerLabel: string, text: string}>
     * }>
     */
    private function singleTranscriptRow(Transcript $transcript, string $text): array
    {
        if (! $this->isUsefulText($text)) {
            return [];
        }

        return [
            $this->transcriptRow(
                $this->rangeLabel(0, max(0, (int) $transcript->duration_seconds * 1000)),
                $text,
            ),
        ];
    }

    /**
     * @return array{
     *     range_label: string,
     *     display_text: string,
     *     speaker_labels: list<string>,
     *     speaker_turns: list<array{speakerId: string, speakerLabel: string, text: string}>
     * }
     */
    private function transcriptRow(string $rangeLabel, string $text, array $timestamps = []): array
    {
        $speakerTurns = $this->speakerTurnsFromTimestamps($timestamps);
        $displayText = $speakerTurns === []
            ? trim($text)
            : implode("\n", array_map(
                fn (array $turn): string => $turn['speakerLabel'].': '.$turn['text'],
                $speakerTurns,
            ));

        if ($speakerTurns === []) {
            $speakerTurns = $this->speakerTurnsFromText($displayText);
        }

        $speakerLabels = array_values(array_unique(array_map(
            fn (array $turn): string => $turn['speakerLabel'],
            $speakerTurns,
        )));

        return [
            'range_label' => $rangeLabel,
            'display_text' => $displayText,
            'speaker_labels' => $speakerLabels,
            'speaker_turns' => $speakerTurns,
        ];
    }

    /**
     * Recognizes the same visible "Speaker N: text" structure emitted by the
     * desktop app when server transcript text contains diarization labels.
     *
     * @return list<array{speakerId: string, speakerLabel: string, text: string}>
     */
    private function speakerTurnsFromTimestamps(array $timestamps): array
    {
        $turns = [];

        foreach ($timestamps as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $part = trim((string) ($entry['text'] ?? ''));
            $speakerId = trim((string) ($entry['speaker_id'] ?? $entry['speakerId'] ?? ''));

            if ($part === '' || $speakerId === '') {
                continue;
            }

            $speakerLabel = $this->speakerLabel($speakerId);
            $previous = end($turns);

            if ($previous !== false && $previous['speakerId'] === $speakerId) {
                $turns[count($turns) - 1]['text'] = $this->appendTranscriptPart($previous['text'], $part);

                continue;
            }

            $turns[] = [
                'speakerId' => $speakerId,
                'speakerLabel' => $speakerLabel,
                'text' => $part,
            ];
        }

        return $turns;
    }

    /**
     * @return list<array{speakerId: string, speakerLabel: string, text: string}>
     */
    private function speakerTurnsFromText(string $text): array
    {
        $turns = [];

        foreach (preg_split("/\R/", $text) ?: [] as $line) {
            if (preg_match('/^\s*(speaker(?:[\s_-]*\d+)?)\s*:\s*(.+)$/iu', $line, $matches) !== 1) {
                continue;
            }

            $speakerId = trim($matches[1]);
            $speakerLabel = $this->speakerLabel($speakerId);
            $part = trim($matches[2]);
            $previous = end($turns);

            if ($previous !== false && $previous['speakerLabel'] === $speakerLabel) {
                $turns[count($turns) - 1]['text'] = trim($previous['text'].' '.$part);

                continue;
            }

            $turns[] = [
                'speakerId' => $speakerId,
                'speakerLabel' => $speakerLabel,
                'text' => $part,
            ];
        }

        return $turns;
    }

    private function appendTranscriptPart(string $current, string $part): string
    {
        if (preg_match('/^[.,!?;:%)\]}]/u', $part) === 1 || preg_match('/[(\[{]$/u', $current) === 1) {
            return $current.$part;
        }

        return $current.' '.$part;
    }

    private function speakerLabel(string $speakerId): string
    {
        preg_match('/(\d+)$/', $speakerId, $matches);

        return isset($matches[1])
            ? 'Speaker '.max(1, (int) $matches[1])
            : 'Speaker';
    }

    private function isUsefulText(?string $text): bool
    {
        return ! in_array(Str::lower(trim((string) $text)), self::NO_SPEECH_VALUES, true);
    }

    /**
     * @param  list<array{range_label: string, display_text: string, speaker_labels: list<string>, speaker_turns: list<array{speakerId: string, speakerLabel: string, text: string}>}>  $rows
     * @return array{path: string, name: string, mime: string}
     */
    private function transcriptTxt(string $directory, string $baseName, array $rows): array
    {
        $text = implode("\n\n", array_map(
            fn (array $row): string => trim(implode("\n", array_filter([
                $row['range_label'],
                $row['display_text'],
            ], fn (string $value): bool => $value !== ''))),
            $rows,
        ));

        return $this->txt($directory, $baseName, $text);
    }

    /**
     * @param  list<array{range_label: string, display_text: string, speaker_labels: list<string>, speaker_turns: list<array{speakerId: string, speakerLabel: string, text: string}>}>  $rows
     * @return array{path: string, name: string, mime: string}
     */
    private function transcriptDocx(
        string $directory,
        string $baseName,
        string $projectTitle,
        string $variantLabel,
        array $rows,
    ): array {
        $path = $directory.DIRECTORY_SEPARATOR.$baseName.'.docx';
        $zip = $this->openZip($path);
        $documentTitle = $projectTitle.' - '.$variantLabel.' Transcript';
        $body = $this->wordParagraph(
            $documentTitle,
            '<w:pPr><w:spacing w:after="80"/></w:pPr>',
            '<w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:b/><w:color w:val="0F172A"/><w:sz w:val="52"/></w:rPr>',
        );
        $body .= $this->wordParagraph(
            'GENERATED BY '.$this->brandName(),
            '<w:pPr><w:spacing w:after="400"/></w:pPr>',
            '<w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:color w:val="64748B"/><w:sz w:val="18"/><w:spacing w:val="36"/></w:rPr>',
        );

        foreach ($rows as $row) {
            $body .= $this->wordParagraph(
                $row['range_label'] ?: 'Transcript',
                '<w:pPr><w:pBdr><w:top w:val="single" w:sz="16" w:space="10" w:color="BAE6FD"/></w:pBdr><w:spacing w:before="160" w:after="120"/><w:shd w:val="clear" w:fill="ECFEFF"/></w:pPr>',
                '<w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:b/><w:color w:val="0E7490"/><w:sz w:val="22"/></w:rPr>',
            );

            if ($row['speaker_turns'] !== []) {
                foreach ($row['speaker_turns'] as $turn) {
                    $body .= $this->wordSpeakerParagraph($turn['speakerLabel'], $turn['text']);
                }
            } else {
                $body .= $this->wordParagraph(
                    $row['display_text'],
                    '<w:pPr><w:spacing w:after="160" w:line="360" w:lineRule="auto"/><w:jc w:val="both"/></w:pPr>',
                    '<w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:color w:val="111827"/><w:sz w:val="22"/></w:rPr>',
                );
            }
        }

        $zip->addFromString('[Content_Types].xml', $this->wordContentTypes());
        $zip->addFromString('_rels/.rels', $this->wordPackageRelationships());
        $zip->addFromString('word/document.xml', $this->wordDocument($body));
        $zip->close();

        return [
            'path' => $path,
            'name' => $baseName.'.docx',
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
    }

    /**
     * @param  list<array{range_label: string, display_text: string, speaker_labels: list<string>, speaker_turns: list<array{speakerId: string, speakerLabel: string, text: string}>}>  $rows
     * @return array{path: string, name: string, mime: string}
     */
    private function transcriptXlsx(
        string $directory,
        string $baseName,
        string $projectTitle,
        string $variantLabel,
        array $rows,
    ): array {
        $path = $directory.DIRECTORY_SEPARATOR.$baseName.'.xlsx';
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($variantLabel.' Transcript');
        $sheet->mergeCells('A1:D1');
        $sheet->mergeCells('A2:D2');
        $this->setString($sheet, 'A1', $projectTitle.' - '.$variantLabel.' Transcript');
        $this->setString($sheet, 'A2', 'Generated by '.$this->brandName());

        foreach (['A4' => '#', 'B4' => 'Time Range', 'C4' => 'Speakers', 'D4' => 'Transcript'] as $cell => $value) {
            $this->setString($sheet, $cell, $value);
        }

        foreach ($rows as $index => $row) {
            $excelRow = $index + 5;
            $this->setString($sheet, 'A'.$excelRow, (string) $index);
            $this->setString($sheet, 'B'.$excelRow, $row['range_label']);
            $this->setString(
                $sheet,
                'C'.$excelRow,
                $row['speaker_labels'] !== [] ? implode(', ', $row['speaker_labels']) : 'Transcript',
            );
            $this->setString($sheet, 'D'.$excelRow, $row['display_text']);

            if ($index % 2 === 1) {
                $sheet->getStyle("A{$excelRow}:D{$excelRow}")
                    ->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setRGB('F8FAFC');
            }
        }

        $lastRow = count($rows) + 4;
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('0891B2');
        $sheet->getStyle('A2')->getFont()->setSize(9)->getColor()->setRGB('64748B');
        $sheet->getStyle('A4:D4')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A4:D4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0F172A');
        $sheet->getStyle("A4:D{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
        $sheet->getStyle("A5:D{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getStyle("B5:B{$lastRow}")->getFont()->setBold(true)->getColor()->setRGB('0891B2');
        $sheet->getStyle("C5:C{$lastRow}")->getFont()->setBold(true)->getColor()->setRGB('0F766E');
        $sheet->getStyle("D5:D{$lastRow}")->getAlignment()->setWrapText(true);
        $sheet->getStyle("A4:D{$lastRow}")->getAlignment()->setWrapText(true);
        $sheet->getStyle('A1:D2')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(26);
        $sheet->getRowDimension(2)->setRowHeight(18);
        $sheet->getRowDimension(4)->setRowHeight(24);
        $sheet->getColumnDimension('A')->setWidth(7);
        $sheet->getColumnDimension('B')->setWidth(21);
        $sheet->getColumnDimension('C')->setWidth(24);
        $sheet->getColumnDimension('D')->setWidth(88);
        $sheet->freezePane('A5');
        $sheet->setAutoFilter("A4:D{$lastRow}");
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setFitToWidth(1)
            ->setFitToHeight(0);

        $this->saveSpreadsheet($spreadsheet, $path);

        return [
            'path' => $path,
            'name' => $baseName.'.xlsx',
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }

    /**
     * @return array{path: string, name: string, mime: string}
     */
    private function summaryExport(Transcript $transcript, string $format): array
    {
        $text = $this->textFor($transcript, 'summary');
        $baseName = $this->slugify($this->projectTitle($transcript)).'-summary';
        $directory = $this->exportDirectory();

        return match ($format) {
            'txt' => $this->txt($directory, $baseName, $this->summaryTextDocument($transcript, $text)),
            'docx' => $this->summaryDocx($directory, $baseName, $transcript, $text),
            'xlsx' => $this->summaryXlsx($directory, $baseName, $transcript, $text),
            default => throw new \InvalidArgumentException('Unsupported export format.'),
        };
    }

    private function textFor(Transcript $transcript, string $source): string
    {
        $text = match ($source) {
            'cleaned' => $transcript->cleaned_text ?: $transcript->raw_text,
            'summary' => $transcript->summary_text,
            default => $transcript->raw_text,
        };

        if (filled($text)) {
            return trim((string) $text);
        }

        if ($source === 'summary') {
            return '';
        }

        return $transcript->sections()
            ->orderBy('position')
            ->get()
            ->map(fn ($section): string => (string) ($source === 'cleaned'
                ? ($section->cleaned_text ?: $section->text)
                : $section->text))
            ->filter()
            ->implode("\n\n");
    }

    /**
     * @return array{path: string, name: string, mime: string}
     */
    private function txt(string $directory, string $baseName, string $text): array
    {
        $path = $directory.DIRECTORY_SEPARATOR.$baseName.'.txt';
        File::put($path, $text.PHP_EOL);

        return [
            'path' => $path,
            'name' => $baseName.'.txt',
            'mime' => 'text/plain; charset=UTF-8',
        ];
    }

    /**
     * @return array{path: string, name: string, mime: string}
     */
    private function summaryDocx(
        string $directory,
        string $baseName,
        Transcript $transcript,
        string $summary,
    ): array {
        $path = $directory.DIRECTORY_SEPARATOR.$baseName.'.docx';
        $zip = $this->openZip($path);
        $projectTitle = $this->projectTitle($transcript);
        $body = $this->wordParagraph(
            $projectTitle.' - Summary',
            '<w:pPr><w:spacing w:after="80"/></w:pPr>',
            '<w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:b/><w:color w:val="312E81"/><w:sz w:val="52"/></w:rPr>',
        );
        $body .= $this->wordParagraph(
            'PROJECT: '.$projectTitle.' | SOURCE: Current transcript',
            '<w:pPr><w:spacing w:after="360"/></w:pPr>',
            '<w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:color w:val="64748B"/><w:sz w:val="24"/><w:spacing w:val="32"/></w:rPr>',
        );
        $body .= $this->summaryWordBody($summary);

        $zip->addFromString('[Content_Types].xml', $this->wordContentTypes());
        $zip->addFromString('_rels/.rels', $this->wordPackageRelationships());
        $zip->addFromString('word/document.xml', $this->wordDocument($body));
        $zip->close();

        return [
            'path' => $path,
            'name' => $baseName.'.docx',
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
    }

    private function summaryTextDocument(Transcript $transcript, string $summary): string
    {
        $projectTitle = $this->projectTitle($transcript);
        $plainSummary = $this->markdownToPlainText($summary);

        return trim(implode("\n", [
            $projectTitle.' - Summary',
            'Project: '.$projectTitle,
            'Source: Current transcript',
            '',
            $plainSummary,
        ]));
    }

    /**
     * @return array{path: string, name: string, mime: string}
     */
    private function summaryXlsx(
        string $directory,
        string $baseName,
        Transcript $transcript,
        string $summary,
    ): array {
        $path = $directory.DIRECTORY_SEPARATOR.$baseName.'.xlsx';
        $projectTitle = $this->projectTitle($transcript);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Summary');
        $sheet->mergeCells('A1:B1');
        $this->setString($sheet, 'A1', $projectTitle.' - Summary');
        $this->setString($sheet, 'A3', 'Project');
        $this->setString($sheet, 'B3', $projectTitle);
        $this->setString($sheet, 'A4', 'Source');
        $this->setString($sheet, 'B4', 'Current transcript');
        $this->setString($sheet, 'A5', 'Summary');
        $sheet->setCellValue('B5', $this->summaryRichText($summary));

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('7C3AED');
        $sheet->getStyle('A3:A5')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A3:A5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('312E81');
        $sheet->getStyle('A3:B5')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
        $sheet->getStyle('A3:B5')->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getStyle('B4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8FAFC');
        $sheet->getStyle('B5')->getAlignment()->setWrapText(true);
        $sheet->getColumnDimension('A')->setWidth(18);
        $sheet->getColumnDimension('B')->setWidth(90);
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getRowDimension(5)->setRowHeight(max(120, min(360, substr_count($summary, "\n") * 18 + 60)));

        $this->saveSpreadsheet($spreadsheet, $path);

        return [
            'path' => $path,
            'name' => $baseName.'.xlsx',
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }

    private function exportDirectory(): string
    {
        $directory = storage_path('app/private/exports');
        File::ensureDirectoryExists($directory);

        return $directory;
    }

    private function projectTitle(Transcript $transcript): string
    {
        $transcript->loadMissing('project');

        return trim((string) ($transcript->project?->title ?: 'Transcription'));
    }

    private function brandName(): string
    {
        return trim((string) config('app.name', 'JERVA Transcriber')) ?: 'JERVA Transcriber';
    }

    private function summaryWordBody(string $summary): string
    {
        $lines = preg_split("/\R/", trim($summary)) ?: [];

        if ($lines === []) {
            $lines = ['No summary has been created for this project.'];
        }

        $body = '';
        $firstBlock = true;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            $topBorder = $firstBlock
                ? '<w:top w:val="single" w:sz="16" w:space="14" w:color="DDD6FE"/>'
                : '';
            $firstBlock = false;

            if (preg_match('/^(#{1,6})\s+(.+)$/u', $trimmed, $matches) === 1) {
                $mainHeading = strlen($matches[1]) <= 3;
                $properties = '<w:pPr>'
                    .($mainHeading
                        ? '<w:pBdr>'.$topBorder.'<w:bottom w:val="single" w:sz="4" w:space="4" w:color="DDD6FE"/></w:pBdr><w:spacing w:before="320" w:after="160"/>'
                        : ($topBorder !== '' ? '<w:pBdr>'.$topBorder.'</w:pBdr>' : '').'<w:spacing w:before="240" w:after="120"/>')
                    .'</w:pPr>';

                $body .= $this->summaryWordParagraph(
                    $matches[2],
                    $properties,
                    $mainHeading ? '36' : '30',
                    $mainHeading ? '312E81' : '4338CA',
                    true,
                );

                continue;
            }

            if (preg_match('/^[-*]\s+(.+)$/u', $trimmed, $matches) === 1) {
                $body .= $this->summaryWordParagraph(
                    '• '.$matches[1],
                    '<w:pPr>'.($topBorder !== '' ? '<w:pBdr>'.$topBorder.'</w:pBdr>' : '').'<w:ind w:left="480" w:hanging="240"/><w:spacing w:after="100" w:line="372" w:lineRule="auto"/></w:pPr>',
                    '22',
                    '111827',
                );

                continue;
            }

            $body .= $this->summaryWordParagraph(
                $trimmed,
                '<w:pPr>'.($topBorder !== '' ? '<w:pBdr>'.$topBorder.'</w:pBdr>' : '').'<w:spacing w:after="180" w:line="372" w:lineRule="auto"/></w:pPr>',
                '22',
                '111827',
            );
        }

        return $body;
    }

    private function summaryWordParagraph(
        string $text,
        string $paragraphProperties,
        string $size,
        string $color,
        bool $bold = false,
    ): string {
        $baseProperties = '<w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/>'
            .($bold ? '<w:b/>' : '')
            .'<w:color w:val="'.$color.'"/><w:sz w:val="'.$size.'"/></w:rPr>';
        $boldProperties = '<w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:b/>'
            .'<w:color w:val="'.$color.'"/><w:sz w:val="'.$size.'"/></w:rPr>';

        return '<w:p>'.$paragraphProperties
            .$this->summaryWordRuns($text, $baseProperties, $boldProperties)
            .'</w:p>';
    }

    private function summaryWordRuns(string $text, string $baseProperties, string $boldProperties): string
    {
        $runs = '';
        $offset = 0;

        while (preg_match('/\*\*(.+?)\*\*/us', $text, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $matchStart = $match[0][1];
            $before = substr($text, $offset, $matchStart - $offset);

            if ($before !== '') {
                $runs .= '<w:r>'.$baseProperties.$this->wordText($before).'</w:r>';
            }

            $runs .= '<w:r>'.$boldProperties.$this->wordText($match[1][0]).'</w:r>';
            $offset = $matchStart + strlen($match[0][0]);
        }

        $after = substr($text, $offset);

        if ($after !== '' || $runs === '') {
            $runs .= '<w:r>'.$baseProperties.$this->wordText($after).'</w:r>';
        }

        return $runs;
    }

    private function summaryRichText(string $summary): RichText
    {
        $richText = new RichText;
        $lines = preg_split("/\R/", trim($summary)) ?: [];

        if ($lines === []) {
            $lines = ['No summary has been created for this project.'];
        }

        $written = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            if ($written) {
                $richText->createText("\n");
            }
            $written = true;

            if (preg_match('/^(#{1,6})\s+(.+)$/u', $trimmed, $matches) === 1) {
                $mainHeading = strlen($matches[1]) <= 3;
                $this->appendSummaryRichText(
                    $richText,
                    $matches[2],
                    true,
                    $mainHeading ? 16 : 13,
                    $mainHeading ? '312E81' : '4338CA',
                );

                continue;
            }

            if (preg_match('/^[-*]\s+(.+)$/u', $trimmed, $matches) === 1) {
                $richText->createText('• ');
                $this->appendSummaryRichText($richText, $matches[1]);

                continue;
            }

            $this->appendSummaryRichText($richText, $trimmed);
        }

        return $richText;
    }

    private function appendSummaryRichText(
        RichText $richText,
        string $text,
        bool $bold = false,
        int $size = 11,
        string $color = '111827',
    ): void {
        $offset = 0;

        while (preg_match('/\*\*(.+?)\*\*/us', $text, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $matchStart = $match[0][1];
            $before = substr($text, $offset, $matchStart - $offset);

            if ($before !== '') {
                $this->appendSummaryRichTextRun($richText, $before, $bold, $size, $color);
            }

            $this->appendSummaryRichTextRun($richText, $match[1][0], true, $size, $color);
            $offset = $matchStart + strlen($match[0][0]);
        }

        $after = substr($text, $offset);

        if ($after !== '' || $offset === 0) {
            $this->appendSummaryRichTextRun($richText, $after, $bold, $size, $color);
        }
    }

    private function appendSummaryRichTextRun(
        RichText $richText,
        string $text,
        bool $bold,
        int $size,
        string $color,
    ): void {
        $run = $richText->createTextRun($text);
        $run->getFont()
            ->setName('Calibri')
            ->setSize($size)
            ->setBold($bold)
            ->getColor()
            ->setRGB($color);
    }

    private function markdownToPlainText(string $value): string
    {
        return collect(preg_split("/\R/", $value) ?: [])
            ->map(fn (string $line): string => trim((string) preg_replace([
                '/^#{1,3}\s+/',
                '/^[-*]\s+/',
                '/\*\*(.+?)\*\*/',
            ], [
                '',
                '- ',
                '$1',
            ], $line)))
            ->implode("\n");
    }

    private function rangeLabel(?int $startedAtMs, ?int $endedAtMs): string
    {
        if ($startedAtMs === null && $endedAtMs === null) {
            return '';
        }

        return $this->formatTime($startedAtMs).'-'.$this->formatTime($endedAtMs);
    }

    private function formatTime(?int $milliseconds): string
    {
        $seconds = max(0, (int) floor(($milliseconds ?? 0) / 1000));
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainder = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $remainder);
        }

        return sprintf('%02d:%02d', $minutes, $remainder);
    }

    private function slugify(string $value): string
    {
        return Str::slug($value) ?: 'transcription';
    }

    private function saveSpreadsheet(Spreadsheet $spreadsheet, string $path): void
    {
        if (! extension_loaded('zip') || ! extension_loaded('gd')) {
            throw new RuntimeException('Excel exports require the PHP zip and gd extensions.');
        }

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    private function setString(Worksheet $sheet, string $cell, string $value): void
    {
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
    }

    private function openZip(string $path): ZipArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create export file.');
        }

        return $zip;
    }

    private function wordParagraph(string $text, string $paragraphProperties, string $runProperties): string
    {
        return '<w:p>'.$paragraphProperties.'<w:r>'.$runProperties.$this->wordText($text).'</w:r></w:p>';
    }

    private function wordSpeakerParagraph(string $speaker, string $text): string
    {
        return '<w:p><w:pPr><w:spacing w:after="120" w:line="360" w:lineRule="auto"/></w:pPr>'
            .'<w:r><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:b/><w:color w:val="0F766E"/><w:sz w:val="22"/></w:rPr>'
            .$this->wordText($speaker.':').'</w:r>'
            .'<w:r><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:color w:val="111827"/><w:sz w:val="22"/></w:rPr>'
            .$this->wordText(' '.$text).'</w:r></w:p>';
    }

    private function wordText(string $text): string
    {
        $lines = preg_split("/\R/", $text) ?: [$text];

        return implode('<w:br/>', array_map(
            fn (string $line): string => '<w:t xml:space="preserve">'.$this->xml($line).'</w:t>',
            $lines,
        ));
    }

    private function wordDocument(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body>'.$body
            .'<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1080" w:right="1080" w:bottom="1080" w:left="1080" w:header="720" w:footer="720" w:gutter="0"/></w:sectPr>'
            .'</w:body></w:document>';
    }

    private function wordContentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'</Types>';
    }

    private function wordPackageRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'</Relationships>';
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
