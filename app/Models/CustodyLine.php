<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CustodyLine extends Model
{
    protected $fillable = ['custody_transaction_id', 'request_item_id', 'allocation_id', 'approved_quantity', 'quantity_to_receive', 'actual_released_quantity', 'returned_quantity', 'item_status', 'compliance_status', 'release_condition', 'adjustment_reason'];

    protected function casts(): array
    {
        return ['approved_quantity' => 'integer', 'quantity_to_receive' => 'integer', 'actual_released_quantity' => 'integer', 'returned_quantity' => 'integer'];
    }

    public function custody(): BelongsTo
    {
        return $this->belongsTo(CustodyTransaction::class, 'custody_transaction_id');
    }

    public function requestItem(): BelongsTo
    {
        return $this->belongsTo(RequestItem::class);
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(Allocation::class);
    }

    public function returnLines(): HasMany
    {
        return $this->hasMany(ReturnLine::class);
    }

    public function laundryJobLine(): HasOne
    {
        return $this->hasOne(LaundryJobLine::class);
    }
}
