@props(['status', 'label' => null])

@php
    $value = $status instanceof BackedEnum ? $status->value : (string) $status;
    $key = strtoupper($value);
    $display = $label ?: (App\Support\StatusLabels::label($key) ?? str($value)->replace('_', ' ')->lower()->title());
    $tone = match (true) {
        // Explicit RSLDDP-lifecycle tones, checked first so the generic
        // substring rules below (which would otherwise catch "AWAITING"/
        // "PENDING"/"REQUIRED" by accident) never override them.
        in_array($key, ['RSLDDP_AWAITING_UPLOAD', 'RSLDDP_FOR_ACCOUNTING_PROCESSING', 'RSLDDP_FOR_RESOLUTION', 'COMPLIANCE_RSLDDP_PENDING', 'RSLDDP_DISPOSITION_PENDING'], true) => 'info',
        in_array($key, ['RSLDDP_PAYMENT_REQUIRED', 'RSLDDP_COMPLIANCE_VERIFICATION'], true) => 'warning',
        str_contains($key, 'OVERDUE') || in_array($key, ['REJECTED', 'EXPIRED', 'FAILED', 'UNAVAILABLE', 'CRITICAL', 'LOST', 'DESTROYED', 'STOLEN', 'CONDEMNED'], true) => 'danger',
        str_contains($key, 'APPROVED') || str_contains($key, 'COMPLETED') || str_contains($key, 'VERIFIED') || in_array($key, ['ACTIVE', 'AVAILABLE', 'READY_FOR_RELEASE', 'RELEASED', 'RETURNED', 'SETTLED', 'EFFECTIVE', 'FINAL', 'CLOSED', 'BORROWER_CLEARED', 'RESOLVED'], true) => 'success',
        str_contains($key, 'RETURNED_FOR_REVISION') || str_contains($key, 'DUE_SOON') || str_contains($key, 'PENDING') || str_contains($key, 'AWAITING') || str_contains($key, 'LOW_STOCK') || str_contains($key, 'OBLIGATION') || str_contains($key, 'DAMAGED') || in_array($key, ['MISSING', 'PICKUP_EXPIRED', 'ACCOUNTABILITY_REVIEW', 'COMPLIANCE_REQUIRED', 'FOR_BILLING', 'BILLING_OPEN', 'BILLING_ISSUED', 'PAYMENT_VERIFICATION', 'LATE_RETURN', 'BORROWING_RESTRICTED'], true) => 'warning',
        str_contains($key, 'UNDER_') || str_contains($key, 'PREPARING') || in_array($key, ['INFORMATIONAL', 'INFO', 'SUBMITTED', 'UNREAD', 'RECEIVED', 'IN_PROCESS', 'TURNED_OVER_TO_LAUNDRY', 'VERIFIED_BY_ACTION_OFFICER', 'ALLOCATED', 'BORROWED', 'RELEASED_PENDING_RETURN', 'PICKUP_SCHEDULED', 'ITEM_PREPARATION', 'PICKUP_SCHEDULING', 'READY_FOR_PRINTING'], true) => 'info',
        in_array($key, ['INACTIVE', 'NOT_APPLICABLE', 'NOT_CONFIGURED', 'CANCELLED', 'VOID', 'WAIVED', 'SUPERSEDED', 'INVALIDATED'], true) => 'neutral',
        default => 'neutral',
    };
@endphp

<span {{ $attributes->class(['status-badge', 'status-'.$tone])->merge(['data-status-tone' => $tone]) }}>{{ $display }}</span>
