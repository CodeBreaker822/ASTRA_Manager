<?php

namespace App\Jobs;

use App\Http\Controllers\Api\TranscriptionController;
use App\Models\ApiTranscriptionJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessAsyncTranscriptionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [5, 15, 30];

    public int $timeout = 0;

    public function __construct(
        public string $transcriptionJobId,
    ) {}

    public function handle(TranscriptionController $controller): void
    {
        $job = ApiTranscriptionJob::query()->find($this->transcriptionJobId);

        if (! $job || $job->status !== 'queued') {
            return;
        }

        $controller->processAsyncTranscriptionJob($job);
    }

    public function failed(?Throwable $exception): void
    {
        $job = ApiTranscriptionJob::query()->find($this->transcriptionJobId);

        if (! $job) {
            return;
        }

        $payload = is_array($job->request_payload) ? $job->request_payload : [];

        foreach (array_values(array_filter($payload['clips'] ?? [], 'is_array')) as $clip) {
            $path = (string) ($clip['audio_path'] ?? '');

            if ($path !== '') {
                Storage::disk('local')->delete($path);
            }
        }

        $job->forceFill([
            'status' => 'failed',
            'error_message' => $exception?->getMessage() ?: 'Transcription job failed.',
            'status_code' => 500,
            'finished_at' => now(),
        ])->save();
    }
}
