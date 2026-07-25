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
        'price_per_second',
        'upload_price_per_hour',
        'live_price_per_hour',
        'llm_price',
        'polish_price_per_character',
        'summary_price_per_character',
        'minutes',
        'free_polish_uses_per_day',
        'free_summary_uses_per_day',
        'polish_characters',
        'summary_characters',
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
            'price_per_second' => 'float',
            'upload_price_per_hour' => 'float',
            'live_price_per_hour' => 'float',
            'llm_price' => 'float',
            'polish_price_per_character' => 'float',
            'summary_price_per_character' => 'float',
            'minutes' => 'integer',
            'free_polish_uses_per_day' => 'integer',
            'free_summary_uses_per_day' => 'integer',
            'polish_characters' => 'integer',
            'summary_characters' => 'integer',
            'featured' => 'boolean',
            'features' => 'array',
            'entitlements' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
