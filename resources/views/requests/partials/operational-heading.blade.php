@php
    // Presentation only: the custody page continues to authorize and gate every action.
    $hasOperationalObligation = (bool) $obligationSummary;

    $nextActionLabel = match (true) {
        $requestIsCancelled => 'No further action required',
        $requestIsCompleted => 'Custody record',
        $detailStatus === 'READY_FOR_RELEASE' => 'Proceed to physical release',
        $detailStatus === 'PREPARING_RELEASE' && (bool) $custody->pickup_expired_at => 'Reschedule the pickup window',
        $detailStatus === 'PREPARING_RELEASE' && ! $hasPickupSchedule => 'Set the pickup schedule',
        $detailStatus === 'PREPARING_RELEASE' => 'Confirm item preparation',
        $hasOperationalObligation => $obligationSummary['title'],
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
        @if($requestIsCompleted)
            <p><span>Related transaction:</span> {{ $nextActionLabel }}</p>
            <a class="button primary ui-pressable request-custody-link" href="{{ route('custody.show', $borrowingRequest->custody) }}">
                View Custody Record <span aria-hidden="true">→</span>
            </a>
        @else
            <p><span>Next action:</span> {{ $nextActionLabel }}</p>
            @if($hasOperationalObligation)
                <a class="button primary ui-pressable request-custody-link" href="{{ route('accountability.index') }}">
                    Open Accountability <span aria-hidden="true">→</span>
                </a>
            @else
                <a class="button primary ui-pressable request-custody-link" href="{{ route('custody.show', $borrowingRequest->custody) }}">
                    Open Custody Record <span aria-hidden="true">→</span>
                </a>
            @endif
        @endif
    </div>
</section>

