<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $plan
 * @property string $reference
 * @property string|null $checkout_session_id
 * @property string|null $payment_id
 * @property string $status
 * @property int $amount
 * @property string $currency
 * @property string|null $checkout_url
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $paid_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'provider',
    'plan',
    'reference',
    'checkout_session_id',
    'payment_id',
    'status',
    'amount',
    'currency',
    'checkout_url',
    'payload',
    'paid_at',
])]
class BillingTransaction extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'payload' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
