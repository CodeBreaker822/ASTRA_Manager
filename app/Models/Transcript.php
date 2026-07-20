<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property string $source
 * @property string $status
 * @property int $duration_seconds
 * @property string|null $raw_text
 * @property string|null $cleaned_text
 * @property string|null $summary_text
 * @property string|null $audio_path
 * @property array<int, array<string, mixed>>|null $processing_log
 * @property string $polish_status
 * @property string|null $polish_error_message
 * @property string $summary_status
 * @property string|null $summary_error_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, TranscriptSection> $sections
 */
#[Fillable([
    'project_id',
    'source',
    'status',
    'duration_seconds',
    'raw_text',
    'cleaned_text',
    'summary_text',
    'audio_path',
    'processing_log',
    'polish_status',
    'polish_error_message',
    'summary_status',
    'summary_error_message',
])]
class Transcript extends Model
{
    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'processing_log' => 'array',
        ];
    }

    /**
     * @return BelongsTo<TranscriptProject, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(TranscriptProject::class, 'project_id');
    }

    /**
     * @return HasMany<TranscriptSection, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(TranscriptSection::class);
    }
}
