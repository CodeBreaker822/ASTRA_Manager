<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPermissions extends Model
{
    protected $fillable = [
        'position_id',
        'permission_name',
    ];

    public function position()
    {
        return $this->belongsTo(UserPositions::class, 'position_id', 'id');
    }
}
