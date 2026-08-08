<?php

namespace App\Jobs;

use App\Http\Controllers\Api\TranscriptionController\TranscriptionController;
use App\Models\ApiTranscriptionJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs an async transcription job on a queue worker.
 *
 * Without this the work only ever happened inside the browser status poll that
 * asked for progress, so a long transcription blocked the poll it was reported
 * through and a dropped request stranded the job in `processing` forever.
 */
class ProcessApiTranscriptionJob implements ShouldQueue
{
    use Queueable;

    // The controller claims the row automically, so a redelivery is a no-op
    // rather than a second transcription. One attempt keeps a genuine failure
    // out of a retry loop that would bill the same audio again.
    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(public string $transcriptionJobId) {}

    public function handle(TranscriptionController $api): void
    {
        $job = ApiTranscriptionJob::query()->find($this->transcriptionJobId);

        // Anything but `queued` means the row was already claimed - by an
        // earlier delivery of this job, or by the status poller falling back to
        // inline processing because no worker answered in time.
        if (! $job || $job->status !== 'queued') {
            return;
        }

        try {
            $api->processAsyncTranscriptionJob($job);
        } catch (Throwable $exception) {
            $this->markFailed($exception->getMessage());

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        // handle() already recorded the failure for a job it had claimed. This
        // only covers a job that failed before reaching the provider at all.
        $this->markFailed($exception?->getMessage() ?: 'Transcription job failed.');
    }

    private function markFailed(string $message): void
    {
        ApiTranscriptionJob::query()
            ->whereKey($this->transcriptionJobId)
            ->whereIn('status', ['queued', 'processing'])
            ->update([
                'status' => 'failed',
                'result_payload' => null,
                'error_message' => $message ?: 'Transcription job failed.',
                'status_code' => 500,
                'finished_at' => now(),
            ]);
    }
}
