<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanComparisonRow extends Model
{
    protected $fillable = [
        'label',
        'tier_keys',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'tier_keys' => 'array',
            'sort_order' => 'integer',
        ];
    }
}
