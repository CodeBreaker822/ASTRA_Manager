<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $plan
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $position_id
 * @property string|null $user_status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'email',
    'password',
    'plan',
    'position_id',
    'user_status',
    'wallet_balance',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<UserPositions, $this>
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(UserPositions::class, 'position_id');
    }

    /**
     * @return HasMany<TranscriptProject, $this>
     */
    public function transcriptProjects(): HasMany
    {
        return $this->hasMany(TranscriptProject::class);
    }

    /**
     * @return HasMany<UsageRecord, $this>
     */
    public function usageRecords(): HasMany
    {
        return $this->hasMany(UsageRecord::class);
    }

    /**
     * @return HasMany<BillingTransaction, $this>
     */
    public function billingTransactions(): HasMany
    {
        return $this->hasMany(BillingTransaction::class);
    }

    /**
     * @return HasOne<API, $this>
     */
    public function license(): HasOne
    {
        return $this->hasOne(API::class);
    }

    /**
     * Avatar initials: the first letter of the first two parts of the email,
     * splitting on whitespace and the usual address separators.
     */
    public function initials(): string
    {
        $parts = preg_split('/[\s@._-]+/', $this->email, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return collect(array_slice($parts, 0, 2))
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }
}
