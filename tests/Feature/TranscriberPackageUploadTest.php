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
