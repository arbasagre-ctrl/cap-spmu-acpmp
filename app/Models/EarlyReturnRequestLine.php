<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EarlyReturnRequestLine extends Model
{
    protected $fillable = ['early_return_request_id', 'custody_line_id', 'proposed_quantity'];

    protected function casts(): array
    {
        return ['proposed_quantity' => 'integer'];
    }

    public function custodyLine(): BelongsTo
    {
        return $this->belongsTo(CustodyLine::class);
    }
}
