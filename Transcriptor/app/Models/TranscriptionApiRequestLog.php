<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranscriptionApiRequestLog extends Model
{
    protected $fillable = [
        'request_id',
        'api_id',
        'app_name',
        'license_token_prefix',
        'license_token_hash',
        'operation',
        'endpoint',
        'http_method',
        'status',
        'severity',
        'http_status',
        'provider',
        'model',
        'language_code',
        'clip_index',
        'clip_start_ms',
        'clip_end_ms',
        'audio_file_name',
        'audio_mime_type',
        'audio_size_bytes',
        'ip_address',
        'user_agent',
        'duration_ms',
        'request_summary',
        'response_summary',
        'error_message',
    ];

    protected $casts = [
        'request_summary' => 'array',
        'response_summary' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function license(): BelongsTo
    {
        return $this->belongsTo(API::class, 'api_id');
    }
}
