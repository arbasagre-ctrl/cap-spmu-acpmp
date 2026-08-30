<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IncidentLine extends Model
{
    protected $fillable = ['incident_id', 'custody_line_id', 'quantity', 'observed_condition', 'disposition_state', 'assessed_value'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'assessed_value' => 'decimal:2'];
    }
}
