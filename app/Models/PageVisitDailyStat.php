<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'visit_date',
    'route_name',
    'path',
    'path_hash',
    'total_visits',
    'authenticated_visits',
    'guest_visits',
    'bot_visits',
])]
class PageVisitDailyStat extends Model
{
    protected function casts(): array
    {
        return [
            'visit_date' => 'date',
            'total_visits' => 'integer',
            'authenticated_visits' => 'integer',
            'guest_visits' => 'integer',
            'bot_visits' => 'integer',
        ];
    }
}
