<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class API extends Model
{
    protected $table = 'a_p_i_s';

    protected $fillable = [
        'user_id',
        'app_name',
        'app_token',
        'user_address',
        'can_post',
        'can_get',
        'can_put',
        'can_patch',
        'can_delete',
        'blacklisted_ips',
        'blacklisted_routes',
        'is_active',
    ];

    protected $casts = [
        'can_post' => 'boolean',
        'can_get' => 'boolean',
        'can_put' => 'boolean',
        'can_patch' => 'boolean',
        'can_delete' => 'boolean',
        'blacklisted_ips' => 'array',
        'blacklisted_routes' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
