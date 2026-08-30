@props(['job'])
@php
    $status = (string) $job->status;
    $current = match($status) {
        'FOR_LAUNDRY' => 1,
        'TURNED_OVER_TO_LAUNDRY' => 2,
        'LAUNDRY_COMPLETED' => 3,
        default => 1,
    };
    $steps = [
        1 => [
            'Return Inspection & Laundry Turnover',
            'SPMU records the borrower return condition. The same printed Laundry Form is then handed to Laundry Personnel for the handwritten Received by signature.',
        ],
        2 => [
            'Borrower Cleared / Internal Laundry',
            'Laundry Personnel have physically received the linen. The borrower no longer waits for washing; processing continues inside the Laundry Area.',
        ],
        3 => [
            'Laundry Completed / Available',
            'Laundry processing is complete and clean/serviceable linen is Available for future borrowing in the Laundry Area.',
        ],
    ];
@endphp

<article class="card laundry-progress-card" aria-label="Laundry progress">
    <div class="card-header">
        <div>
            <p class="eyebrow">Laundry tracker</p>
            <h2>Where this linen is now</h2>
        </div>
        <x-status-badge :status="$job->status" />
    </div>
    <ol class="laundry-progress-rail">
        @foreach($steps as $index => [$label, $description])
            @php
                $state = $index < $current
                    ? 'complete'
                    : ($index === $current ? 'current' : 'pending');
            @endphp
            <li class="laundry-progress-step is-{{ $state }}" @if($state === 'current') aria-current="step" @endif>
                <span class="laundry-progress-marker">{{ $state === 'complete' ? '✓' : $index }}</span>
                <div
                    class="workflow-tracker__interactive"
                    data-workflow-step
                    data-workflow-title="{{ $label }}"
                    data-workflow-meta="{{ $state === 'complete' ? 'Completed' : ($state === 'current' ? 'Current' : 'Pending') }}"
                    data-workflow-description="{{ $description }}"
                    tabindex="0"
                    aria-label="{{ $label }}. {{ $state === 'complete' ? 'Completed' : ($state === 'current' ? 'Current' : 'Pending') }}. {{ $description }}"
                >
                    <strong>{{ $label }}</strong>
                    <span class="workflow-tracker__meta">
                        {{ $state === 'complete' ? 'Completed' : ($state === 'current' ? 'Current' : 'Pending') }}
                    </span>
                </div>
            </li>
        @endforeach
    </ol>
</article>

<x-workflow-tracker-interactions />
