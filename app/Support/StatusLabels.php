<?php

namespace App\Support;

/**
 * The one canonical map of raw status keys to their user-facing label,
 * shared by every place a status is displayed (the status-badge component,
 * filter dropdowns, report options) so a raw key such as
 * RSLDDP_FOR_ACCOUNTING_PROCESSING can never leak through one surface as
 * "Rslddp For Accounting Processing" while another correctly shows
 * "For Accounting Processing".
 *
 * A caller with its own more specific/legacy-flagged label for a particular
 * context (e.g. LateReturnService::LABELS for the case stepper) may still
 * check that first and only fall back here - this class does not claim to
 * be the single source for every context, only the shared canonical one.
 */
class StatusLabels
{
    public const LABELS = [
        'DRAFT' => 'Draft',
        'UNDER_SPMU' => 'Under SPMU Review',
        'UNDER_GSU' => 'Legacy Review',
        'UNDER_VPAF' => 'Legacy Review',
        'PENDING' => 'Pending',
        'RECEIVED' => 'Pending Review',
        'RETURNED_FOR_REVISION' => 'Returned for Revision',
        'FINAL_APPROVED_AWAITING_DOWNLOAD' => 'Approved Letter Ready',
        'APPROVED_READY_FOR_RELEASE' => 'Ready for Release',
        'READY_FOR_RELEASE' => 'Ready for Release',
        'PICKUP_SCHEDULED' => 'Pickup Scheduled',
        'ITEM_PREPARATION' => 'For Item Preparation',
        'PICKUP_SCHEDULING' => 'For Pickup Scheduling',
        'PICKUP_EXPIRED' => 'Pickup Missed',
        'BORROWER_CLEARED' => 'Borrower Cleared',
        'RESOLVED' => 'Resolved',
        'CANCELLED' => 'Cancelled',
        'VOID' => 'Voided',
        'AWAITING_ACCOMPLISHED_GATE_PASS' => 'Awaiting Accomplished Gate Pass',
        'PREPARING_RELEASE' => 'Preparing for Release',
        'RETURN_PROCESSING' => 'Return Processing',
        'OBLIGATION_OPEN' => 'Accountability Pending',
        'ACCOUNTABILITY_REVIEW' => 'Accountability Review',
        'COMPLIANCE_REQUIRED' => 'Compliance Required',
        'COMPLIANCE_RSLDDP_PENDING' => 'Compliance - RSLDDP Pending',
        'RSLDDP_AWAITING_UPLOAD' => 'RSLDDP Processing',
        'RSLDDP_FOR_ACCOUNTING_PROCESSING' => 'For Accounting Processing',
        'RSLDDP_PAYMENT_REQUIRED' => 'Payment Required',
        'RSLDDP_FOR_RESOLUTION' => 'For Resolution',
        'FOR_BILLING' => 'Billing Statement Pending',
        'BILLING_OPEN' => 'Billing Pending',
        'BILLING_ISSUED' => 'Billing Unpaid',
        'PAYMENT_VERIFICATION' => 'Payment Verification',
        'LATE_RETURN' => 'Late Return Processing',
        'EARLY_RETURN' => 'Return Processing', // legacy records only
        'INCIDENT_OPEN' => 'Accountability Pending',
        'RECEIPT_SUBMITTED' => 'Receipt Submitted',
        'PENDING_VERIFICATION' => 'Pending Verification',
        'SUBMITTED_PENDING_ORIGINAL' => 'Pending Original Receipt',
        'RETURNED_PENDING_SETTLEMENT' => 'Pending Settlement',
        'SERVICEABLE' => 'Serviceable',
        'DAMAGED_MAINTENANCE' => 'Damaged / Maintenance',
        'CONDEMNED' => 'Condemned',
        'ALLOCATED' => 'Fully Allocated',
        'BORROWED' => 'On Custody',
        'LAUNDRY' => 'In Laundry',
        'PREPARED' => 'Prepared',
        'RELEASED_PENDING_RETURN' => 'Issued / Awaiting Return',
        'IN_LAUNDRY' => 'In Laundry',
        'INCIDENT_PENDING' => 'Accountability Review',
        'BILLING_PENDING' => 'Billing Pending',
        'SUBMITTED' => 'Submitted',
        'ISSUED' => 'Pending Payment',
        'SETTLED' => 'Paid / Verified',
        'LIFTED' => 'Lifted',
        'ACTIVE' => 'Active',
        'INACTIVE' => 'Inactive',
        'SUSPENDED' => 'Suspended',
        'RESTRICTED' => 'Restricted',
        'BORROWING_RESTRICTED' => 'Borrowing Restricted',
        'ENABLED' => 'Enabled',
        'DISABLED' => 'Disabled',
        'NOT_CONFIGURED' => 'Not configured',
        'BORROWER_ONLY' => 'Borrower',
        'SPMU_HEAD' => 'SPMU Head',
        'SPMU_OFFICER' => 'SPMU Action Officer',
        'GSU_HEAD' => 'Retired Signatory Record',
        'VPAF_HEAD' => 'Retired Signatory Record',
        'ICTU_MAINTAINER' => 'ICTU Maintainer',
        'FOR_LAUNDRY' => 'Laundry Form Pending',
        'IN_PROCESS' => 'Legacy Laundry Process',
        'TURNED_OVER_TO_LAUNDRY' => 'Reconciliation Pending',
        'VERIFIED_BY_ACTION_OFFICER' => 'Verified by Action Officer',
        'READY_FOR_PICKUP' => 'Legacy Ready for Pickup',
        'READY_FOR_SPMU_RETURN' => 'Ready for SPMU Return',
        'AWAITING_FINAL_FORM_UPLOAD' => 'Awaiting Final Form Upload',
        'FORM_REPLACEMENT_REQUIRED' => 'Replacement Form Required',
        'FOR_SPMU_FINAL_CHECK' => 'Legacy SPMU Final Check',
        'LAUNDRY_COMPLETED' => 'Available',
    ];

    /** The canonical label for a raw status key, or null if this map has none. */
    public static function label(string $key): ?string
    {
        return self::LABELS[strtoupper($key)] ?? null;
    }
}
