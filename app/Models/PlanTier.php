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
        'upload_price_per_hour',
        'live_price_per_hour',
        'llm_price',
        'polish_price_per_character',
        'summary_price_per_character',
        'minutes',
        'free_polish_uses_per_day',
        'free_summary_uses_per_day',
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
            'upload_price_per_hour' => 'decimal:8',
            'live_price_per_hour' => 'decimal:8',
            'llm_price' => 'decimal:8',
            'polish_price_per_character' => 'decimal:8',
            'summary_price_per_character' => 'decimal:8',
            'minutes' => 'integer',
            'free_polish_uses_per_day' => 'integer',
            'free_summary_uses_per_day' => 'integer',
            'featured' => 'boolean',
            'features' => 'array',
            'entitlements' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
