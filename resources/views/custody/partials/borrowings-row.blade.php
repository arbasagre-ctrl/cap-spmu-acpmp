@php
    $outstanding = $custody->lines->sum(
        fn ($line) => max(
            0,
            (float) $line->actual_released_quantity - (float) $line->returned_quantity
        )
    );

    $version = $custody->request?->currentVersion;
    $scheduleDate = $version?->schedule_date ?: $version?->needed_from;
    $returnDate = $version?->return_date ?: $version?->return_due_at ?: $custody->due_at;

    $hasActivePickupSchedule = (bool) $custody->scheduled_release_at
        && (bool) $custody->pickup_expires_at
        && ! $custody->pickup_expired_at;

    $isCompleted = $custody->status === 'CLOSED' || $custody->closed_at !== null;

    /*
     * Borrower Cleared vs. Completed (see custody/show.blade.php for the full
     * rule): Completed requires, for linen, that internal Laundry processing
     * has finished AND the Laundry Form has been archived - not archival alone.
     */
    $rowHasLaundryItem = $custody->lines->contains(
        fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
    );
    $rowLaundryJob = $custody->relationLoaded('laundryJob') ? $custody->laundryJob : null;
    $isFullyComplete = $isCompleted
        && (
            ! $rowHasLaundryItem
            || ($rowLaundryJob?->status === 'LAUNDRY_COMPLETED' && $rowLaundryJob?->latestEvidence?->file)
        );

    $operationalLabel = match (true) {
        $isCompleted => $isFullyComplete ? 'Completed' : 'Borrower Cleared',
        $custody->status === 'OBLIGATION_OPEN' => 'Obligation Open',
        $custody->status === 'INCIDENT_OPEN' => 'Incident Open',
        in_array($custody->status, ['RETURN_PROCESSING', 'PARTIALLY_RETURNED'], true) => 'Return Processing',
        $custody->status === 'OVERDUE' => 'Overdue',
        (bool) $custody->released_at => 'Items Released / On Custody',
        (bool) $custody->prepared_at && $hasActivePickupSchedule => 'Ready for Release',
        $hasActivePickupSchedule => 'For Item Preparation',
        $custody->status === 'PREPARING_RELEASE' => 'For Pickup Scheduling',
        default => null,
    };
@endphp

<a
    class="operational-record ui-pressable"
    href="{{ route('custody.show', $custody) }}"
    data-borrowings-record
    data-borrowings-group="{{ $isCompleted ? 'completed' : 'active' }}"
>
    <span class="operational-record-primary">
        <strong>{{ $custody->custody_no }}</strong>
        <span>Request {{ $custody->request?->request_no }}</span>
        <small>
            Schedule {{ optional($scheduleDate)->format('d M Y') }}
            · Return {{ optional($returnDate)->format('d M Y') }}
        </small>
    </span>

    <span class="operational-record-facts">
        <span><small>Pickup</small><strong>{{ optional($custody->scheduled_release_at)->format('d M Y, g:i A') ?: 'Not scheduled' }}</strong></span>
        <span><small>Issued</small><strong>{{ optional($custody->released_at)->format('d M Y, g:i A') ?: 'Not yet' }}</strong></span>

        @if($isCompleted)
            <span>
                <small>{{ $isFullyComplete ? 'Completed' : 'Borrower Cleared' }}</small>
                <strong>{{ optional($custody->closed_at)->format('d M Y, g:i A') ?: ($isFullyComplete ? 'Completed' : 'Borrower Cleared') }}</strong>
            </span>
        @else
            <span>
                <small>{{ $custody->status === 'OVERDUE' ? 'Overdue' : 'On Custody' }}</small>
                <strong>{{ $outstanding + 0 }}</strong>
            </span>
        @endif
    </span>

    <span class="operational-record-action">
        <x-status-badge
            :status="$isCompleted ? 'COMPLETED' : $custody->status"
            :label="$operationalLabel"
        />
        <strong>View<x-icon name="chevron-right" size="16" /></strong>
    </span>
</a>
