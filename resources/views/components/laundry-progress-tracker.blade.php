@props(['job', 'inspectionComplete' => null])
@php
    $inspectionComplete = $inspectionComplete ?? ($job->lines->isNotEmpty()
        && $job->lines->every(fn ($line) => $line->custodyLine
            && (float) $line->custodyLine->returned_quantity >= (float) $line->custodyLine->actual_released_quantity));

    $formComplete = $job->hasVerifiedAccomplishedForm();
    $returnEncoded = $inspectionComplete
        || in_array($job->status, ['TURNED_OVER_TO_LAUNDRY', 'LAUNDRY_COMPLETED'], true);
    $available = $job->status === 'LAUNDRY_COMPLETED';
    $legacyReconciliationPending = $job->status === 'TURNED_OVER_TO_LAUNDRY';

    $progressLabel = match (true) {
        $available => 'Available',
        $legacyReconciliationPending => 'Availability Reconciliation Pending',
        $formComplete => 'Ready for SPMU Encoding',
        default => 'Laundry Processing / Form Pending',
    };

    $steps = [
        [
            'label' => 'Laundry Processing',
            'icon' => 'linen',
            'state' => $formComplete ? 'complete' : 'current',
            'description' => 'Laundry Personnel receive the linen, record RECEIVED BY, finish the offline laundry process, fill DATE COMPLETED, and deliver the completed form to SPMU.',
        ],
        [
            'label' => 'SPMU Records Final Form',
            'icon' => 'requests',
            'state' => $returnEncoded ? 'complete' : ($formComplete ? 'current' : 'pending'),
            'description' => 'The Action Officer uploads the completed form and encodes the received quantity plus any reported issue. If no issue was reported, the full received quantity is recorded as Fine / Good.',
        ],
        [
            'label' => 'Available',
            'icon' => 'success',
            'state' => $available ? 'complete' : ($legacyReconciliationPending ? 'current' : 'pending'),
            'description' => $legacyReconciliationPending
                ? 'This older record is waiting for the system one-time reconciliation. No separate Action Officer finalization is required.'
                : 'After SPMU encoding, serviceable linen becomes Available automatically. Adverse or late findings continue through Accountability Processing.',
        ],
    ];
@endphp

<article class="card laundry-progress-card" aria-label="Laundry progress">
    <div class="card-header">
        <div>
            <p class="eyebrow">Laundry tracker</p>
            <h2>Where this linen is now</h2>
        </div>
        <span class="status-badge {{ $available ? 'status-success' : 'status-info' }} laundry-progress-status">{{ $progressLabel }}</span>
    </div>
    <ol class="laundry-progress-rail">
        @foreach($steps as $step)
            @php
                $state = $step['state'];
                $stateLabel = $state === 'complete' ? 'Completed' : ucfirst($state);
            @endphp
            <li class="laundry-progress-step is-{{ $state }}" @if($state === 'current') aria-current="step" @endif>
                <span class="laundry-progress-marker"><x-icon :name="$step['icon']" size="25" /></span>
                <div
                    class="workflow-tracker__interactive laundry-progress-content"
                    data-workflow-step
                    data-workflow-title="{{ $step['label'] }}"
                    data-workflow-meta="{{ $stateLabel }}"
                    data-workflow-description="{{ $step['description'] }}"
                    tabindex="0"
                    aria-label="{{ $step['label'] }}. {{ $stateLabel }}. {{ $step['description'] }}"
                >
                    <strong>{{ $step['label'] }}</strong>
                    <span class="workflow-tracker__meta">{{ $stateLabel }}</span>
                </div>
            </li>
        @endforeach
    </ol>
</article>

<x-workflow-tracker-interactions />
