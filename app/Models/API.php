<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class API extends Model
{
    protected $table = 'a_p_i_s';

    protected $fillable = [
        'user_id',
        'app_name',
        'app_token',
        'app_token_hash',
        'app_token_suffix',
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
        'app_token' => 'encrypted',
    ];

    protected $hidden = [
        'app_token',
        'app_token_hash',
    ];

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByPlainToken(string $token): ?self
    {
        return self::query()
            ->where('app_token_hash', self::hashToken($token))
            ->first();
    }

    protected static function booted(): void
    {
        static::saving(function (self $api): void {
            if (! $api->isDirty('app_token')) {
                return;
            }

            $token = $api->app_token;

            $api->app_token_hash = filled($token) ? self::hashToken((string) $token) : null;
            $api->app_token_suffix = filled($token) ? Str::of((string) $token)->substr(-12)->toString() : null;
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
