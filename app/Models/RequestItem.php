<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RequestItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_version_id', 'inventory_item_id', 'description_snapshot', 'unit_snapshot',
        'requested_quantity', 'approved_quantity', 'use_location', 'remarks',
    ];

    protected function casts(): array
    {
        return ['requested_quantity' => 'integer', 'approved_quantity' => 'integer'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(RequestVersion::class, 'request_version_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function allocation(): HasOne
    {
        return $this->hasOne(Allocation::class);
    }
}
