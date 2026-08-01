<?php

namespace App\Jobs;

use App\Models\ApiTranscriptionJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAsyncTranscriptionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 3600;

    public int $maxExceptions = 1;

    public int $uniqueFor = 86400;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $jobId,
    ) {
        $this->queue = 'default';
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $job = ApiTranscriptionJob::query()->find($this->jobId);

        if (! $job) {
            Log::error('Transcription job not found for async processing.', [
                'job_id' => $this->jobId,
            ]);

            return;
        }

        // Delegate to controller method that does the actual processing
        app('App\Http\Controllers\Api\TranscriptionController')
            ->processAsyncTranscriptionJob($job);
    }

    public function uniqueId(): string
    {
        return $this->jobId;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $job = ApiTranscriptionJob::query()->find($this->jobId);

        if (! $job) {
            return;
        }

        Log::error('Async transcription job processing failed.', [
            'job_id' => $job->id,
            'attempt' => $this->attempts(),
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);

        $job->forceFill([
            'status' => 'failed',
            'error_message' => 'Background job processing failed: '.$exception->getMessage(),
            'status_code' => 500,
            'finished_at' => now(),
        ])->save();
    }
}
