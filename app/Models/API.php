<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

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
            ->first()
            ?? self::query()->where('app_token', $token)->first();
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function appToken(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): ?string {
                if ($value === null || $value === '') {
                    return $value;
                }

                try {
                    return Crypt::decryptString($value);
                } catch (Throwable) {
                    return $value;
                }
            },
            set: fn (?string $value): array => $value === null || $value === ''
                ? [
                    'app_token' => $value,
                    'app_token_hash' => null,
                    'app_token_suffix' => null,
                ]
                : [
                    'app_token' => Crypt::encryptString($value),
                    'app_token_hash' => self::hashToken($value),
                    'app_token_suffix' => Str::of($value)->substr(-12)->toString(),
                ],
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
