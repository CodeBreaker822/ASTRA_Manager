<?php

namespace App\Models;

use Database\Factories\BlogPostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string|null $excerpt
 * @property string $body_markdown
 * @property string|null $cover_path
 * @property string $status
 * @property Carbon|null $published_at
 * @property int|null $author_id
 */
class BlogPost extends Model
{
    /** @use HasFactory<BlogPostFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'slug',
        'excerpt',
        'body_markdown',
        'cover_path',
        'status',
        'published_at',
        'author_id',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public static function renderMarkdown(string $markdown): string
    {
        return Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(bool $withBody = false): array
    {
        return array_filter([
            'title' => $this->title,
            'slug' => $this->slug,
            'date' => $this->published_at?->format('Y-m-d') ?? '',
            'excerpt' => $this->excerpt ?? '',
            'cover' => $this->cover_path ?? '',
            'cover_url' => $this->cover_path ? Storage::disk('public')->url($this->cover_path) : null,
            'html' => $withBody ? self::renderMarkdown($this->body_markdown) : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDashboardArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt ?? '',
            'body_markdown' => $this->body_markdown,
            'cover_path' => $this->cover_path,
            'cover_url' => $this->cover_path ? Storage::disk('public')->url($this->cover_path) : null,
            'status' => $this->status,
            'published_at' => $this->published_at?->format('Y-m-d\TH:i'),
            'date' => $this->published_at?->format('Y-m-d') ?? '',
            'author' => $this->author?->name,
            'html' => self::renderMarkdown($this->body_markdown),
        ];
    }
}
