<?php

namespace App\Jobs;

use App\Models\Transcript;
use App\Services\WebTranscriptProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessWebTranscriptJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [5, 15, 30];

    public int $timeout = 0;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public int $transcriptId,
        public array $options = [],
    ) {}

    public function handle(WebTranscriptProcessor $processor): void
    {
        $transcript = Transcript::query()->find($this->transcriptId);

        if (! $transcript || ! in_array($transcript->status, ['queued', 'failed'], true)) {
            return;
        }

        $processor->transcribe($transcript, $this->options);
    }

    public function failed(): void
    {
        $transcript = Transcript::query()->find($this->transcriptId);

        if (! $transcript) {
            return;
        }

        app(WebTranscriptProcessor::class)->appendLog(
            $transcript,
            'failed',
            'Audio upload could not be processed.',
        );
    }
}
