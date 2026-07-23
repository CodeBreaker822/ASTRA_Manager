<?php

namespace App\Services\Api;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class TranscriberPackageService
{
    /**
     * @return array{version: string|null, zipfile: string|null}
     */
    public function current(): array
    {
        $directory = Storage::disk('local')->path('transcriber');
        $versionPath = $directory.DIRECTORY_SEPARATOR.'version.json';
        $version = null;

        if (File::isReadable($versionPath)) {
            try {
                $contents = json_decode(File::get($versionPath), true, 512, JSON_THROW_ON_ERROR);
                $version = is_array($contents) && is_string($contents['version'] ?? null)
                    ? $contents['version']
                    : null;
            } catch (Throwable) {
                // Keep the settings page available if a legacy version file is malformed.
            }
        }

        $zipFiles = File::isDirectory($directory)
            ? array_values(array_filter(
                File::files($directory),
                fn ($file): bool => strtolower($file->getExtension()) === 'zip',
            ))
            : [];

        usort($zipFiles, fn ($left, $right): int => strnatcasecmp($left->getFilename(), $right->getFilename()));

        return [
            'version' => $version,
            'zipfile' => $zipFiles === [] ? null : $zipFiles[0]->getFilename(),
        ];
    }

    /**
     * @return array{version: string, zipfile: string}
     */
    public function publish(string $version, UploadedFile $package): array
    {
        $directory = Storage::disk('local')->path('transcriber');
        $filename = 'standalone-transcriber-'.$version.'.zip';
        $temporaryPackage = $directory.DIRECTORY_SEPARATOR.'.upload-'.bin2hex(random_bytes(12)).'.tmp';
        $temporaryVersion = $directory.DIRECTORY_SEPARATOR.'.version-'.bin2hex(random_bytes(12)).'.json';

        try {
            $embeddedVersion = $this->versionFromZip((string) $package->getRealPath());

            if ($embeddedVersion === null) {
                throw new RuntimeException('The Transcriber App Package must include a root version.json file.');
            }

            if (! hash_equals($version, $embeddedVersion)) {
                throw new RuntimeException("The Transcriber App Package version.json version [{$embeddedVersion}] does not match the published version [{$version}].");
            }

            File::ensureDirectoryExists($directory);
            $package->move($directory, basename($temporaryPackage));

            File::put($temporaryVersion, json_encode(
                ['version' => $version],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ).PHP_EOL);

            foreach (File::files($directory) as $file) {
                if (strtolower($file->getExtension()) === 'zip' && $file->getPathname() !== $temporaryPackage) {
                    File::delete($file->getPathname());
                }
            }

            if (! File::move($temporaryPackage, $directory.DIRECTORY_SEPARATOR.$filename)) {
                throw new RuntimeException('Unable to publish the Transcriber App Package.');
            }

            if (! File::move($temporaryVersion, $directory.DIRECTORY_SEPARATOR.'version.json')) {
                throw new RuntimeException('Unable to publish the Transcriber App version.');
            }
        } catch (Throwable $exception) {
            File::delete([$temporaryPackage, $temporaryVersion]);

            throw $exception;
        }

        return [
            'version' => $version,
            'zipfile' => $filename,
        ];
    }

    public function uploadError(Throwable $exception, string $errorId): string
    {
        return "Transcriber package upload failed. Error reference: {$errorId}.";
    }

    private function versionFromZip(string $path): ?string
    {
        $versionJson = $this->readFileFromZip($path, 'version.json');

        if ($versionJson === null) {
            return null;
        }

        $payload = json_decode($versionJson, true);

        return is_array($payload) && is_string($payload['version'] ?? null)
            ? trim($payload['version'])
            : null;
    }

    private function readFileFromZip(string $path, string $wantedName): ?string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to read the uploaded Transcriber App Package.');
        }

        try {
            $size = filesize($path);

            if ($size === false || $size < 22) {
                throw new RuntimeException('The Transcriber App Package is not a readable ZIP file.');
            }

            $tailSize = min($size, 65557);
            fseek($handle, -$tailSize, SEEK_END);
            $tail = fread($handle, $tailSize);
            $endOffset = is_string($tail) ? strrpos($tail, "PK\x05\x06") : false;

            if ($endOffset === false) {
                throw new RuntimeException('The Transcriber App Package is not a readable ZIP file.');
            }

            $endRecord = substr($tail, $endOffset, 22);
            $directory = unpack('ventries/vtotal/Vsize/Voffset', substr($endRecord, 8, 12));

            if (! is_array($directory)) {
                throw new RuntimeException('The Transcriber App Package directory could not be read.');
            }

            fseek($handle, (int) $directory['offset']);
            $read = 0;

            while ($read < (int) $directory['size']) {
                $header = fread($handle, 46);

                if (! is_string($header) || strlen($header) !== 46 || substr($header, 0, 4) !== "PK\x01\x02") {
                    throw new RuntimeException('The Transcriber App Package directory is malformed.');
                }

                $entry = unpack(
                    'x10/vmethod/x8/VcompressedSize/VuncompressedSize/vnameLength/vextraLength/vcommentLength/x8/VlocalOffset',
                    $header,
                );

                if (! is_array($entry)) {
                    throw new RuntimeException('The Transcriber App Package directory entry could not be read.');
                }

                $name = ((int) $entry['nameLength']) < 1
                    ? ''
                    : fread($handle, (int) $entry['nameLength']);

                fseek($handle, (int) $entry['extraLength'] + (int) $entry['commentLength'], SEEK_CUR);
                $read += 46 + (int) $entry['nameLength'] + (int) $entry['extraLength'] + (int) $entry['commentLength'];

                if (! is_string($name) || ltrim(str_replace('\\', '/', $name), './') !== $wantedName) {
                    continue;
                }

                return $this->readZipEntryContents($handle, (int) $entry['localOffset'], (int) $entry['method'], (int) $entry['compressedSize']);
            }
        } finally {
            fclose($handle);
        }

        return null;
    }

    /**
     * @param  resource  $handle
     */
    private function readZipEntryContents(mixed $handle, int $localOffset, int $method, int $compressedSize): string
    {
        if ($compressedSize < 0 || $compressedSize > 1024 * 1024) {
            throw new RuntimeException('The Transcriber App Package version.json file is too large.');
        }

        fseek($handle, $localOffset);
        $localHeader = fread($handle, 30);

        if (! is_string($localHeader) || strlen($localHeader) !== 30 || substr($localHeader, 0, 4) !== "PK\x03\x04") {
            throw new RuntimeException('The Transcriber App Package file entry is malformed.');
        }

        $local = unpack('vnameLength/vextraLength', substr($localHeader, 26, 4));

        if (! is_array($local)) {
            throw new RuntimeException('The Transcriber App Package file entry could not be read.');
        }

        fseek($handle, (int) $local['nameLength'] + (int) $local['extraLength'], SEEK_CUR);

        if ($compressedSize === 0) {
            return '';
        }

        $contents = fread($handle, $compressedSize);

        if (! is_string($contents) || strlen($contents) !== $compressedSize) {
            throw new RuntimeException('The Transcriber App Package version.json file could not be read.');
        }

        return match ($method) {
            0 => $contents,
            8 => gzinflate($contents) ?: throw new RuntimeException('The Transcriber App Package version.json file could not be decompressed.'),
            default => throw new RuntimeException('The Transcriber App Package version.json file uses an unsupported ZIP compression method.'),
        };
    }
}
