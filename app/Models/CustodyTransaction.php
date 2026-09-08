<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CustodyTransaction extends Model
{
    /*
     * The *_signature_snapshot_id columns below are retained only because they
     * already exist in older databases and legacy code may still read them.
     * The active custody workflow does not create or require e-signatures.
     */
    protected $fillable = [
        'custody_no',
        'request_id',
        'request_version_id',
        'borrower_user_id',
        'released_by_user_id',
        'prepared_by_user_id',

        /*
         * SPMU Action Officer E-signature for the physical issuance of the
         * approved property. This is NOT the borrower's request certification
         * signature and NOT the borrower's handwritten receipt signature.
         */
        'released_by_signature_snapshot_id',

        'borrower_ack_signature_snapshot_id',
        'laundry_borrower_signature_snapshot_id',
        'laundry_approved_by_user_id',
        'laundry_approver_signature_snapshot_id',
        'laundry_temporary_delegation_id',
        'laundry_approved_at',
        'status',
        'scheduled_release_at',
        'pickup_expires_at',
        'pickup_expired_at',
        'pickup_scheduled_by_user_id',
        'pickup_scheduled_at',
        'released_at',
        'prepared_at',
        'due_at',
        'original_due_at',
        'due_adjustment_reason',
        'due_adjusted_at',
        'acknowledged_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_release_at' => 'datetime',
            'pickup_expires_at' => 'datetime',
            'pickup_expired_at' => 'datetime',
            'pickup_scheduled_at' => 'datetime',
            'released_at' => 'datetime',
            'prepared_at' => 'datetime',
            'due_at' => 'datetime',
            'original_due_at' => 'datetime',
            'due_adjusted_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'laundry_approved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(BorrowingRequest::class, 'request_id');
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'borrower_user_id');
    }

    public function pickupScheduledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pickup_scheduled_by_user_id');
    }

    /**
     * SPMU Action Officer who physically issued the approved property.
     */
    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_user_id');
    }

    public function hasPickupSchedule(): bool
    {
        return $this->scheduled_release_at !== null
            && $this->pickup_expires_at !== null
            && $this->pickup_scheduled_at !== null
            && $this->pickup_expired_at === null;
    }

    /**
     * SPMU Action Officer issuance E-signature captured at physical release.
     */
    public function releaseSignature(): BelongsTo
    {
        return $this->belongsTo(SignatureSnapshot::class, 'released_by_signature_snapshot_id');
    }

    public function acknowledgementSignature(): BelongsTo
    {
        return $this->belongsTo(SignatureSnapshot::class, 'borrower_ack_signature_snapshot_id');
    }

    public function laundryBorrowerSignature(): BelongsTo
    {
        return $this->belongsTo(SignatureSnapshot::class, 'laundry_borrower_signature_snapshot_id');
    }

    public function laundryApproverSignature(): BelongsTo
    {
        return $this->belongsTo(SignatureSnapshot::class, 'laundry_approver_signature_snapshot_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CustodyLine::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(ReturnTransaction::class);
    }

    public function gatePass(): HasOne
    {
        return $this->hasOne(GatePass::class);
    }

    public function laundryJob(): HasOne
    {
        return $this->hasOne(LaundryJob::class);
    }

    public function overdueCase(): HasOne
    {
        return $this->hasOne(OverdueCase::class);
    }

    public function earlyReturnRequests(): HasMany
    {
        return $this->hasMany(EarlyReturnRequest::class);
    }

    /**
     * Return one borrower/SPMU-facing lifecycle status for this custody.
     * This is the single source of truth used by list/detail pages so a
     * cancelled record cannot also appear as Completed just because closed_at
     * was populated as part of cancellation cleanup.
     *
     * @return array{key:string,label:string,group:string}
     */
    public function workflowStatus(): array
    {
        $requestStatus = $this->request?->status;
        $requestStatusValue = $requestStatus instanceof \BackedEnum
            ? $requestStatus->value
            : (string) $requestStatus;

        if (strtoupper((string) $this->status) === 'CANCELLED' || strtoupper($requestStatusValue) === 'CANCELLED') {
            return ['key' => 'CANCELLED', 'label' => 'Cancelled', 'group' => 'cancelled'];
        }

        if ((string) $this->status === 'CLOSED') {
            $hasLaundryItem = $this->lines->contains(
                fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
            );
            $laundryJob = $this->laundryJob;
            $fullyComplete = ! $hasLaundryItem
                || ($laundryJob?->status === 'LAUNDRY_COMPLETED' && $laundryJob?->latestEvidence?->file);

            return $fullyComplete
                ? ['key' => 'COMPLETED', 'label' => 'Completed', 'group' => 'completed']
                : ['key' => 'BORROWER_CLEARED', 'label' => 'Borrower Cleared', 'group' => 'completed'];
        }

        if ((string) $this->status === 'OBLIGATION_OPEN') {
            return ['key' => 'OBLIGATION_OPEN', 'label' => 'Obligation Open', 'group' => 'attention'];
        }

        if ((string) $this->status === 'INCIDENT_OPEN') {
            return ['key' => 'INCIDENT_OPEN', 'label' => 'Incident Open', 'group' => 'attention'];
        }

        if (in_array((string) $this->status, ['RETURN_PROCESSING', 'PARTIALLY_RETURNED', 'EARLY_RETURN'], true)) {
            return ['key' => 'RETURN_PROCESSING', 'label' => 'Return Processing', 'group' => 'return'];
        }

        if ((string) $this->status === 'OVERDUE') {
            return ['key' => 'OVERDUE', 'label' => 'Overdue', 'group' => 'attention'];
        }

        if ($this->released_at) {
            return ['key' => 'BORROWED', 'label' => 'Items Released / On Custody', 'group' => 'custody'];
        }

        if ((string) $this->status === 'PREPARING_RELEASE') {
            $hasGeneratedWindow = (bool) $this->scheduled_release_at && (bool) $this->pickup_expires_at;
            $hasSchedule = $hasGeneratedWindow && (bool) $this->pickup_scheduled_at;
            $expired = (bool) $this->pickup_expired_at
                || ($hasSchedule && now()->gt($this->pickup_expires_at));
            $upcoming = $hasSchedule && ! $expired && now()->lt($this->scheduled_release_at);
            $open = $hasSchedule && ! $expired && ! $upcoming;

            if ($expired) {
                return ['key' => 'PICKUP_EXPIRED', 'label' => 'Pickup Window Expired', 'group' => 'release'];
            }

            if ($this->prepared_at && $open) {
                return ['key' => 'READY_FOR_RELEASE', 'label' => 'Ready for Release', 'group' => 'release'];
            }

            if ($this->prepared_at && $upcoming) {
                return ['key' => 'PICKUP_SCHEDULED', 'label' => 'Pickup Scheduled', 'group' => 'release'];
            }

            if ($hasSchedule && ! $this->prepared_at) {
                return ['key' => 'ITEM_PREPARATION', 'label' => 'For Item Preparation', 'group' => 'release'];
            }

            if ($hasGeneratedWindow && ! $hasSchedule) {
                return ['key' => 'PICKUP_CONFIRMATION', 'label' => 'For Pickup Confirmation', 'group' => 'release'];
            }

            if (! $hasGeneratedWindow) {
                return ['key' => 'PICKUP_SCHEDULING', 'label' => 'Pickup Schedule Exception', 'group' => 'release'];
            }

            return ['key' => 'PREPARING_RELEASE', 'label' => 'Preparing for Release', 'group' => 'release'];
        }

        return [
            'key' => strtoupper((string) $this->status),
            'label' => str((string) $this->status)->replace('_', ' ')->lower()->title()->toString(),
            'group' => 'active',
        ];
    }
}
