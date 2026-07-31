<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use RuntimeException;

class ChunkedUploadService
{
    public const MAX_TOTAL_BYTES = 500 * 1024 * 1024;

    public const MAX_CHUNK_BYTES = 50 * 1024 * 1024;

    /**
     * @return array{upload_id: string, received_chunks: int, total_chunks: int, complete: bool}
     */
    public function storeChunk(
        string $kind,
        string $ownerKey,
        string $uploadId,
        UploadedFile $chunk,
        int $chunkIndex,
        int $totalChunks,
        int $totalSize,
        string $filename,
        ?string $mimeType,
        ?string $chunkHash,
    ): array {
        $uploadId = $this->normalizeUploadId($uploadId);
        $ownerKey = $this->normalizeOwnerKey($ownerKey);
        $kind = $this->normalizeKind($kind);
        $filename = $this->normalizeFilename($filename);

        if ($totalSize < 1 || $totalSize > self::MAX_TOTAL_BYTES) {
            throw new RuntimeException('The uploaded file must not exceed 500 MB.');
        }

        if ($totalChunks < 1 || $chunkIndex < 0 || $chunkIndex >= $totalChunks) {
            throw new RuntimeException('The uploaded file chunk is invalid.');
        }

        $chunkSize = (int) ($chunk->getSize() ?? 0);

        if ($chunkSize < 1 || $chunkSize > self::MAX_CHUNK_BYTES) {
            throw new RuntimeException('The uploaded file chunk is too large.');
        }

        $path = $chunk->getRealPath();

        if (! is_string($path) || ! is_file($path)) {
            throw new RuntimeException('The uploaded file chunk could not be read.');
        }

        $expectedHash = trim((string) $chunkHash);

        if ($expectedHash !== '' && ! hash_equals(strtolower($expectedHash), hash_file('sha256', $path))) {
            throw new RuntimeException('The uploaded file chunk failed integrity validation.');
        }

        $directory = $this->sessionDirectory($kind, $ownerKey, $uploadId);
        $chunksDirectory = $directory.DIRECTORY_SEPARATOR.'chunks';
        File::ensureDirectoryExists($chunksDirectory);

        $metadata = $this->metadata($directory);

        if ($metadata !== []) {
            if (
                (int) ($metadata['total_size'] ?? 0) !== $totalSize
                || (int) ($metadata['total_chunks'] ?? 0) !== $totalChunks
                || (string) ($metadata['filename'] ?? '') !== $filename
            ) {
                throw new RuntimeException('The uploaded file chunk does not match this upload session.');
            }
        } else {
            $metadata = [
                'kind' => $kind,
                'owner_key' => $ownerKey,
                'upload_id' => $uploadId,
                'filename' => $filename,
                'mime_type' => $mimeType ?: 'application/octet-stream',
                'total_size' => $totalSize,
                'total_chunks' => $totalChunks,
                'chunks' => [],
                'created_at' => now()->toISOString(),
            ];
        }

        $storedChunkPath = $chunksDirectory.DIRECTORY_SEPARATOR.$chunkIndex.'.part';
        File::copy($path, $storedChunkPath);

        $metadata['chunks'][(string) $chunkIndex] = [
            'size' => filesize($storedChunkPath),
            'sha256' => hash_file('sha256', $storedChunkPath),
            'received_at' => now()->toISOString(),
        ];
        $metadata['updated_at'] = now()->toISOString();
        $this->writeMetadata($directory, $metadata);

        $received = count($metadata['chunks']);

        return [
            'upload_id' => $uploadId,
            'received_chunks' => $received,
            'total_chunks' => $totalChunks,
            'complete' => $received === $totalChunks,
        ];
    }

    /**
     * @return array{path: string, filename: string, mime_type: string, size: int, upload_id: string}
     */
    public function assemble(string $kind, string $ownerKey, string $uploadId): array
    {
        $uploadId = $this->normalizeUploadId($uploadId);
        $ownerKey = $this->normalizeOwnerKey($ownerKey);
        $kind = $this->normalizeKind($kind);
        $directory = $this->sessionDirectory($kind, $ownerKey, $uploadId);
        $metadata = $this->metadata($directory);

        if ($metadata === [] || (string) ($metadata['owner_key'] ?? '') !== $ownerKey) {
            throw new RuntimeException('The upload session was not found.');
        }

        $totalChunks = (int) ($metadata['total_chunks'] ?? 0);
        $totalSize = (int) ($metadata['total_size'] ?? 0);

        if ($totalChunks < 1 || $totalSize < 1 || $totalSize > self::MAX_TOTAL_BYTES) {
            throw new RuntimeException('The upload session is invalid.');
        }

        $chunks = is_array($metadata['chunks'] ?? null) ? $metadata['chunks'] : [];

        if (count($chunks) !== $totalChunks) {
            throw new RuntimeException('The upload session is missing one or more chunks.');
        }

        $assembledPath = $directory.DIRECTORY_SEPARATOR.'assembled.bin';
        $output = fopen($assembledPath, 'wb');

        if ($output === false) {
            throw new RuntimeException('The uploaded file could not be assembled.');
        }

        try {
            for ($index = 0; $index < $totalChunks; $index++) {
                $chunkPath = $directory.DIRECTORY_SEPARATOR.'chunks'.DIRECTORY_SEPARATOR.$index.'.part';
                $chunkMetadata = is_array($chunks[(string) $index] ?? null) ? $chunks[(string) $index] : [];

                if (! is_file($chunkPath)) {
                    throw new RuntimeException('The upload session is missing one or more chunks.');
                }

                if ((int) ($chunkMetadata['size'] ?? -1) !== filesize($chunkPath)) {
                    throw new RuntimeException('The uploaded file chunk size changed before assembly.');
                }

                if (! hash_equals((string) ($chunkMetadata['sha256'] ?? ''), hash_file('sha256', $chunkPath))) {
                    throw new RuntimeException('The uploaded file chunk failed integrity validation.');
                }

                $input = fopen($chunkPath, 'rb');

                if ($input === false) {
                    throw new RuntimeException('The uploaded file chunk could not be read.');
                }

                try {
                    stream_copy_to_stream($input, $output);
                } finally {
                    fclose($input);
                }
            }
        } finally {
            fclose($output);
        }

        $assembledSize = filesize($assembledPath);

        if ($assembledSize !== $totalSize) {
            File::delete($assembledPath);

            throw new RuntimeException('The uploaded file failed final size validation.');
        }

        return [
            'path' => $assembledPath,
            'filename' => (string) ($metadata['filename'] ?? 'upload.bin'),
            'mime_type' => (string) ($metadata['mime_type'] ?? 'application/octet-stream'),
            'size' => $assembledSize,
            'upload_id' => $uploadId,
        ];
    }

    public function cleanup(string $kind, string $ownerKey, string $uploadId): void
    {
        $directory = $this->sessionDirectory(
            $this->normalizeKind($kind),
            $this->normalizeOwnerKey($ownerKey),
            $this->normalizeUploadId($uploadId),
        );
        $root = realpath(storage_path('app/private/chunked-uploads'));
        $path = realpath($directory);

        if (! is_string($root) || ! is_string($path)) {
            return;
        }

        if (str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            File::deleteDirectory($path);
        }
    }

    private function sessionDirectory(string $kind, string $ownerKey, string $uploadId): string
    {
        return storage_path('app/private/chunked-uploads/'.$kind.'/'.$ownerKey.'/'.$uploadId);
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(string $directory): array
    {
        $path = $directory.DIRECTORY_SEPARATOR.'metadata.json';

        if (! is_file($path)) {
            return [];
        }

        $metadata = json_decode((string) file_get_contents($path), true);

        return is_array($metadata) ? $metadata : [];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function writeMetadata(string $directory, array $metadata): void
    {
        File::put(
            $directory.DIRECTORY_SEPARATOR.'metadata.json',
            json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
            true,
        );
    }

    private function normalizeKind(string $kind): string
    {
        if (! preg_match('/^[a-z0-9_-]{1,40}$/', $kind)) {
            throw new RuntimeException('The upload kind is invalid.');
        }

        return $kind;
    }

    private function normalizeOwnerKey(string $ownerKey): string
    {
        $ownerKey = preg_replace('/[^a-zA-Z0-9_-]/', '-', $ownerKey) ?? '';
        $ownerKey = trim($ownerKey, '-');

        if ($ownerKey === '') {
            throw new RuntimeException('The upload owner is invalid.');
        }

        return substr($ownerKey, 0, 80);
    }

    private function normalizeUploadId(string $uploadId): string
    {
        if (! preg_match('/^[a-zA-Z0-9_-]{8,80}$/', $uploadId)) {
            throw new RuntimeException('The upload session id is invalid.');
        }

        return $uploadId;
    }

    private function normalizeFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', trim($filename)));

        if ($filename === '' || $filename === '.' || $filename === '..') {
            return 'upload.bin';
        }

        return mb_substr($filename, 0, 180);
    }
}
