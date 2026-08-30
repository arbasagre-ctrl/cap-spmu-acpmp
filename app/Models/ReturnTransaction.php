<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReturnTransaction extends Model
{
    protected $fillable = [
        'return_no',
        'custody_transaction_id',
        'received_by_user_id',
        'inspection_signature_snapshot_id',
        'confirmed_by_user_id',
        'return_type',
        'received_at',
        'confirmed_at',
        'locked_at',
        'reopened_by_user_id',
        'reopened_at',
        'reopen_reason',
        'status',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'locked_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function custody(): BelongsTo
    {
        return $this->belongsTo(CustodyTransaction::class, 'custody_transaction_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    /**
     * Immutable E-signature snapshot of the SPMU Action Officer who recorded
     * this physical return inspection.
     */
    public function inspectionSignature(): BelongsTo
    {
        return $this->belongsTo(SignatureSnapshot::class, 'inspection_signature_snapshot_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReturnLine::class);
    }

    public function isReadOnly(): bool
    {
        return $this->locked_at !== null && $this->status !== 'REOPENED';
    }
}
