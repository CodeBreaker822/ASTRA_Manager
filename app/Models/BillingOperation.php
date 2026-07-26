<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Billing operation record (USD-based)
 *
 * Tracks each billed request through the authorization/charge/release flow.
 * This provides idempotency guarantees and audit trail for billing operations.
 *
 * @property int $id
 * @property int $user_id
 * @property string $feature Billing feature (transcription, polish, summary)
 * @property string $status Operation status (pending, authorized, charged, released, failed)
 * @property int $units_requested Units requested (seconds for audio, characters for text)
 * @property int $units_free Free units consumed
 * @property int $units_paid Paid units consumed
 * @property string $rate_per_unit_cents Rate per unit in USD cents
 * @property int $authorized_amount_cents Amount authorized in USD cents
 * @property int|null $charged_amount_cents Amount charged in USD cents
 * @property string $operation_key Unique idempotency key
 * @property string|null $reference_type Type of the reference
 * @property int|null $reference_id ID of the reference
 * @property array|null $metadata Additional metadata
 * @property Carbon|null $authorized_at
 * @property Carbon|null $charged_at
 * @property Carbon|null $released_at
 * @property Carbon|null $failed_at
 * @property string|null $error_message
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class BillingOperation extends Model
{
    use HasFactory;

    /**
     * Operation statuses
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_AUTHORIZED = 'authorized';
    public const STATUS_CHARGED = 'charged';
    public const STATUS_RELEASED = 'released';
    public const STATUS_FAILED = 'failed';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'feature',
        'status',
        'units_requested',
        'units_free',
        'units_paid',
        'rate_per_unit_cents',
        'authorized_amount_cents',
        'charged_amount_cents',
        'operation_key',
        'reference_type',
        'reference_id',
        'metadata',
        'authorized_at',
        'charged_at',
        'released_at',
        'failed_at',
        'error_message',
    ];

    /**
     * The attributes should not be be cast.
     *
     * @var array<int, string>
     */
    protected $casts = [
        'units_requested' => 'integer',
        'units_free' => 'integer',
        'units_paid' => 'integer',
        'authorized_amount_cents' => 'integer',
        'charged_amount_cents' => 'integer',
        'metadata' => 'array',
        'authorized_at' => 'datetime',
        'charged_at' => 'datetime',
        'released_at' => 'datetime',
        'failed_at' => 'datetime',
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
     * Get the user that owns the billing operation
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if operation is authorized
     *
     * @return bool
     */
    public function isAuthorized(): bool
    {
        return $this->status === self::STATUS_AUTHORIZED;
    }

    /**
     * Check if operation is charged
     *
     * @return bool
     */
    public function isCharged(): bool
    {
        return $this->status === self::STATUS_CHARGED;
    }

    /**
     * Check if operation is released
     *
     * @return bool
     */
    public function isReleased(): bool
    {
        return $this->status === self::STATUS_RELEASED;
    }

    /**
     * Check if operation failed
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Get rate in dollars per unit
     *
     * @return float
     */
    public function getRateDollarsPerUnitAttribute(): float
    {
        return $this->rate_per_unit_cents / 100.0;
    }

    /**
     * Get authorized amount in dollars
     *
     * @return float
     */
    public function getAuthorizedAmountDollarsAttribute(): float
    {
        return $this->authorized_amount_cents / 100.0;
    }

    /**
     * Get charged amount in dollars
     *
     * @return float|null
     */
    public function getChargedAmountDollarsAttribute(): ?float
    {
        return $this->charged_amount_cents
            ? $this->charged_amount_cents / 100.0
            : null;
    }

    /**
     * Get cost in dollars
     *
     * @return float
     */
    public function getCostDollarsAttribute(): float
    {
        return $this->units_paid * $this->getRateDollarsPerUnitAttribute();
    }

    /**
     * Scope query for pending operations
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope query for authorized operations
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAuthorized($query)
    {
        return $query->where('status', self::STATUS_AUTHORIZED);
    }

    /**
     * Scope query for charged operations
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCharged($query)
    {
        return $query->where('status', self::STATUS_CHARGED);
    }

    /**
     * Scope query for released operations
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeReleased($query)
    {
        return $query->where('status', self::STATUS_RELEASED);
    }

    /**
     * Scope query by feature
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $feature Feature type
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFeature($query, string $feature)
    {
        return $query->where('feature', $feature);
    }

    /**
     * Scope query by reference
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $type Reference type
     * @param int|null $id Reference ID
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeReference($query, ?string $type = null, ?int $id = null)
    {
        $query = $query;
        if ($type) {
            $query->where('reference_type', $type);
        }
        if ($id) {
            $query->where('reference_id', $id);
        }
        return $query;
    }
}