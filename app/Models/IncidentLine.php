<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentLine extends Model
{
    protected $fillable = ['incident_id', 'custody_line_id', 'quantity', 'observed_condition', 'disposition_state', 'assessed_value'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'assessed_value' => 'decimal:2'];
    }

    public function custodyLine(): BelongsTo
    {
        return $this->belongsTo(CustodyLine::class);
    }
}
