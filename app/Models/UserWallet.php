<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * User wallet for unified billing system (USD-based)
 *
 * Stores user's current wallet balance and reserved funds in USD cents.
 *
 * @property int $id
 * @property int $user_id
 * @property int $balance_cents Current wallet balance in USD cents
 * @property int $reserved_cents Reserved amount for current operation in USD cents
 * @property int $total_earned_cents Total credits earned over time in USD cents
 * @property int $total_spent_cents Total debits spent over time in USD cents
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class UserWallet extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'balance_cents',
        'reserved_cents',
        'total_earned_cents',
        'total_spent_cents',
    ];

    /**
     * The attributes should not be cast.
     *
     * @var array<int, string>
     */
    protected $casts = [
        'balance_cents' => 'integer',
        'reserved_cents' => 'integer',
        'total_earned_cents' => 'integer',
        'total_spent_cents' => 'integer',
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
     * Get wallet balance in dollars
     */
    public function getBalanceDollarsAttribute(): float
    {
        return $this->balance_cents / 100.0;
    }

    /**
     * Get reserved amount in dollars
     */
    public function getReservedDollarsAttribute(): float
    {
        return $this->reserved_cents / 100.0;
    }

    /**
     * Scope query for users with sufficient balance
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $requiredCents Required amount in cents
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithSufficientBalance($query, int $requiredCents)
    {
        return $query->where('balance_cents', '>=', $requiredCents);
    }

    /**
     * Check if user has sufficient balance
     *
     * @param int $requiredCents Required amount in cents
     * @return bool
     */
    public function hasSufficientBalance(int $requiredCents): bool
    {
        return $this->balance_cents >= $requiredCents;
    }

    /**
     * Check if user has sufficient funds after considering reservation
     *
     * @param int $requiredCents Required amount in cents
     * @return bool
     */
    public function canAfford(int $requiredCents): bool
    {
        return ($this->balance_cents + $this->reserved_cents) >= $requiredCents;
    }

    /**
     * Increment balance (thread-safe) - in USD cents
     *
     * @param int $amountCents Amount in cents
     * @return bool
     */
    public function incrementBalance(int $amountCents): bool
    {
        return DB::transaction(function () use ($amountCents) {
            $this->balance_cents += $amountCents;
            $this->total_earned_cents += $amountCents;
            return $this->save();
        });
    }

    /**
     * Decrement balance (thread-safe) - in USD cents
     *
     * @param int $amountCents Amount in cents
     * @return bool
     */
    public function decrementBalance(int $amountCents): bool
    {
        return DB::transaction(function () use ($amountCents) {
            if ($this->balance_cents < $amountCents) {
                return false;
            }
            $this->balance_cents -= $amountCents;
            $this->total_spent_cents += $amountCents;
            return $this->save();
        });
    }

    /**
     * Add to reserved amount (in USD cents)
     *
     * @param int $amountCents Amount in cents
     * @return bool
     */
    public function addReservation(int $amountCents): bool
    {
        return DB::transaction(function () use ($amountCents) {
            $this->reserved_cents += $amountCents;
            return $this->save();
        });
    }

    /**
     * Subtract from reserved amount (in USD cents)
     *
     * @param int $amountCents Amount in cents
     * @return bool
     */
    public function removeReservation(int $amountCents): bool
    {
        return DB::transaction(function () use ($amountCents) {
            if ($this->reserved_cents < $amountCents) {
                return false;
            }
            $this->reserved_cents -= $amountCents;
            return $this->save();
        });
    }

    /**
     * Set reservation (in USD cents)
     *
     * @param int $amountCents Amount in cents
     * @return bool
     */
    public function setReservation(int $amountCents): bool
    {
        return DB::transaction(function () use ($amountCents) {
            $this->reserved_cents = $amountCents;
            return $this->save();
        });
    }

    /**
     * Clear reservation
     *
     * @return bool
     */
    public function clearReservation(): bool
    {
        return DB::transaction(function () {
            $this->reserved_cents = 0;
            return $this->save();
        });
    }
}