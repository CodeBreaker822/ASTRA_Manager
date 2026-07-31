<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function transcriberPackageZip(string $version, ?string $path = null): string
{
    $path ??= tempnam(sys_get_temp_dir(), 'transcriber-package-');
    $filename = 'version.json';
    $contents = json_encode(['version' => $version], JSON_THROW_ON_ERROR).PHP_EOL;
    $compressed = gzdeflate($contents);
    $crc = hexdec(hash('crc32b', $contents));
    $localHeaderOffset = 0;

    $localHeader = pack('VvvvvvVVVvv', 0x04034B50, 20, 0, 8, 0, 0, $crc, strlen($compressed), strlen($contents), strlen($filename), 0)
        .$filename
        .$compressed;

    $centralDirectoryOffset = strlen($localHeader);
    $centralDirectory = pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0, 8, 0, 0, $crc, strlen($compressed), strlen($contents), strlen($filename), 0, 0, 0, 0, 0, $localHeaderOffset)
        .$filename;
    $centralDirectorySize = strlen($centralDirectory);

    $end = pack('VvvvvVVv', 0x06054B50, 0, 0, 1, 1, $centralDirectorySize, $centralDirectoryOffset, 0);

    file_put_contents($path, $localHeader.$centralDirectory.$end);

    return $path;
}

function transcriberPackageUser(): User
{
    config([
        'admin.access' => true,
        'admin.email' => 'admin@example.test',
    ]);

    return User::factory()->create([
        'email' => 'admin@example.test',
    ]);
}

test('transcriber package upload publishes matching embedded version', function () {
    Storage::fake('local');

    $zipPath = transcriberPackageZip('5.0.1');

    $response = $this
        ->actingAs(transcriberPackageUser())
        ->post(route('api.transcriber-package.upload'), [
            'version' => '5.0.1',
            'package' => new UploadedFile($zipPath, 'package.zip', 'application/zip', null, true),
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('version', '5.0.1')
        ->assertJsonPath('zipfile', 'standalone-transcriber-5.0.1.zip');

    Storage::disk('local')->assertExists('transcriber/version.json');
    Storage::disk('local')->assertExists('transcriber/standalone-transcriber-5.0.1.zip');
});

test('transcriber package upload rejects mismatched embedded version', function () {
    Storage::fake('local');

    $zipPath = transcriberPackageZip('5.0.0-Optimized');

    $response = $this
        ->actingAs(transcriberPackageUser())
        ->post(route('api.transcriber-package.upload'), [
            'version' => '5.0.1',
            'package' => new UploadedFile($zipPath, 'package.zip', 'application/zip', null, true),
        ]);

    $response
        ->assertStatus(500)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'does not match the published version'));

    Storage::disk('local')->assertMissing('transcriber/version.json');
    Storage::disk('local')->assertMissing('transcriber/standalone-transcriber-5.0.1.zip');
});

test('chunked transcriber package upload publishes matching embedded version', function () {
    Storage::fake('local');

    $zipPath = transcriberPackageZip('5.0.2');
    $contents = file_get_contents($zipPath);
    $first = substr($contents, 0, 10);
    $second = substr($contents, 10);
    $firstPath = tempnam(sys_get_temp_dir(), 'transcriber-package-part-a-');
    $secondPath = tempnam(sys_get_temp_dir(), 'transcriber-package-part-b-');
    file_put_contents($firstPath, $first);
    file_put_contents($secondPath, $second);
    $uploadId = 'package-upload-'.bin2hex(random_bytes(4));
    $user = transcriberPackageUser();

    try {
        $this
            ->actingAs($user)
            ->postJson(route('api.transcriber-package.chunk'), [
                'upload_id' => $uploadId,
                'chunk_index' => 0,
                'total_chunks' => 2,
                'total_size' => strlen($contents),
                'filename' => 'package.zip',
                'mime_type' => 'application/zip',
                'chunk_hash' => hash_file('sha256', $firstPath),
                'chunk' => new UploadedFile($firstPath, 'package.part0', 'application/octet-stream', null, true),
            ])
            ->assertOk()
            ->assertJsonPath('received_chunks', 1);

        $this
            ->actingAs($user)
            ->postJson(route('api.transcriber-package.chunk'), [
                'upload_id' => $uploadId,
                'chunk_index' => 1,
                'total_chunks' => 2,
                'total_size' => strlen($contents),
                'filename' => 'package.zip',
                'mime_type' => 'application/zip',
                'chunk_hash' => hash_file('sha256', $secondPath),
                'chunk' => new UploadedFile($secondPath, 'package.part1', 'application/octet-stream', null, true),
            ])
            ->assertOk()
            ->assertJsonPath('complete', true);

        $this
            ->actingAs($user)
            ->postJson(route('api.transcriber-package.complete'), [
                'upload_id' => $uploadId,
                'version' => '5.0.2',
            ])
            ->assertOk()
            ->assertJsonPath('version', '5.0.2')
            ->assertJsonPath('zipfile', 'standalone-transcriber-5.0.2.zip');
    } finally {
        @unlink($zipPath);
        @unlink($firstPath);
        @unlink($secondPath);
    }

    Storage::disk('local')->assertExists('transcriber/version.json');
    Storage::disk('local')->assertExists('transcriber/standalone-transcriber-5.0.2.zip');
});
