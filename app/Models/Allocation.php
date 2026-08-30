<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Allocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_item_id', 'period_start', 'period_end', 'allocated_quantity',
        'released_quantity', 'restored_quantity', 'status', 'allocated_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime', 'period_end' => 'datetime', 'allocated_at' => 'datetime',
            'allocated_quantity' => 'integer', 'released_quantity' => 'integer',
            'restored_quantity' => 'integer',
        ];
    }

    public function requestItem(): BelongsTo
    {
        return $this->belongsTo(RequestItem::class);
    }
}
