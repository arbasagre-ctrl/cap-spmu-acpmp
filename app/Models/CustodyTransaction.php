<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

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

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class, 'custody_transaction_id');
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
     * Return the active accountability signal for this custody, independent of
     * the stored custody status. This keeps older/mixed return records honest:
     * once an adverse finding or late-return case exists, every transaction
     * view can surface it even while another item branch is still returning.
     *
     * @return array{key:string,label:string}|null
     */
    public function activeAccountabilityIndicator(): ?array
    {
        $incidents = $this->relationLoaded('incidents')
            ? $this->incidents
            : $this->incidents()->get();

        $incident = $incidents
            ->whereNotIn('status', ['RESOLVED', 'CLOSED', 'VOID_CORRECTION'])
            ->sortByDesc(fn ($record) => $record->reported_at?->timestamp ?? $record->id)
            ->first();

        if ($incident) {
            return match ((string) $incident->status) {
                'FOR_BILLING' => ['key' => 'FOR_BILLING', 'label' => 'Billing Required'],
                'BILLING_PENDING' => ['key' => 'BILLING_PENDING', 'label' => 'Billing Pending'],
                'COMPLIANCE_REQUIRED' => ['key' => 'COMPLIANCE_REQUIRED', 'label' => 'Compliance Required'],
                default => ['key' => 'INCIDENT_OPEN', 'label' => 'Property Case Open'],
            };
        }

        /*
         * Defensive fallback for older records: a linked Billing Statement or
         * restriction may still be active even if an older incident row was
         * prematurely moved to a terminal status. Do not let that financial or
         * borrowing obligation disappear from transaction views.
         */
        $incidentIds = $incidents->pluck('id')->filter()->values();

        if ($incidentIds->isNotEmpty()) {
            $billingStatus = DB::table('billing_lines')
                ->join('billing_statements', 'billing_statements.id', '=', 'billing_lines.billing_statement_id')
                ->whereIn('billing_lines.incident_id', $incidentIds)
                ->whereNotIn('billing_statements.status', ['SETTLED', 'WAIVED', 'VOID'])
                ->orderByDesc('billing_statements.issued_at')
                ->value('billing_statements.status');

            if ($billingStatus) {
                return [
                    'key' => (string) $billingStatus,
                    'label' => (string) $billingStatus === 'RECEIPT_SUBMITTED'
                        ? 'Payment Verification'
                        : 'Billing Pending',
                ];
            }

            $hasActiveRestriction = BorrowerRestriction::query()
                ->whereIn('incident_id', $incidentIds)
                ->where('status', 'ACTIVE')
                ->where(function ($query): void {
                    $query->whereNull('effective_to')->orWhere('effective_to', '>', now());
                })
                ->exists();

            if ($hasActiveRestriction) {
                return ['key' => 'BORROWING_RESTRICTED', 'label' => 'Borrowing Restricted'];
            }
        }

        $overdue = $this->relationLoaded('overdueCase')
            ? $this->overdueCase
            : $this->overdueCase()->first();

        if ($overdue && (string) $overdue->status !== 'RESOLVED') {
            return ['key' => 'LATE_RETURN', 'label' => 'Late Return Accountability'];
        }

        return null;
    }

    public function hasOutstandingProperty(): bool
    {
        if ($this->relationLoaded('lines')) {
            return $this->lines->contains(
                fn ($line) => (float) $line->returned_quantity < (float) $line->actual_released_quantity
            );
        }

        return $this->lines()
            ->whereColumn('returned_quantity', '<', 'actual_released_quantity')
            ->exists();
    }

    /**
     * Explain why a fully returned custody is still open.
     *
     * The stored custody status remains OBLIGATION_OPEN. This presentation
     * helper exposes the actual unresolved branch so detail pages do not show
     * a generic status without telling the user why.
     *
     * @return array{key:string,label:string,title:string,copy:string}|null
     */
    public function openObligationSummary(): ?array
    {
        $activeAccountability = $this->activeAccountabilityIndicator();

        if (
            ! in_array((string) $this->status, ['OBLIGATION_OPEN', 'INCIDENT_OPEN'], true)
            && $activeAccountability === null
        ) {
            return null;
        }

        $incidentIds = Incident::query()
            ->where('custody_transaction_id', $this->id)
            ->whereNotIn('status', ['RESOLVED', 'CLOSED', 'VOID_CORRECTION'])
            ->pluck('id');

        $penaltyIds = Penalty::query()
            ->where('custody_transaction_id', $this->id)
            ->pluck('id');

        $billingId = null;

        if ($incidentIds->isNotEmpty() || $penaltyIds->isNotEmpty()) {
            $billingId = DB::table('billing_lines')
                ->where(function ($query) use ($incidentIds, $penaltyIds): void {
                    if ($incidentIds->isNotEmpty()) {
                        $query->whereIn('incident_id', $incidentIds);
                    }

                    if ($penaltyIds->isNotEmpty()) {
                        $method = $incidentIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                        $query->{$method}('penalty_id', $penaltyIds);
                    }
                })
                ->value('billing_statement_id');
        }

        if ($billingId) {
            $billing = BillingStatement::query()->find($billingId);

            if ($billing && ! in_array($billing->status, ['SETTLED', 'WAIVED', 'VOID'], true)) {
                return match ((string) $billing->status) {
                    'ISSUED' => [
                        'key' => 'BILLING_ISSUED',
                        'label' => 'Billing Unpaid',
                        'title' => 'Billing Statement issued',
                        'copy' => 'An issued Billing Statement still needs settlement through the CSPC Cashier.',
                    ],
                    'RECEIPT_SUBMITTED' => [
                        'key' => 'PAYMENT_VERIFICATION',
                        'label' => 'Payment Verification',
                        'title' => 'Cashier payment under verification',
                        'copy' => 'SPMU is verifying the recorded CSPC Cashier receipt before the obligation can be cleared.',
                    ],
                    default => [
                        'key' => 'BILLING_OPEN',
                        'label' => 'Billing Pending',
                        'title' => 'Billing obligation pending',
                        'copy' => 'The related financial obligation has not yet been resolved.',
                    ],
                };
            }
        }

        $incident = Incident::query()
            ->where('custody_transaction_id', $this->id)
            ->whereNotIn('status', ['RESOLVED', 'CLOSED', 'VOID_CORRECTION'])
            ->latest('reported_at')
            ->first();

        if ($incident) {
            return match ((string) $incident->status) {
                'FOR_BILLING' => [
                    'key' => 'FOR_BILLING',
                    'label' => 'Billing Statement Pending',
                    'title' => 'Billing required',
                    'copy' => 'The SPMU Head/Admin has required billing for this property case. The formal Billing Statement still needs to be issued.',
                ],
                'BILLING_PENDING' => [
                    'key' => 'BILLING_PENDING',
                    'label' => 'Billing Pending',
                    'title' => 'Billing obligation pending',
                    'copy' => 'The property case has been routed to billing and remains open until the financial obligation is settled or formally waived.',
                ],
                'COMPLIANCE_REQUIRED' => [
                    'key' => 'COMPLIANCE_REQUIRED',
                    'label' => 'Compliance Required',
                    'title' => 'Property compliance required',
                    'copy' => 'SPMU still needs to verify the required repair, replacement, or other compliance.',
                ],
                default => [
                    'key' => 'ACCOUNTABILITY_REVIEW',
                    'label' => 'Accountability Review',
                    'title' => 'Property case under review',
                    'copy' => 'The recorded property finding still requires an accountability decision or resolution.',
                ],
            };
        }

        $overdue = OverdueCase::query()
            ->where('custody_transaction_id', $this->id)
            ->where('status', '!=', 'RESOLVED')
            ->latest('overdue_started_at')
            ->first();

        if ($overdue) {
            return [
                'key' => 'LATE_RETURN',
                'label' => 'Late Return Processing',
                'title' => 'Late-return obligation pending',
                'copy' => 'The date-based late-return assessment has not yet been resolved.',
            ];
        }

        $gatePass = $this->gatePass()->first();
        if ($gatePass && ! in_array($gatePass->status, ['VERIFIED', 'VOID'], true)) {
            return [
                'key' => 'GATE_PASS_PENDING',
                'label' => 'Gate Pass Pending',
                'title' => 'Accomplished Gate Pass pending',
                'copy' => 'The property has been returned, but the accomplished Gate Pass still needs to be recorded by SPMU.',
            ];
        }

        $laundry = $this->laundryJob()->first();
        if ($laundry && ! in_array($laundry->status, ['TURNED_OVER_TO_LAUNDRY', 'LAUNDRY_COMPLETED'], true)) {
            return [
                'key' => 'LINEN_PENDING',
                'label' => 'Linen Pending',
                'title' => 'Linen return documentation pending',
                'copy' => 'The non-linen return may be complete, but the linen branch still has an unresolved Laundry Form or return record.',
            ];
        }

        return [
            'key' => 'OBLIGATION_OPEN',
            'label' => 'Obligation Open',
            'title' => 'Outstanding obligation',
            'copy' => 'The physical return is complete, but an accountability obligation still requires resolution.',
        ];
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

        $activeAccountability = $this->activeAccountabilityIndicator();

        if ($activeAccountability) {
            return [
                'key' => 'OBLIGATION_OPEN',
                'label' => $this->hasOutstandingProperty()
                    ? 'Return + Accountability'
                    : 'Accountability Processing',
                'group' => 'attention',
            ];
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
            $hasSchedule = (bool) $this->scheduled_release_at && (bool) $this->pickup_expires_at;
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

            if (! $hasSchedule) {
                return ['key' => 'PICKUP_SCHEDULING', 'label' => 'For Pickup Scheduling', 'group' => 'release'];
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
