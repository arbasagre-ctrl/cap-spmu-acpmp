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
     * evidence that the offline Laundry Worker physically received the returned
     * linen, recorded quantity/condition, wet-signed "Received by", and later
     * delivered the accomplished form to SPMU.
     */
    public function hasVerifiedAccomplishedForm(): bool
    {
        return (bool) ($this->latest_evidence_submission_id && $this->form_verified_at);
    }

    /*
     * DISPLAY-ONLY READING OF THE PHYSICAL SEQUENCE
     * ---------------------------------------------
     * Laundry Personnel are a physical/offline actor with no portal account.
     * FOR_LAUNDRY covers the offline period while Laundry Personnel receive,
     * wash/assess the linen, complete the physical form, and deliver that form
     * to SPMU. TURNED_OVER_TO_LAUNDRY is retained as the internal post-encoding
     * state, but the UI presents it as availability finalization rather than a
     * second physical Laundry step.
     */
    public function displayStatusLabel(): string
    {
        return match (true) {
            $this->status === 'FOR_LAUNDRY' && $this->hasVerifiedAccomplishedForm()
                => 'Ready for SPMU Encoding',
            $this->status === 'FOR_LAUNDRY' => 'Laundry Processing / Form Pending',
            $this->status === 'TURNED_OVER_TO_LAUNDRY' => 'Availability Finalization',
            $this->status === 'LAUNDRY_COMPLETED' => 'Available',
            default => str($this->status)->replace('_', ' ')->title(),
        };
    }

    public function displayStatusDescription(): string
    {
        return match (true) {
            $this->status === 'FOR_LAUNDRY' && $this->hasVerifiedAccomplishedForm()
                => 'Completed Laundry Form received · awaiting SPMU return encoding',
            $this->status === 'FOR_LAUNDRY'
                => 'Laundry Personnel are processing the linen offline · completed form not yet received by SPMU',
            $this->status === 'TURNED_OVER_TO_LAUNDRY'
                => 'SPMU return encoded · serviceable linen ready for availability finalization',
            $this->status === 'LAUNDRY_COMPLETED'
                => 'Serviceable linen available',
            default => $this->displayStatusLabel(),
        };
    }
}
