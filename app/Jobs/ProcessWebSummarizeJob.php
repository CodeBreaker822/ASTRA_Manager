<?php

namespace App\Jobs;

use App\Models\Transcript;
use App\Services\WebTranscriptProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessWebSummarizeJob implements ShouldQueue
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
        public string $source,
    ) {}

    public function handle(WebTranscriptProcessor $processor): void
    {
        $transcript = Transcript::query()->find($this->transcriptId);

        if (! $transcript || $transcript->summary_status !== 'processing') {
            return;
        }

        $processor->summarize($transcript, $this->source);
    }

    public function failed(): void
    {
        $transcript = Transcript::query()->find($this->transcriptId);

        if (! $transcript) {
            return;
        }

        $message = 'The transcript could not be summarized.';

        $transcript->forceFill([
            'summary_status' => 'failed',
            'summary_error_message' => $message,
        ])->save();

        app(WebTranscriptProcessor::class)->appendLog($transcript, 'summary_failed', $message);
    }
}
