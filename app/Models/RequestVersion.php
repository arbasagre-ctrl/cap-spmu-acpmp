<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RequestVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
        'version_no',
        'purpose_event',
        'location',
        'division_code',
        'office_unit',

        /*
         * Canonical date-only borrowing period used by the
         * current client workflow.
         */
        'schedule_date',
        'return_date',

        /*
         * Legacy timestamp fields are retained so existing
         * inventory, calendar, reports, and historical records
         * continue to work. New records normalize these to:
         *
         * needed_from  = Schedule Date at start of day
         * return_due_at = Return Date at end of day
         */
        'needed_from',
        'return_due_at',

        'represents_student_activity',
        'student_organization',
        'represented_program_department',
        'represented_year_level',
        'event_details',
        'off_campus',
        'remarks',

        /*
         * The borrower's explicit submission captures an immutable
         * E-signature snapshot for this exact request version. Corrected
         * resubmissions create a new version and never overwrite the prior
         * signed evidence.
         */
        'signed_at',
        'submitted_at',
        'borrower_signature_snapshot_id',

        'created_by_user_id',
        'accuracy_certified',
    ];

    protected function casts(): array
    {
        return [
            'schedule_date' => 'date',
            'return_date' => 'date',

            /*
             * Keep these as datetime for compatibility with the
             * current inventory/calendar code. Their time values
             * are system-normalized, not borrower-selected.
             */
            'needed_from' => 'datetime',
            'return_due_at' => 'datetime',

            'off_campus' => 'boolean',
            'represents_student_activity' => 'boolean',
            'signed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'accuracy_certified' => 'boolean',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(
            BorrowingRequest::class,
            'request_id'
        );
    }

    public function items(): HasMany
    {
        return $this->hasMany(
            RequestItem::class
        );
    }

    public function approvalSteps(): HasMany
    {
        return $this->hasMany(
            ApprovalStep::class
        );
    }

    public function documents(): HasMany
    {
        return $this->hasMany(
            GeneratedDocument::class
        );
    }

    /**
     * Scanned supporting documents attached to this exact
     * version of the borrowing request.
     */
    public function supportingDocuments(): HasMany
    {
        return $this->hasMany(
            RequestSupportingDocument::class,
            'request_version_id'
        );
    }

    /**
     * Legacy relationship retained for historical transactions.
     */
    public function borrowerSignature(): BelongsTo
    {
        return $this->belongsTo(
            SignatureSnapshot::class,
            'borrower_signature_snapshot_id'
        );
    }
}
