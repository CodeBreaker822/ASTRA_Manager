<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    /**
     * Disable updated_at timestamp as logs are immutable
     */
    const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'user_email',
        'user_name',
        'user_type',
        'event',
        'auditable_type',
        'auditable_id',
        'ip_address',
        'user_agent',
        'url',
        'http_method',
        'old_values',
        'new_values',
        'metadata',
        'transaction_id',
        'session_id',
        'description',
        'severity',
        'module',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        // Prevent updates
        static::updating(function ($model) {
            throw new \Exception('Audit logs cannot be modified - COA Compliance');
        });

        // Prevent deletes
        static::deleting(function ($model) {
            throw new \Exception('Audit logs cannot be deleted - COA Compliance');
        });
    }

    /**
     * Get the user that performed the action.
     * Note: This may return null if user was deleted (which is intentional for COA compliance)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * Get the auditable model (polymorphic relationship)
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    /**
     * Scope to filter by event type
     */
    public function scopeEvent($query, string $event)
    {
        return $query->where('event', $event);
    }

    /**
     * Scope to filter by user
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to filter by date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Scope to filter by auditable model
     */
    public function scopeForModel($query, string $modelType, ?int $modelId = null)
    {
        $query->where('auditable_type', $modelType);

        if ($modelId !== null) {
            $query->where('auditable_id', $modelId);
        }

        return $query;
    }

    /**
     * Scope to filter by severity
     */
    public function scopeSeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    /**
     * Scope to filter by module
     */
    public function scopeModule($query, string $module)
    {
        return $query->where('module', $module);
    }

    /**
     * Scope to filter by IP address
     */
    public function scopeByIp($query, string $ip)
    {
        return $query->where('ip_address', $ip);
    }

    /**
     * Get human-readable changes
     */
    public function getChangesAttribute(): array
    {
        $changes = [];

        if ($this->old_values && $this->new_values) {
            foreach ($this->new_values as $key => $newValue) {
                $oldValue = $this->old_values[$key] ?? null;

                if ($oldValue !== $newValue) {
                    $changes[$key] = [
                        'old' => $oldValue,
                        'new' => $newValue,
                    ];
                }
            }
        }

        return $changes;
    }

    /**
     * Get formatted log entry for display
     */
    public function getFormattedLogAttribute(): string
    {
        $user = $this->user_name ?? $this->user_email ?? 'System';
        $action = ucfirst($this->event);
        $model = class_basename($this->auditable_type ?? 'Unknown');

        return "[{$this->created_at}] {$user} {$action} {$model} #{$this->auditable_id}";
    }
}
