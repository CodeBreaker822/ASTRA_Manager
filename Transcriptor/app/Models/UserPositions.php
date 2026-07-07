<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
     * @var array
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function setIconAttribute($value)
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

    public function getIconAttribute($value)
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

    public function user()
    {
        return $this->hasMany(User::class, 'position_id', 'id');
    }

    public function permissions()
    {
        return $this->hasMany(UserPermissions::class, 'position_id', 'id');
    }
}
