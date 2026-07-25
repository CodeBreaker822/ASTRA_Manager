<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingOperation extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'api_id',
        'feature',
        'status',
        'idempotency_key',
        'subject_type',
        'subject_id',
        'requested_units',
        'free_units',
        'paid_units',
        'rate_nanos',
        'authorized_amount_nanos',
        'captured_amount_nanos',
        'currency',
        'authorization_attempts',
        'metadata',
        'result_payload',
        'authorized_at',
        'captured_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_units' => 'integer',
            'free_units' => 'integer',
            'paid_units' => 'integer',
            'rate_nanos' => 'integer',
            'authorized_amount_nanos' => 'integer',
            'captured_amount_nanos' => 'integer',
            'authorization_attempts' => 'integer',
            'metadata' => 'array',
            'result_payload' => 'array',
            'authorized_at' => 'datetime',
            'captured_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function api(): BelongsTo
    {
        return $this->belongsTo(API::class, 'api_id');
    }
}