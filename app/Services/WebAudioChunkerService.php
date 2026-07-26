<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

class WebAudioChunkerService
{
    private const CHUNK_SECONDS = 60;

    private const CHUNK_MS = self::CHUNK_SECONDS * 1000;

    private const MIN_CHUNK_MS = 1000;

    /**
     * @return array{clips: array<int, array<string, mixed>>, cleanup: string|null}
     */
    public function clipsFromUpload(UploadedFile $file, int $durationSeconds): array
    {
        $sourcePath = $file->getRealPath();

        if (! is_string($sourcePath) || ! is_file($sourcePath)) {
            Log::error('Audio upload source file is not readable.', [
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'real_path' => $sourcePath,
            ]);

            throw new RuntimeException('Audio upload could not be processed.');
        }

        $durationMs = $this->audioDurationMs($sourcePath);

        if ($durationMs <= self::CHUNK_MS) {
            return [
                'clips' => [[
                    'audio' => $file,
                    'clip_index' => 0,
                    'clip_start_ms' => 0,
                    'clip_end_ms' => max(0, $durationMs),
                    'language_code' => null,
                ]],
                'cleanup' => null,
            ];
        }

        $directory = storage_path('app/private/web-upload-chunks/'.uniqid('web-', true));
        File::ensureDirectoryExists($directory);

        $clips = [];
        $ranges = $this->chunkRanges($durationMs);

        foreach ($ranges as $index => [$startMs, $endMs]) {
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
            $this->ffmpegBinary(),
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

        try {
            $process->run();
        } catch (ProcessRuntimeException $exception) {
            Log::error('FFmpeg chunk process could not start.', [
                'binary' => $this->ffmpegBinary(),
                'source_path' => $sourcePath,
                'source_size' => is_file($sourcePath) ? filesize($sourcePath) : null,
                'output_path' => $outputPath,
                'start_ms' => $startMs,
                'duration_ms' => $durationMs,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Audio upload could not be processed because FFmpeg is not available.', 0, $exception);
        }

        if (! $process->isSuccessful() || ! is_file($outputPath)) {
            Log::error('FFmpeg chunk process failed.', [
                'binary' => $this->ffmpegBinary(),
                'exit_code' => $process->getExitCode(),
                'source_path' => $sourcePath,
                'source_size' => is_file($sourcePath) ? filesize($sourcePath) : null,
                'output_path' => $outputPath,
                'output_exists' => is_file($outputPath),
                'start_ms' => $startMs,
                'duration_ms' => $durationMs,
                'stderr' => $this->logSnippet($process->getErrorOutput()),
                'stdout' => $this->logSnippet($process->getOutput()),
            ]);

            throw new RuntimeException('Audio upload could not be processed.');
        }
    }

    /**
     * @return array<int, array{0: int, 1: int}>
     */
    private function chunkRanges(int $durationMs): array
    {
        $ranges = [];

        for ($startMs = 0; $startMs < $durationMs; $startMs += self::CHUNK_MS) {
            $endMs = min($durationMs, $startMs + self::CHUNK_MS);

            if (($endMs - $startMs) < self::MIN_CHUNK_MS && $ranges !== []) {
                $ranges[array_key_last($ranges)][1] = $endMs;

                continue;
            }

            $ranges[] = [$startMs, $endMs];
        }

        return $ranges;
    }

    private function audioDurationMs(string $sourcePath): int
    {
        $process = new Process([
            $this->ffmpegBinary(),
            '-hide_banner',
            '-i',
            $sourcePath,
        ]);
        $process->setTimeout(30);

        try {
            $process->run();
        } catch (ProcessRuntimeException $exception) {
            Log::error('FFmpeg duration probe could not start.', [
                'binary' => $this->ffmpegBinary(),
                'source_path' => $sourcePath,
                'source_size' => is_file($sourcePath) ? filesize($sourcePath) : null,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Audio upload could not be processed because FFmpeg is not available.', 0, $exception);
        }

        $output = $process->getErrorOutput()."\n".$process->getOutput();

        if (! preg_match('/Duration:\s*(\d{2}):(\d{2}):(\d{2}(?:\.\d+)?)/', $output, $matches)) {
            Log::error('FFmpeg duration probe did not return a readable duration.', [
                'binary' => $this->ffmpegBinary(),
                'exit_code' => $process->getExitCode(),
                'source_path' => $sourcePath,
                'source_size' => is_file($sourcePath) ? filesize($sourcePath) : null,
                'stderr' => $this->logSnippet($process->getErrorOutput()),
                'stdout' => $this->logSnippet($process->getOutput()),
            ]);

            throw new RuntimeException('Audio upload could not be processed.');
        }

        $seconds = ((int) $matches[1] * 3600)
            + ((int) $matches[2] * 60)
            + (float) $matches[3];

        return max(1, (int) ceil($seconds * 1000));
    }

    private function ffmpegBinary(): string
    {
        $binary = config('services.ffmpeg.binary', 'ffmpeg');

        return is_string($binary) && trim($binary) !== '' ? trim($binary) : 'ffmpeg';
    }

    private function logSnippet(string $value): string
    {
        return mb_substr(trim($value), 0, 4000);
    }
}
