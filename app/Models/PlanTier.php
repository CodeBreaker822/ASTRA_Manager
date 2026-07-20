<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanTier extends Model
{
    protected $fillable = [
        'key',
        'name',
        'tagline',
        'monthly_price',
        'yearly_price',
        'price_label',
        'minutes',
        'cta',
        'featured',
        'features',
        'entitlements',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'monthly_price' => 'integer',
            'yearly_price' => 'integer',
            'minutes' => 'integer',
            'featured' => 'boolean',
            'features' => 'array',
            'entitlements' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
