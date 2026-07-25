<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $period
 * @property int $seconds_transcribed
 * @property int $polish_count
 * @property int $summary_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'period',
    'seconds_transcribed',
    'polish_count',
    'summary_count',
])]
class UsageRecord extends Model
{
    protected function casts(): array
    {
        return [
            'seconds_transcribed' => 'integer',
            'polish_count' => 'integer',
            'summary_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
