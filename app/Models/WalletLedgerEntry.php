<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WalletLedgerEntry extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'billing_operation_id',
        'billing_transaction_id',
        'direction',
        'type',
        'amount_nanos',
        'balance_after_nanos',
        'currency',
        'idempotency_key',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount_nanos' => 'integer',
            'balance_after_nanos' => 'integer',
            'metadata' => 'array',
        ];
    }
}