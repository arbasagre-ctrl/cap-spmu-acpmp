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

    $workflowStatus = $custody->workflowStatus();
    $accountabilityIndicator = $custody->activeAccountabilityIndicator();
    $operationalLabel = $workflowStatus['label'];
    $operationalStatusKey = $workflowStatus['key'];
    $workflowGroup = $workflowStatus['group'];
    $isCompleted = $workflowGroup === 'completed';
    $isCancelled = $workflowGroup === 'cancelled';
@endphp

<a
    class="operational-record ui-pressable"
    href="{{ route('custody.show', $custody) }}"
    data-borrowings-record
    data-borrowings-group="{{ $workflowGroup }}"
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

        @if($isCancelled)
            <span>
                <small>Cancelled</small>
                <strong>{{ optional($custody->closed_at)->format('d M Y, g:i A') ?: 'Cancelled' }}</strong>
            </span>
        @elseif($isCompleted)
            <span>
                <small>{{ $operationalLabel }}</small>
                <strong>{{ optional($custody->closed_at)->format('d M Y, g:i A') ?: $operationalLabel }}</strong>
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
            :status="$operationalStatusKey"
            :label="$operationalLabel"
        />
        @if($accountabilityIndicator)
            <small class="borrowings-accountability-note">{{ $accountabilityIndicator['label'] }}</small>
        @endif
        <strong>View<x-icon name="chevron-right" size="16" /></strong>
    </span>
</a>
