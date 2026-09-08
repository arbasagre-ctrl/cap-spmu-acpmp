<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingLine extends Model
{
    protected $fillable = ['billing_statement_id', 'penalty_id', 'incident_id', 'line_type', 'description', 'basis', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    /** The charge this line bills, and through it the case it came from. */
    public function penalty(): BelongsTo
    {
        return $this->belongsTo(Penalty::class);
    }
}
