<?php

namespace App\Console\Commands;

use App\Models\TranscriptionApiRequestLog;
use Illuminate\Console\Command;

class PruneTranscriptionApiRequestLogs extends Command
{
    protected $signature = 'transcription-api-logs:prune
        {--success-days=30 : Delete successful request logs older than this many days}
        {--noncritical-days=90 : Delete non-critical failed request logs older than this many days}
        {--critical-days=365 : Delete critical request logs older than this many days}
        {--dry-run : Show how many rows would be deleted without deleting them}';

    protected $description = 'Prune old transcription API request logs while keeping critical security logs longer';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $successDays = max(1, (int) $this->option('success-days'));
        $noncriticalDays = max(1, (int) $this->option('noncritical-days'));
        $criticalDays = max(1, (int) $this->option('critical-days'));

        $plans = [
            'successful logs' => TranscriptionApiRequestLog::query()
                ->where('status', 'success')
                ->where('created_at', '<', now()->subDays($successDays)),
            'non-critical failed logs' => TranscriptionApiRequestLog::query()
                ->where('status', '!=', 'success')
                ->where('severity', '!=', 'critical')
                ->where('created_at', '<', now()->subDays($noncriticalDays)),
            'critical logs' => TranscriptionApiRequestLog::query()
                ->where('severity', 'critical')
                ->where('created_at', '<', now()->subDays($criticalDays)),
        ];

        foreach ($plans as $label => $query) {
            $count = (clone $query)->count();

            if (! $dryRun && $count > 0) {
                (clone $query)->delete();
            }

            $this->line(($dryRun ? 'Would delete ' : 'Deleted ').$count.' '.$label.'.');
        }

        return self::SUCCESS;
    }
}
