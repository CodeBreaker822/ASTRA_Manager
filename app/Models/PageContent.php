<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property array<int|string, mixed> $content */
class PageContent extends Model
{
    protected $fillable = [
        'page',
        'section',
        'content',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
        ];
    }
}
