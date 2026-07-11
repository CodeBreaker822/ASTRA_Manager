<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
