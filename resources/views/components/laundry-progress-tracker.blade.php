@props(['job', 'inspectionComplete' => null])
@php
    $inspectionComplete = $inspectionComplete ?? ($job->lines->isNotEmpty()
        && $job->lines->every(fn ($line) => $line->custodyLine
            && (float) $line->custodyLine->returned_quantity >= (float) $line->custodyLine->actual_released_quantity));

    $formComplete = $job->hasVerifiedAccomplishedForm();
    $returnEncoded = $inspectionComplete
        || in_array($job->status, ['TURNED_OVER_TO_LAUNDRY', 'LAUNDRY_COMPLETED'], true);
    $laundryProcessing = $job->status === 'TURNED_OVER_TO_LAUNDRY';
    $laundryComplete = $job->status === 'LAUNDRY_COMPLETED';

    $progressLabel = match (true) {
        $laundryComplete => 'Clean & Available',
        $laundryProcessing => 'Laundry Processing',
        $returnEncoded => 'Return Encoded',
        $formComplete => 'Ready for SPMU Encoding',
        default => 'Accomplished Form Pending',
    };

    $steps = [
        [
            'label' => 'SPMU Return Encoding',
            'icon' => 'requests',
            'state' => $returnEncoded ? 'complete' : 'current',
            'description' => $formComplete
                ? 'The accomplished Laundry Form is ready for Action Officer encoding.'
                : 'Upload the accomplished Laundry Form before encoding the linen return.',
        ],
        [
            'label' => 'Laundry Processing',
            'icon' => 'linen',
            'state' => $laundryComplete ? 'complete' : ($laundryProcessing ? 'current' : 'pending'),
            'description' => 'Serviceable returned linen is washed in the Laundry Area.',
        ],
        [
            'label' => 'Available',
            'icon' => 'approval',
            'state' => $laundryComplete ? 'complete' : 'pending',
            'description' => 'Clean serviceable linen is available for future borrowing.',
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
