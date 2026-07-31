<?php

use App\Services\ChunkedUploadService;
use Illuminate\Http\UploadedFile;

function chunkedUploadTempFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'chunked-upload-');
    file_put_contents($path, $contents);

    return $path;
}

test('chunked upload service assembles chunks without corrupting bytes', function () {
    $service = app(ChunkedUploadService::class);
    $uploadId = 'integrity-test-'.bin2hex(random_bytes(4));
    $owner = 'test-owner';
    $first = chunkedUploadTempFile('hello ');
    $second = chunkedUploadTempFile('world');

    try {
        $service->storeChunk(
            'test',
            $owner,
            $uploadId,
            new UploadedFile($first, 'part-0.bin', 'application/octet-stream', null, true),
            0,
            2,
            11,
            'audio.wav',
            'audio/wav',
            hash_file('sha256', $first),
        );
        $service->storeChunk(
            'test',
            $owner,
            $uploadId,
            new UploadedFile($second, 'part-1.bin', 'application/octet-stream', null, true),
            1,
            2,
            11,
            'audio.wav',
            'audio/wav',
            hash_file('sha256', $second),
        );

        $assembled = $service->assemble('test', $owner, $uploadId);

        expect(file_get_contents($assembled['path']))->toBe('hello world')
            ->and($assembled['size'])->toBe(11)
            ->and($assembled['filename'])->toBe('audio.wav');
    } finally {
        @unlink($first);
        @unlink($second);
        $service->cleanup('test', $owner, $uploadId);
    }
});

test('chunked upload service rejects corrupted chunks', function () {
    $path = chunkedUploadTempFile('tampered');

    try {
        app(ChunkedUploadService::class)->storeChunk(
            'test',
            'test-owner',
            'hash-test-'.bin2hex(random_bytes(4)),
            new UploadedFile($path, 'part.bin', 'application/octet-stream', null, true),
            0,
            1,
            8,
            'audio.wav',
            'audio/wav',
            str_repeat('0', 64),
        );
    } finally {
        @unlink($path);
    }
})->throws(RuntimeException::class, 'failed integrity validation');

test('chunked upload service rejects totals over five hundred megabytes', function () {
    $path = chunkedUploadTempFile('small');

    try {
        app(ChunkedUploadService::class)->storeChunk(
            'test',
            'test-owner',
            'size-test-'.bin2hex(random_bytes(4)),
            new UploadedFile($path, 'part.bin', 'application/octet-stream', null, true),
            0,
            1,
            ChunkedUploadService::MAX_TOTAL_BYTES + 1,
            'audio.wav',
            'audio/wav',
            hash_file('sha256', $path),
        );
    } finally {
        @unlink($path);
    }
})->throws(RuntimeException::class, 'must not exceed 500 MB');
