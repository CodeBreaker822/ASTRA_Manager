<?php

namespace App\Services\Transcription;

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

    /** @var array{binary: string, source: string}|null */
    private ?array $selectedFfmpeg = null;

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
        $process = $this->runFfmpegProcess([
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
        ], 120, 'chunk', [
            'source_path' => $sourcePath,
            'source_size' => is_file($sourcePath) ? filesize($sourcePath) : null,
            'output_path' => $outputPath,
            'start_ms' => $startMs,
            'duration_ms' => $durationMs,
        ]);

        if (! $process->isSuccessful() || ! is_file($outputPath)) {
            Log::error('FFmpeg chunk process failed.', [
                ...$this->selectedFfmpegLogContext(),
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

        Log::info('FFmpeg chunk process succeeded.', [
            ...$this->selectedFfmpegLogContext(),
            'output_path' => $outputPath,
            'output_size' => filesize($outputPath),
            'start_ms' => $startMs,
            'duration_ms' => $durationMs,
        ]);
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
        $process = $this->runFfmpegProcess([
            '-hide_banner',
            '-i',
            $sourcePath,
        ], 30, 'duration_probe', [
            'source_path' => $sourcePath,
            'source_size' => is_file($sourcePath) ? filesize($sourcePath) : null,
        ]);

        $output = $process->getErrorOutput()."\n".$process->getOutput();

        if (! preg_match('/Duration:\s*(\d{2}):(\d{2}):(\d{2}(?:\.\d+)?)/', $output, $matches)) {
            Log::error('FFmpeg duration probe did not return a readable duration.', [
                ...$this->selectedFfmpegLogContext(),
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

        $durationMs = max(1, (int) ceil($seconds * 1000));

        Log::info('FFmpeg duration probe succeeded.', [
            ...$this->selectedFfmpegLogContext(),
            'source_path' => $sourcePath,
            'duration_ms' => $durationMs,
        ]);

        return $durationMs;
    }

    private function ffmpegBinary(): string
    {
        $binary = config('services.ffmpeg.binary', 'ffmpeg');

        return is_string($binary) && trim($binary) !== '' ? trim($binary) : 'ffmpeg';
    }

    /**
     * @param  array<int, string>  $arguments
     * @param  array<string, mixed>  $context
     */
    private function runFfmpegProcess(array $arguments, int $timeout, string $operation, array $context): Process
    {
        $candidate = $this->selectFfmpeg();

        Log::info('Starting FFmpeg process.', [
            'operation' => $operation,
            ...$candidate,
            ...$context,
        ]);

        $process = new Process([$candidate['binary'], ...$arguments]);
        $process->setTimeout($timeout);

        try {
            $process->run();
        } catch (ProcessRuntimeException $exception) {
            Log::error('Selected FFmpeg process could not start.', [
                'operation' => $operation,
                ...$candidate,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                ...$context,
            ]);

            throw new RuntimeException('Audio upload could not be processed because FFmpeg is not available.', 0, $exception);
        }

        return $process;
    }

    /** @return array{binary: string, source: string} */
    private function selectFfmpeg(): array
    {
        if ($this->selectedFfmpeg !== null) {
            return $this->selectedFfmpeg;
        }

        $candidates = $this->ffmpegCandidates();

        foreach ($candidates as $index => $candidate) {
            $process = new Process([$candidate['binary'], '-version']);
            $process->setTimeout(5);
            $exception = null;

            try {
                $process->run();
            } catch (ProcessRuntimeException $caught) {
                $exception = $caught;
            }

            if ($exception === null && $process->isSuccessful()) {
                $this->selectedFfmpeg = $candidate;

                Log::info('FFmpeg binary selected.', [
                    ...$candidate,
                    'version' => $this->firstLogLine($process->getOutput()),
                ]);

                return $candidate;
            }

            $willFallback = isset($candidates[$index + 1]);
            $message = $willFallback
                ? 'FFmpeg binary is unavailable; trying the local Windows fallback.'
                : 'FFmpeg binary is unavailable and no further fallback is available.';
            $logContext = [
                ...$candidate,
                'will_fallback' => $willFallback,
                'exit_code' => $process->getExitCode(),
                'platform' => PHP_OS_FAMILY,
                'configured_fallback_binary' => config('services.ffmpeg.fallback_binary'),
                'exception' => $exception !== null ? $exception::class : null,
                'message' => $exception?->getMessage(),
                'stderr' => $this->logSnippet($process->getErrorOutput()),
            ];

            if ($willFallback) {
                Log::warning($message, $logContext);

                continue;
            }

            Log::error($message, $logContext);

            throw new RuntimeException('Audio upload could not be processed because FFmpeg is not available.', 0, $exception);
        }

        throw new RuntimeException('Audio upload could not be processed because FFmpeg is not available.');
    }

    /**
     * @return array<int, array{binary: string, source: string}>
     */
    private function ffmpegCandidates(): array
    {
        $configured = $this->ffmpegBinary();
        $candidates = [[
            'binary' => $configured,
            'source' => 'configured',
        ]];
        $fallback = $this->ffmpegFallbackBinary();

        if ($fallback !== null && strcasecmp($configured, $fallback) !== 0) {
            $candidates[] = [
                'binary' => $fallback,
                'source' => 'local_windows_fallback',
            ];
        }

        return $candidates;
    }

    private function ffmpegFallbackBinary(): ?string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return null;
        }

        $binary = config('services.ffmpeg.fallback_binary');

        if (! is_string($binary) || trim($binary) === '') {
            return null;
        }

        $binary = trim($binary);

        return is_file($binary) ? $binary : null;
    }

    /** @return array{binary: string|null, source: string|null} */
    private function selectedFfmpegLogContext(): array
    {
        return [
            'binary' => $this->selectedFfmpeg['binary'] ?? null,
            'source' => $this->selectedFfmpeg['source'] ?? null,
        ];
    }

    private function logSnippet(string $value): string
    {
        return mb_substr(trim($value), 0, 4000);
    }

    private function firstLogLine(string $value): string
    {
        return trim((string) strtok($value, "\r\n"));
    }
}
