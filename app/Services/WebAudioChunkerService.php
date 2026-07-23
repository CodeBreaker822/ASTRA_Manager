<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

class WebAudioChunkerService
{
    private const CHUNK_SECONDS = 60;

    /**
     * @return array{clips: array<int, array<string, mixed>>, cleanup: string|null}
     */
    public function clipsFromUpload(UploadedFile $file, int $durationSeconds): array
    {
        if ($durationSeconds <= self::CHUNK_SECONDS) {
            return [
                'clips' => [[
                    'audio' => $file,
                    'clip_index' => 0,
                    'clip_start_ms' => 0,
                    'clip_end_ms' => max(0, $durationSeconds * 1000),
                    'language_code' => null,
                ]],
                'cleanup' => null,
            ];
        }

        $sourcePath = $file->getRealPath();

        if (! is_string($sourcePath) || ! is_file($sourcePath)) {
            throw new RuntimeException('Audio upload could not be processed.');
        }

        $directory = storage_path('app/private/web-upload-chunks/'.uniqid('web-', true));
        File::ensureDirectoryExists($directory);

        $clips = [];

        for ($startMs = 0, $index = 0; $startMs < ($durationSeconds * 1000); $startMs += self::CHUNK_SECONDS * 1000, $index++) {
            $endMs = min($durationSeconds * 1000, $startMs + self::CHUNK_SECONDS * 1000);
            $durationMs = max(1, $endMs - $startMs);
            $outputPath = $directory.DIRECTORY_SEPARATOR.sprintf('chunk_%05d.wav', $index + 1);

            $this->runFfmpeg($sourcePath, $outputPath, $startMs, $durationMs);

            $clips[] = [
                'audio' => new UploadedFile($outputPath, basename($outputPath), 'audio/wav', null, true),
                'clip_index' => $index,
                'clip_start_ms' => $startMs,
                'clip_end_ms' => $endMs,
                'language_code' => null,
            ];
        }

        return [
            'clips' => $clips,
            'cleanup' => $directory,
        ];
    }

    public function cleanup(?string $directory): void
    {
        if (! is_string($directory) || $directory === '') {
            return;
        }

        $root = realpath(storage_path('app/private/web-upload-chunks'));
        $path = realpath($directory);

        if (! is_string($root) || ! is_string($path)) {
            return;
        }

        if (str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            File::deleteDirectory($path);
        }
    }

    private function runFfmpeg(string $sourcePath, string $outputPath, int $startMs, int $durationMs): void
    {
        $process = new Process([
            base_path('ffmpeg/bin/ffmpeg.exe'),
            '-y',
            '-ss',
            sprintf('%.3f', $startMs / 1000),
            '-t',
            sprintf('%.3f', $durationMs / 1000),
            '-i',
            $sourcePath,
            '-vn',
            '-ac',
            '1',
            '-ar',
            '16000',
            '-c:a',
            'pcm_s16le',
            $outputPath,
        ]);
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($outputPath)) {
            throw new RuntimeException('Audio upload could not be processed.');
        }
    }
}
