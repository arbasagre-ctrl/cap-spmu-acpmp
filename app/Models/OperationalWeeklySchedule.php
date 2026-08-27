<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalWeeklySchedule extends Model
{
    protected $fillable = [
        'weekday',
        'is_open',
        'accepts_requests',
        'allows_pickup',
        'allows_return',
        'open_time',
        'close_time',
        'configured_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'is_open' => 'boolean',
            'accepts_requests' => 'boolean',
            'allows_pickup' => 'boolean',
            'allows_return' => 'boolean',
        ];
    }

    public function configuredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'configured_by_user_id');
    }
}
