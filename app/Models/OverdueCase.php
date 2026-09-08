<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OverdueCase extends Model
{
    protected $fillable = [
        'custody_transaction_id',
        'borrower_user_id',
        'tariff_rule_id',
        'grace_expires_at',
        'overdue_started_at',
        'offense_level',
        'rate_snapshot',
        'accrued_amount',
        'sanction_type',
        'status',

        /* Late-return assessment, frozen at the physical return date. */
        'actual_return_date',
        'return_date_source',
        'late_days',
        'ao_confirmed_by_user_id',
        'ao_confirmed_at',
        'correction_remarks',
    ];

    protected function casts(): array
    {
        return [
            'grace_expires_at' => 'datetime',
            'overdue_started_at' => 'datetime',
            'rate_snapshot' => 'decimal:2',
            'accrued_amount' => 'decimal:2',
            'actual_return_date' => 'date',
            'late_days' => 'integer',
            'ao_confirmed_at' => 'datetime',
        ];
    }

    public function custody(): BelongsTo
    {
        return $this->belongsTo(CustodyTransaction::class, 'custody_transaction_id');
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'borrower_user_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ao_confirmed_by_user_id');
    }

    public function penalties(): HasMany
    {
        return $this->hasMany(Penalty::class);
    }
}
