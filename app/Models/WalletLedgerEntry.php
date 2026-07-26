<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Wallet ledger entry - immutable audit trail (USD-based)
 *
 * Every wallet transaction creates exactly one ledger entry.
 * The ledger is the source of truth for reconciling balances
 * and investigating discrepancies.
 *
 * @property int $id
 * @property int $user_id
 * @property int $user_wallet_id
 * @property string $type Transaction type (credit, debit, adjustment)
 * @property string $description Description of the transaction
 * @property int $amount_cents Amount in USD cents (positive for credit, negative for debit)
 * @property int $balance_cents Wallet balance after this transaction in USD cents
 * @property string|null $reference_type Type of the reference
 * @property int|null $reference_id ID of the reference
 * @property string|null $operation_key Idempotency key
 * @property array|null $metadata Additional metadata
 * @property Carbon $created_at
 */
class WalletLedgerEntry extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'user_wallet_id',
        'type',
        'description',
        'amount_cents',
        'balance_cents',
        'reference_type',
        'reference_id',
        'operation_key',
        'metadata',
    ];

    /**
     * The attributes should not be cast.
     *
     * @var array<int, string>
     */
    protected $casts = [
        'amount_cents' => 'integer',
        'balance_cents' => 'integer',
        'metadata' => 'array',
    ];

    /**
     * The attributes that should be hidden.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<UserWallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(UserWallet::class);
    }

    /**
     * Check if this is a credit transaction
     *
     * @return bool
     */
    public function isCredit(): bool
    {
        return $this->type === 'credit' || $this->amount_cents > 0;
    }

    /**
     * Check if this is a debit transaction
     *
     * @return bool
     */
    public function isDebit(): bool
    {
        return $this->type === 'debit' || $this->amount_cents < 0;
    }

    /**
     * Get amount in dollars
     *
     * @return float
     */
    public function getAmountDollarsAttribute(): float
    {
        return $this->amount_cents / 100.0;
    }

    /**
     * Get balance in dollars
     *
     * @return float
     */
    public function getBalanceDollarsAttribute(): float
    {
        return $this->balance_cents / 100.0;
    }

    /**
     * Scope query for credits
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCredits($query)
    {
        return $query->where('type', 'credit')->orWhere('amount_cents', '>', 0);
    }

    /**
     * Scope query for debits
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDebits($query)
    {
        return $query->where('type', 'debit')->orWhere('amount_cents', '<', 0);
    }

    /**
     * Scope query by reference type
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $type Reference type
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeReferenceType($query, string $type)
    {
        return $query->where('reference_type', $type);
    }

    /**
     * Scope query by reference ID
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $id Reference ID
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeReferenceId($query, int $id)
    {
        return $query->where('reference_id', $id);
    }

    /**
     * Scope query by operation key
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $key Operation key
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOperationKey($query, string $key)
    {
        return $query->where('operation_key', $key);
    }

    /**
     * Get transaction string representation
     *
     * @return string
     */
    public function getTransactionStringAttribute(): string
    {
        $typeSymbol = $this->isCredit() ? '+' : '-';
        $amount = abs($this->amount_cents);
        $dollars = $amount / 100.0;

        return sprintf('%s $%.2f (%s)', $typeSymbol, $dollars, $this->description);
    }
}