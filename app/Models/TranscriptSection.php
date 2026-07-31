<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $transcript_id
 * @property int $position
 * @property string $text
 * @property string|null $cleaned_text
 * @property int|null $started_at_ms
 * @property int|null $ended_at_ms
 * @property array<int, array<string, mixed>>|null $speaker_timestamps
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'transcript_id',
    'position',
    'text',
    'cleaned_text',
    'started_at_ms',
    'ended_at_ms',
    'speaker_timestamps',
])]
class TranscriptSection extends Model
{
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'started_at_ms' => 'integer',
            'ended_at_ms' => 'integer',
            'speaker_timestamps' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Transcript, $this>
     */
    public function transcript(): BelongsTo
    {
        return $this->belongsTo(Transcript::class);
    }
}
