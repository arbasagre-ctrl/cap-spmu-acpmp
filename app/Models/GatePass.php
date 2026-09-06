<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatePass extends Model
{
    protected $fillable = [
        'custody_transaction_id',
        'custody_line_id',
        'pass_document_id',
        'accomplished_file_id',
        'uploaded_by_user_id',
        'uploaded_at',
        'verified_by_user_id',

        /* Historical digital-signature fields retained for old records. */
        'prepared_verified_by_user_id',
        'prepared_verifier_signature_snapshot_id',
        'prepared_verified_at',
        'approved_by_user_id',
        'approver_signature_snapshot_id',
        'temporary_delegation_id',
        'approved_at',

        'bearer_name',
        'destination',
        'purpose',
        'guard_name',
        'guard_signed_at',
        'status',
        'verified_at',
        'verification_remarks',
    ];

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'prepared_verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'guard_signed_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function custody(): BelongsTo
    {
        return $this->belongsTo(CustodyTransaction::class, 'custody_transaction_id');
    }

    public function passDocument(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'pass_document_id');
    }

    public function accomplishedFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'accomplished_file_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    /* Historical relations. */
    public function preparedVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_verified_by_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function delegation(): BelongsTo
    {
        return $this->belongsTo(TemporaryDelegation::class, 'temporary_delegation_id');
    }

    /**
     * Gate Pass stage shown to the Action Officer. The database status is kept
     * for workflow enforcement, while this presentation status reflects where
     * the related custody actually is now.
     *
     * @return array{key:string,label:string,tone:string}
     */
    public function workflowStatus(): array
    {
        $custody = $this->custody;
        $requestStatus = $custody?->request?->status;
        $requestStatusValue = $requestStatus instanceof \BackedEnum
            ? $requestStatus->value
            : (string) $requestStatus;

        if (in_array(strtoupper((string) $this->status), ['VOID', 'CANCELLED'], true)
            || strtoupper((string) $custody?->status) === 'CANCELLED'
            || strtoupper($requestStatusValue) === 'CANCELLED') {
            return ['key' => 'VOID', 'label' => 'Voided', 'tone' => 'neutral'];
        }

        if ((string) $this->status === 'VERIFIED') {
            return ['key' => 'VERIFIED', 'label' => 'Completed', 'tone' => 'success'];
        }

        if ((string) $this->status === 'READY_FOR_PRINTING') {
            if ($custody?->released_at) {
                return [
                    'key' => 'AWAITING_ACCOMPLISHED_GATE_PASS',
                    'label' => 'Awaiting Accomplished Gate Pass',
                    'tone' => 'warning',
                ];
            }

            $custodyStage = $custody?->workflowStatus();
            if (($custodyStage['key'] ?? null) === 'READY_FOR_RELEASE') {
                return ['key' => 'READY_FOR_RELEASE', 'label' => 'Ready for Release', 'tone' => 'success'];
            }

            if (($custodyStage['key'] ?? null) === 'PICKUP_SCHEDULED') {
                return ['key' => 'PICKUP_SCHEDULED', 'label' => 'Pickup Scheduled', 'tone' => 'info'];
            }

            if (($custodyStage['key'] ?? null) === 'ITEM_PREPARATION') {
                return ['key' => 'ITEM_PREPARATION', 'label' => 'For Item Preparation', 'tone' => 'info'];
            }

            if (($custodyStage['key'] ?? null) === 'PICKUP_SCHEDULING') {
                return ['key' => 'PICKUP_SCHEDULING', 'label' => 'For Pickup Scheduling', 'tone' => 'info'];
            }

            if (($custodyStage['key'] ?? null) === 'PICKUP_EXPIRED') {
                return ['key' => 'PICKUP_EXPIRED', 'label' => 'Pickup Window Expired', 'tone' => 'warning'];
            }

            if (($custodyStage['key'] ?? null) === 'PREPARING_RELEASE') {
                return ['key' => 'PREPARING_RELEASE', 'label' => 'Preparing for Release', 'tone' => 'info'];
            }

            return ['key' => 'READY_FOR_PRINTING', 'label' => 'Approved / Ready for Release', 'tone' => 'info'];
        }

        if ((string) $this->status === 'PENDING') {
            return ['key' => 'PENDING', 'label' => 'Pending Gate Pass', 'tone' => 'warning'];
        }

        return [
            'key' => strtoupper((string) $this->status),
            'label' => str((string) $this->status)->replace('_', ' ')->lower()->title()->toString(),
            'tone' => 'neutral',
        ];
    }

    public function preparedVerifierSignature(): BelongsTo
    {
        return $this->belongsTo(SignatureSnapshot::class, 'prepared_verifier_signature_snapshot_id');
    }

    public function approverSignature(): BelongsTo
    {
        return $this->belongsTo(SignatureSnapshot::class, 'approver_signature_snapshot_id');
    }
}
