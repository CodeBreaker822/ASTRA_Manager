<?php

namespace App\Services;

use App\Models\Transcript;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ZipArchive;

class TranscriptExportService
{
    /**
     * @return array{path: string, name: string, mime: string}
     */
    public function export(Transcript $transcript, string $format, string $source, string $summarySource = 'raw'): array
    {
        if ($source === 'summary') {
            return $this->summaryExport($transcript, $format, $summarySource);
        }

        $text = $this->textFor($transcript, $source);
        $baseName = 'jerva-transcript-'.$transcript->id.'-'.$source;
        $directory = storage_path('app/private/exports');

        File::ensureDirectoryExists($directory);

        return match ($format) {
            'txt' => $this->txt($directory, $baseName, $text),
            'docx' => $this->docx($directory, $baseName, $text),
            'xlsx' => $this->xlsx($directory, $baseName, $text),
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
            'mime' => 'text/plain',
        ];
    }

    /**
     * @return array{path: string, name: string, mime: string}
     */
    private function docx(string $directory, string $baseName, string $text): array
    {
        $path = $directory.DIRECTORY_SEPARATOR.$baseName.'.docx';
        $zip = $this->openZip($path);
        $paragraphs = collect(preg_split("/\R{2,}/", trim($text)) ?: [$text])
            ->map(fn (string $paragraph): string => '<w:p><w:r><w:t xml:space="preserve">'.$this->xml($paragraph).'</w:t></w:r></w:p>')
            ->implode('');

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$paragraphs.'<w:sectPr/></w:body></w:document>');
        $zip->close();

        return [
            'path' => $path,
            'name' => $baseName.'.docx',
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
    }

    /**
     * @return array{path: string, name: string, mime: string}
     */
    private function xlsx(string $directory, string $baseName, string $text): array
    {
        $path = $directory.DIRECTORY_SEPARATOR.$baseName.'.xlsx';
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Transcript');

        collect(preg_split("/\R+/", trim($text)) ?: [$text])
            ->values()
            ->each(fn (string $line, int $index) => $sheet->setCellValue('A'.($index + 1), $line));

        $sheet->getColumnDimension('A')->setWidth(96);
        $sheet->getStyle('A:A')->getAlignment()->setWrapText(true);

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
    private function summaryExport(Transcript $transcript, string $format, string $summarySource): array
    {
        $text = $this->textFor($transcript, 'summary');
        $baseName = 'jerva-transcript-'.$transcript->id.'-summary';
        $directory = storage_path('app/private/exports');

        File::ensureDirectoryExists($directory);

        return match ($format) {
            'txt' => $this->txt($directory, $baseName, $this->summaryTextDocument($transcript, $text, $summarySource)),
            'docx' => $this->docx($directory, $baseName, $this->summaryTextDocument($transcript, $text, $summarySource)),
            'xlsx' => $this->summaryXlsx($directory, $baseName, $transcript, $text, $summarySource),
            default => throw new \InvalidArgumentException('Unsupported export format.'),
        };
    }

    private function summaryTextDocument(Transcript $transcript, string $summary, string $summarySource): string
    {
        $projectTitle = $this->projectTitle($transcript);
        $plainSummary = $this->markdownToPlainText($summary);

        return trim(implode("\n", [
            $projectTitle.' - Summary',
            'Project: '.$projectTitle,
            'Source: '.$this->summarySourceLabel($summarySource),
            '',
            $plainSummary,
        ]));
    }

    /**
     * @return array{path: string, name: string, mime: string}
     */
    private function summaryXlsx(string $directory, string $baseName, Transcript $transcript, string $summary, string $summarySource): array
    {
        $path = $directory.DIRECTORY_SEPARATOR.$baseName.'.xlsx';
        $projectTitle = $this->projectTitle($transcript);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Summary');
        $sheet->mergeCells('A1:B1');
        $sheet->setCellValue('A1', $projectTitle.' - Summary');
        $sheet->setCellValue('A3', 'Project');
        $sheet->setCellValue('B3', $projectTitle);
        $sheet->setCellValue('A4', 'Source');
        $sheet->setCellValue('B4', $this->summarySourceLabel($summarySource));
        $sheet->setCellValue('A6', 'Summary');
        $sheet->setCellValue('B6', $this->markdownToPlainText($summary));

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A3:A6')->getFont()->setBold(true);
        $sheet->getStyle('A1:B6')->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getStyle('B6')->getAlignment()->setWrapText(true);
        $sheet->getColumnDimension('A')->setWidth(18);
        $sheet->getColumnDimension('B')->setWidth(90);
        $sheet->getRowDimension(6)->setRowHeight(120);

        $this->saveSpreadsheet($spreadsheet, $path);

        return [
            'path' => $path,
            'name' => $baseName.'.xlsx',
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }

    private function projectTitle(Transcript $transcript): string
    {
        $transcript->loadMissing('project');

        return trim((string) ($transcript->project?->title ?: 'Project'));
    }

    private function summarySourceLabel(string $summarySource): string
    {
        return $summarySource === 'cleaned' ? 'Cleaned transcript' : 'Raw transcript';
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

    private function saveSpreadsheet(Spreadsheet $spreadsheet, string $path): void
    {
        if (! extension_loaded('zip') || ! extension_loaded('gd')) {
            throw new \RuntimeException('Excel exports require the PHP zip and gd extensions.');
        }

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    private function openZip(string $path): ZipArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create export file.');
        }

        return $zip;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
