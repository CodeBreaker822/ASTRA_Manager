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
 * @property int $charged_cents
 * @property int $upload_charged_cents
 * @property int $live_charged_cents
 * @property int $polish_charged_cents
 * @property int $summary_charged_cents
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'period',
    'seconds_transcribed',
    'polish_count',
    'summary_count',
    'charged_cents',
    'upload_charged_cents',
    'live_charged_cents',
    'polish_charged_cents',
    'summary_charged_cents',
])]
class UsageRecord extends Model
{
    protected function casts(): array
    {
        return [
            'seconds_transcribed' => 'integer',
            'polish_count' => 'integer',
            'summary_count' => 'integer',
            'charged_cents' => 'integer',
            'upload_charged_cents' => 'integer',
            'live_charged_cents' => 'integer',
            'polish_charged_cents' => 'integer',
            'summary_charged_cents' => 'integer',
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
