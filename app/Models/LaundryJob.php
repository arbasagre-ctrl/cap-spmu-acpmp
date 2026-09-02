<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LaundryJob extends Model
{
    protected $fillable = [
        'custody_transaction_id',
        'generated_document_id',
        'latest_evidence_submission_id',
        'form_verified_by_user_id',
        'status',
        'worker_name',
        'worker_received_at',
        'worker_completed_at',
        'worker_remarks',
        'ready_at',
        'released_to_borrower_at',
        'form_verified_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'worker_received_at' => 'datetime',
            'worker_completed_at' => 'datetime',
            'ready_at' => 'datetime',
            'released_to_borrower_at' => 'datetime',
            'form_verified_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function custody(): BelongsTo
    {
        return $this->belongsTo(CustodyTransaction::class, 'custody_transaction_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'generated_document_id');
    }

    public function latestEvidence(): BelongsTo
    {
        return $this->belongsTo(EvidenceSubmission::class, 'latest_evidence_submission_id');
    }

    public function formVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'form_verified_by_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(LaundryJobLine::class);
    }

    /**
     * The accomplished Laundry Form is on file and verified, which is the
     * evidence that Laundry Personnel physically received the returned linen,
     * recorded quantity/condition, and wet-signed "Received by".
     */
    public function hasVerifiedAccomplishedForm(): bool
    {
        return (bool) ($this->latest_evidence_submission_id && $this->form_verified_at);
    }

    /*
     * DISPLAY-ONLY READING OF THE PHYSICAL SEQUENCE
     * ---------------------------------------------
     * The stored status stays FOR_LAUNDRY from physical release until the
     * turnover is confirmed, so on its own it cannot separate "the borrower
     * still holds the linen" from "Laundry already received and signed for
     * it". The verified accomplished form is what distinguishes the two, so
     * the label is derived here instead of adding a database status. Nothing
     * in this file changes a transition.
     */
    public function displayStatusLabel(): string
    {
        return match (true) {
            $this->status === 'FOR_LAUNDRY' && $this->hasVerifiedAccomplishedForm()
                => 'Laundry Receipt Confirmed',
            $this->status === 'FOR_LAUNDRY' => 'Awaiting Laundry Return',
            $this->status === 'TURNED_OVER_TO_LAUNDRY' => 'Internal Laundry Pending',
            $this->status === 'LAUNDRY_COMPLETED' => 'Laundry Completed',
            default => str($this->status)->replace('_', ' ')->title(),
        };
    }

    public function displayStatusDescription(): string
    {
        return match (true) {
            $this->status === 'FOR_LAUNDRY' && $this->hasVerifiedAccomplishedForm()
                => 'Accomplished Laundry Form verified · awaiting SPMU Return Verification',
            $this->status === 'FOR_LAUNDRY'
                => 'Borrower returns the linen to the Laundry Area first',
            $this->status === 'TURNED_OVER_TO_LAUNDRY'
                => 'Borrower turnover complete · internal laundry pending',
            $this->status === 'LAUNDRY_COMPLETED'
                => 'Clean linen available in the Laundry Area',
            default => $this->displayStatusLabel(),
        };
    }
}
