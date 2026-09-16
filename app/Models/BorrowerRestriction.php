<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BorrowerRestriction extends Model
{
    protected $fillable = [
        'borrower_user_id',
        'custody_transaction_id',
        'restriction_type',
        'reason',
        'effective_from',
        'effective_to',
        'status',
        'imposed_by_user_id',
        'lifted_by_user_id',
        'penalty_id',
        'billing_statement_id',
        'incident_id',
        'sanction_id',
    ];



    public function custody(): BelongsTo
    {
        return $this->belongsTo(CustodyTransaction::class, 'custody_transaction_id');
    }

    /**
     * Restrict a query to the borrowing transaction that created the control.
     * The reason fallback keeps pre-migration rows safe without letting one
     * custody lift or overwrite another custody's restriction.
     */
    public function scopeForCustody(Builder $query, CustodyTransaction $custody): Builder
    {
        return $query->where(function (Builder $source) use ($custody): void {
            $source->where('custody_transaction_id', $custody->id)
                ->orWhere(function (Builder $legacy) use ($custody): void {
                    $legacy->whereNull('custody_transaction_id')
                        ->where('borrower_user_id', $custody->borrower_user_id)
                        ->where('reason', 'like', '%'.$custody->custody_no.'%');
                });
        });
    }

    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }
}
