@php
    // Presentation only: the custody page continues to authorize and gate every action.
    $nextActionLabel = match (true) {
        $requestIsCompleted => 'Review the custody record',
        $detailStatus === 'READY_FOR_RELEASE' => 'Proceed to physical release',
        $detailStatus === 'PREPARING_RELEASE' && (bool) $custody->pickup_expired_at => 'Reschedule the pickup window',
        $detailStatus === 'PREPARING_RELEASE' && ! $hasPickupSchedule => 'Set the pickup schedule',
        $detailStatus === 'PREPARING_RELEASE' => 'Confirm item preparation',
        in_array($detailStatus, ['OBLIGATION_OPEN', 'INCIDENT_OPEN'], true) => 'Review outstanding obligations',
        $detailStatus === 'OVERDUE' => 'Process the overdue return',
        (bool) $custody->released_at => 'Continue return processing',
        default => 'Review the custody record',
    };
@endphp

<section class="page-heading request-operational-heading" aria-labelledby="operational-request-title">
    <div class="request-operational-identity">
        <div class="request-operational-title-row">
            <h1 id="operational-request-title">{{ $borrowingRequest->request_no }}</h1>
            <x-status-badge :status="$detailStatus" :label="$detailStatusLabel" />
        </div>
        <p>{{ $v->purpose_event }}</p>
    </div>

    <div class="request-operational-next-action">
        <p><span>Next action:</span> {{ $nextActionLabel }}</p>
        <a class="button primary ui-pressable request-custody-link" href="{{ route('custody.show', $borrowingRequest->custody) }}">
            Open Custody Record
            <svg class="ui-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3h7v7M21 3l-9 9M10 5H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5" /></svg>
        </a>
    </div>
</section>
