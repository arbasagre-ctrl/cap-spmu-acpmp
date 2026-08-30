<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id', 'unit_id', 'unique_description', 'specification', 'total_quantity',
        'condition_code', 'borrowable', 'off_campus_allowed', 'laundry_required', 'provisional', 'active',
    ];

    protected function casts(): array
    {
        return [
            'total_quantity' => 'integer',
            'borrowable' => 'boolean',
            'off_campus_allowed' => 'boolean',
            'laundry_required' => 'boolean',
            'provisional' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(InventoryCategory::class, 'category_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_id');
    }

    public function requestItems(): HasMany
    {
        return $this->hasMany(RequestItem::class);
    }
}
