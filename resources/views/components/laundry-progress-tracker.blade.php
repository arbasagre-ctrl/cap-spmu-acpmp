@props(['job'])
@php
    $status = (string) $job->status;
    $current = match($status) {
        'FOR_LAUNDRY' => 1,
        'IN_PROCESS' => 2,
        'READY_FOR_SPMU_RETURN' => 3,
        'AWAITING_FINAL_FORM_UPLOAD', 'FORM_REPLACEMENT_REQUIRED' => 4,
        'LAUNDRY_COMPLETED' => 5,
        default => 1,
    };
    $steps = [
        1 => ['Borrower Turnover', 'Borrower signs the physical turnover portion and brings used linen + the printed Laundry Form to the SPMU Action Officer.'],
        2 => ['Laundry Processing', 'The SPMU Action Officer records actual receipt, processing results, quantities, and condition.'],
        3 => ['Final SPMU Acceptance', 'SPMU performs final inspection and obtains the required authorized signature on the same physical form.'],
        4 => ['Final Form Upload', 'The SPMU Action Officer scans and archives the fully signed form.'],
        5 => ['Completed', 'Final signed form is archived and the Laundry transaction is settled.'],
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
