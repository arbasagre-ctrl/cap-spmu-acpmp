<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Incident extends Model
{
    protected $fillable = ['incident_no', 'custody_transaction_id', 'borrower_user_id', 'reported_by_user_id', 'head_decision_signature_snapshot_id', 'head_decided_by_user_id', 'head_decided_at', 'supporting_evidence_file_id', 'incident_type', 'reported_at', 'police_blotter_reference', 'appraisal_amount', 'rslddp_reference', 'requires_rslddp', 'compliance_action', 'rslddp_evidence_submission_id', 'status', 'remarks', 'official_disposition', 'official_disposition_amount', 'official_disposition_details', 'official_disposition_recorded_by_user_id', 'official_disposition_recorded_at', 'compliance_verification_status', 'compliance_verification_remarks', 'compliance_verified_by_user_id', 'compliance_verified_at', 'accountability_flow_version'];

    protected function casts(): array
    {
        return [
            'reported_at' => 'datetime',
            'head_decided_at' => 'datetime',
            'appraisal_amount' => 'decimal:2',
            'requires_rslddp' => 'boolean',
            'official_disposition_amount' => 'decimal:2',
            'official_disposition_recorded_at' => 'datetime',
            'compliance_verified_at' => 'datetime',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'borrower_user_id');
    }

    public function custody(): BelongsTo
    {
        return $this->belongsTo(CustodyTransaction::class, 'custody_transaction_id');
    }

    public function evidenceFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'supporting_evidence_file_id');
    }

    /**
     * SPMU Action Officer who inspected the property and reported this case.
     * Printed on the RSLDDP as the named reporting signatory.
     */
    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function headDecisionSignature(): BelongsTo
    {
        return $this->belongsTo(SignatureSnapshot::class, 'head_decision_signature_snapshot_id');
    }

    public function headDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_decided_by_user_id');
    }

    /**
     * SPMU Head/Admin who recorded the official disposition stated in the
     * accomplished RSLDDP (spec Section G).
     */
    public function officialDispositionRecordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'official_disposition_recorded_by_user_id');
    }

    /**
     * SPMU Action Officer who verified REPAIR/REPLACEMENT/RETURN_RECOVERY
     * compliance. Not used for MONETARY_SETTLEMENT, which is verified through
     * the Cashier receipt/payment flow instead.
     */
    public function complianceVerifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'compliance_verified_by_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(IncidentLine::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(GeneratedDocument::class, 'subject');
    }

    /**
     * The accomplished/notarized RSLDDP scan the borrower returns after
     * external signing. Notarization itself never happens in-system; this
     * only tracks the uploaded evidence of it.
     */
    public function rslddpEvidence(): BelongsTo
    {
        return $this->belongsTo(EvidenceSubmission::class, 'rslddp_evidence_submission_id');
    }
}
