<?php

namespace App\Services;

use App\Models\Transcript;
use Illuminate\Support\Facades\File;
use ZipArchive;

class TranscriptExportService
{
    /**
     * @return array{path: string, name: string, mime: string}
     */
    public function export(Transcript $transcript, string $format, string $source): array
    {
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
        $zip = $this->openZip($path);
        $rows = collect(preg_split("/\R+/", trim($text)) ?: [$text])
            ->values()
            ->map(fn (string $line, int $index): string => '<row r="'.($index + 1).'"><c r="A'.($index + 1).'" t="inlineStr"><is><t>'.$this->xml($line).'</t></is></c></row>')
            ->implode('');

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Transcript" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rows.'</sheetData></worksheet>');
        $zip->close();

        return [
            'path' => $path,
            'name' => $baseName.'.xlsx',
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
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
