<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Penalty extends Model
{
    protected $fillable = ['borrower_user_id', 'custody_transaction_id', 'overdue_case_id', 'incident_id', 'assessed_by_user_id', 'penalty_type', 'offense_level', 'basis', 'rate_snapshot', 'amount', 'status', 'assessed_at'];

    protected function casts(): array
    {
        return ['rate_snapshot' => 'decimal:2', 'amount' => 'decimal:2', 'assessed_at' => 'datetime'];
    }

    public function overdueCase(): BelongsTo
    {
        return $this->belongsTo(OverdueCase::class);
    }

    public function custody(): BelongsTo
    {
        return $this->belongsTo(CustodyTransaction::class, 'custody_transaction_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
