<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $api_id
 * @property string $status
 * @property array<string, mixed>|null $request_payload
 * @property array<string, mixed>|null $result_payload
 * @property string|null $billing_feature
 * @property int $billing_seconds
 * @property Carbon|null $billed_at
 * @property string|null $error_message
 * @property int|null $status_code
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ApiTranscriptionJob extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'api_id',
        'status',
        'request_payload',
        'result_payload',
        'billing_feature',
        'billing_seconds',
        'billed_at',
        'error_message',
        'status_code',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'result_payload' => 'array',
            'billing_seconds' => 'integer',
            'billed_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
