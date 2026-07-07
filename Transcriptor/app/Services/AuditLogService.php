<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

class AuditLogService
{
    /**
     * Log an audit event.
     */
    public function log(array $data): AuditLog
    {
        $user = Auth::user();

        $logData = array_merge([
            'user_id' => $user?->id,
            'user_email' => $user?->email,
            'user_name' => $user?->name ?? ($user?->first_name.' '.$user?->last_name),
            'user_type' => $user ? get_class($user) : null,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'url' => Request::fullUrl(),
            'http_method' => Request::method(),
            'transaction_id' => $this->generateTransactionId(),
            'session_id' => session()->getId(),
            'severity' => 'low',
        ], $data);

        return AuditLog::create($this->normalizeLogData($logData));
    }

    /**
     * Keep audit payloads within database column limits.
     */
    protected function normalizeLogData(array $logData): array
    {
        $stringLimits = [
            'user_email' => 255,
            'user_name' => 255,
            'user_type' => 255,
            'event' => 255,
            'auditable_type' => 255,
            'ip_address' => 45,
            'user_agent' => 255,
            'url' => 255,
            'http_method' => 10,
            'transaction_id' => 255,
            'session_id' => 255,
            'module' => 255,
        ];

        foreach ($stringLimits as $key => $limit) {
            if (isset($logData[$key]) && is_string($logData[$key])) {
                $logData[$key] = Str::limit($logData[$key], $limit, '');
            }
        }

        return $logData;
    }

    /**
     * Log authentication events.
     */
    public function logAuth(string $event, ?int $userId = null, array $metadata = []): AuditLog
    {
        return $this->log([
            'event' => $event,
            'user_id' => $userId,
            'description' => $this->getAuthDescription($event),
            'severity' => $this->getAuthSeverity($event),
            'module' => 'Authentication',
            'metadata' => $metadata,
        ]);
    }

    /**
     * Log system events.
     */
    public function logSystem(string $event, string $description, string $severity = 'low', array $metadata = []): AuditLog
    {
        return $this->log([
            'event' => $event,
            'description' => $description,
            'severity' => $severity,
            'module' => 'System',
            'metadata' => $metadata,
        ]);
    }

    /**
     * Log security events.
     */
    public function logSecurity(string $event, string $description, array $metadata = []): AuditLog
    {
        return $this->log([
            'event' => $event,
            'description' => $description,
            'severity' => 'critical',
            'module' => 'Security',
            'metadata' => $metadata,
        ]);
    }

    /**
     * Log data access events.
     */
    public function logDataAccess(string $modelType, int $modelId, string $action = 'viewed', array $metadata = []): AuditLog
    {
        return $this->log([
            'event' => $action,
            'auditable_type' => $modelType,
            'auditable_id' => $modelId,
            'description' => "Accessed {$modelType} #{$modelId}",
            'severity' => 'low',
            'module' => 'Data Access',
            'metadata' => $metadata,
        ]);
    }

    /**
     * Log file operations.
     */
    public function logFileOperation(string $operation, string $filename, array $metadata = []): AuditLog
    {
        return $this->log([
            'event' => $operation,
            'description' => "File {$operation}: {$filename}",
            'severity' => $operation === 'deleted' ? 'high' : 'medium',
            'module' => 'File Management',
            'metadata' => array_merge(['filename' => $filename], $metadata),
        ]);
    }

    /**
     * Log configuration changes.
     */
    public function logConfigChange(string $key, $oldValue, $newValue, array $metadata = []): AuditLog
    {
        return $this->log([
            'event' => 'config_changed',
            'description' => "Configuration changed: {$key}",
            'old_values' => ['value' => $oldValue],
            'new_values' => ['value' => $newValue],
            'severity' => 'high',
            'module' => 'Configuration',
            'metadata' => array_merge(['config_key' => $key], $metadata),
        ]);
    }

    /**
     * Log permission changes.
     */
    public function logPermissionChange(string $action, array $details): AuditLog
    {
        return $this->log([
            'event' => $action,
            'description' => "Permission {$action}",
            'severity' => 'high',
            'module' => 'Permissions',
            'metadata' => $details,
        ]);
    }

    /**
     * Log report generation.
     */
    public function logReportGeneration(string $reportType, array $metadata = []): AuditLog
    {
        return $this->log([
            'event' => 'report_generated',
            'description' => "Generated {$reportType} report",
            'severity' => 'low',
            'module' => 'Reports',
            'metadata' => array_merge(['report_type' => $reportType], $metadata),
        ]);
    }

    /**
     * Log export operations.
     */
    public function logExport(string $dataType, int $recordCount, array $metadata = []): AuditLog
    {
        return $this->log([
            'event' => 'data_exported',
            'description' => "Exported {$recordCount} {$dataType} records",
            'severity' => 'medium',
            'module' => 'Data Export',
            'metadata' => array_merge([
                'data_type' => $dataType,
                'record_count' => $recordCount,
            ], $metadata),
        ]);
    }

    /**
     * Log import operations.
     */
    public function logImport(string $dataType, int $recordCount, array $metadata = []): AuditLog
    {
        return $this->log([
            'event' => 'data_imported',
            'description' => "Imported {$recordCount} {$dataType} records",
            'severity' => 'medium',
            'module' => 'Data Import',
            'metadata' => array_merge([
                'data_type' => $dataType,
                'record_count' => $recordCount,
            ], $metadata),
        ]);
    }

    /**
     * Generate a unique transaction ID.
     */
    protected function generateTransactionId(): string
    {
        return Str::uuid()->toString();
    }

    /**
     * Get authentication event description.
     */
    protected function getAuthDescription(string $event): string
    {
        return match ($event) {
            'login' => 'User logged in',
            'logout' => 'User logged out',
            'login_failed' => 'Failed login attempt',
            'password_reset' => 'Password was reset',
            'password_changed' => 'Password was changed',
            'email_verified' => 'Email was verified',
            'two_factor_enabled' => 'Two-factor authentication enabled',
            'two_factor_disabled' => 'Two-factor authentication disabled',
            default => "Authentication event: {$event}",
        };
    }

    /**
     * Get authentication event severity.
     */
    protected function getAuthSeverity(string $event): string
    {
        return match ($event) {
            'login_failed' => 'high',
            'password_reset', 'password_changed', 'two_factor_disabled' => 'medium',
            default => 'low',
        };
    }

    /**
     * Query audit logs with filters.
     */
    public function query(array $filters = [])
    {
        $query = AuditLog::query();

        if (isset($filters['user_id'])) {
            $query->byUser($filters['user_id']);
        }

        if (isset($filters['event'])) {
            $query->event($filters['event']);
        }

        if (isset($filters['severity'])) {
            $query->severity($filters['severity']);
        }

        if (isset($filters['module'])) {
            $query->module($filters['module']);
        }

        if (isset($filters['start_date']) && isset($filters['end_date'])) {
            $query->dateRange($filters['start_date'], $filters['end_date']);
        }

        if (isset($filters['ip_address'])) {
            $query->byIp($filters['ip_address']);
        }

        if (isset($filters['auditable_type'])) {
            $query->forModel($filters['auditable_type'], $filters['auditable_id'] ?? null);
        }

        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Get audit statistics.
     */
    public function getStatistics(array $filters = []): array
    {
        $query = $this->query($filters);

        return [
            'total_logs' => $query->count(),
            'by_severity' => $query->clone()->selectRaw('severity, COUNT(*) as count')
                ->groupBy('severity')
                ->pluck('count', 'severity')
                ->toArray(),
            'by_event' => $query->clone()->selectRaw('event, COUNT(*) as count')
                ->groupBy('event')
                ->orderByDesc('count')
                ->limit(10)
                ->pluck('count', 'event')
                ->toArray(),
            'by_module' => $query->clone()->selectRaw('module, COUNT(*) as count')
                ->groupBy('module')
                ->orderByDesc('count')
                ->pluck('count', 'module')
                ->toArray(),
        ];
    }
}
