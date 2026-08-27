<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalDateException extends Model
{
    protected $fillable = [
        'exception_date',
        'status',
        'accepts_requests',
        'allows_pickup',
        'allows_return',
        'open_time',
        'close_time',
        'reason',
        'configured_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'exception_date' => 'date',
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
