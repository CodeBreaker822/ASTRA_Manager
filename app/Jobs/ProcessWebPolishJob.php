<?php

namespace App\Jobs;

use App\Models\Transcript;
use App\Services\WebTranscriptProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessWebPolishJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [5, 15, 30];

    public int $timeout = 0;

    public function __construct(
        public int $transcriptId,
        public string $instruction,
    ) {}

    public function handle(WebTranscriptProcessor $processor): void
    {
        $transcript = Transcript::query()->find($this->transcriptId);

        if (! $transcript || $transcript->polish_status !== 'processing') {
            return;
        }

        $processor->polish($transcript, $this->instruction);
    }

    public function failed(?Throwable $exception): void
    {
        $transcript = Transcript::query()->find($this->transcriptId);

        if (! $transcript) {
            return;
        }

        $message = $exception?->getMessage() ?: 'Transcript could not be polished.';

        $transcript->forceFill([
            'polish_status' => 'failed',
            'polish_error_message' => $message,
        ])->save();

        app(WebTranscriptProcessor::class)->appendLog($transcript, 'polish_failed', $message);
    }
}
