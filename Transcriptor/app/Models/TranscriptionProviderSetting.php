<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TranscriptionProviderSetting extends Model
{
    protected $fillable = [
        'provider',
        'api_key',
        'model',
        'is_enabled',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'api_key' => 'encrypted',
        'is_enabled' => 'boolean',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];
}
