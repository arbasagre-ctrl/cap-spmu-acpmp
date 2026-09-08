<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = ['billing_statement_id', 'evidence_file_id', 'recorded_by_user_id', 'verified_by_user_id', 'official_receipt_no', 'receipt_date', 'amount', 'status', 'submitted_at', 'verified_at', 'verification_remarks', 'rejection_reason'];

    protected function casts(): array
    {
        return ['receipt_date' => 'date', 'amount' => 'decimal:2', 'submitted_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function billingStatement(): BelongsTo
    {
        return $this->belongsTo(BillingStatement::class);
    }
}
