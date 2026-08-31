@props(['job', 'inspectionComplete' => null])
@php
    $inspectionComplete = $inspectionComplete ?? ($job->lines->isNotEmpty()
        && $job->lines->every(fn ($line) => $line->custodyLine
            && (float) $line->custodyLine->returned_quantity >= (float) $line->custodyLine->actual_released_quantity));
    $turnoverComplete = in_array($job->status, ['TURNED_OVER_TO_LAUNDRY', 'LAUNDRY_COMPLETED'], true);
    $laundryComplete = $job->status === 'LAUNDRY_COMPLETED';
    $progressLabel = match (true) {
        $laundryComplete => 'Laundry Complete / Available',
        $turnoverComplete => 'Internal Laundry Processing',
        $inspectionComplete => 'Awaiting Laundry Turnover',
        default => 'Awaiting Return Inspection',
    };
    $steps = [
        [
            'label' => 'Return Inspection',
            'icon' => 'requests',
            'state' => $inspectionComplete ? 'complete' : 'current',
            'description' => 'SPMU records the quantity and condition of all returned linen before Laundry Turnover can be confirmed.',
        ],
        [
            'label' => 'Laundry Turnover',
            'icon' => 'custody',
            'state' => $turnoverComplete ? 'complete' : ($inspectionComplete ? 'current' : 'locked'),
            'description' => 'After Return Inspection, Laundry Personnel physically receive the linen and wet-sign Received by on the same printed Laundry Form. The borrower no longer waits for washing.',
        ],
        [
            'label' => 'Laundry Complete / Available',
            'icon' => 'approval',
            'state' => $laundryComplete ? 'complete' : ($turnoverComplete ? 'current' : 'pending'),
            'description' => 'Internal washing is completed and clean/serviceable linen becomes Available for future borrowing in the Laundry Area.',
        ],
    ];
@endphp

<article class="card laundry-progress-card" aria-label="Laundry progress">
    <div class="card-header">
        <div>
            <p class="eyebrow">Laundry tracker</p>
            <h2>Where this linen is now</h2>
        </div>
        <span class="status-badge {{ $laundryComplete ? 'status-success' : 'status-info' }} laundry-progress-status">{{ $progressLabel }}</span>
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
