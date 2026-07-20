<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserPositions extends Model
{
    protected $fillable = [
        'position_code',
        'position_name',
        'assigned_office',
        'category',
        'description',
        'max_users',
        'icon',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function setIconAttribute(mixed $value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['icon'] = null;

            return;
        }

        $gzipped = gzencode($value);

        $this->attributes['icon'] = $gzipped === false
            ? $value
            : base64_encode($gzipped);
    }

    public function getIconAttribute(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $decodedBase64 = base64_decode($value, true);

        if ($decodedBase64 === false) {
            return $value;
        }

        $decodedGz = gzdecode($decodedBase64);

        return $decodedGz === false ? $value : $decodedGz;
    }

    /**
     * @return HasMany<User, $this>
     */
    public function user(): HasMany
    {
        return $this->hasMany(User::class, 'position_id', 'id');
    }

    /**
     * @return HasMany<UserPermissions, $this>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(UserPermissions::class, 'position_id', 'id');
    }
}
