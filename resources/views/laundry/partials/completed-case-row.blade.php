@php
    // Display-only ID; links continue to use the original LaundryJob key.
    $caseId = 'LND-'.($job->created_at ? $job->created_at->format('Y').'-' : '').str_pad((string) $job->id, 5, '0', STR_PAD_LEFT);
    $caseBorrower = $job->custody?->borrower?->full_name ?: '—';
    $serviceableQuantity = (int) $job->lines->sum('completed_quantity');
    $receivedQuantity = (int) $job->lines->sum('received_quantity');
    $hasRecordedQuantities = $job->lines->isNotEmpty()
        && $job->lines->every(fn ($line) => $line->received_quantity !== null);

    $adverseConditions = $job->lines
        ->flatMap(fn ($line) => $line->custodyLine?->returnLines ?? collect())
        ->map(fn ($returnLine) => strtoupper((string) $returnLine->condition_code))
        ->filter(fn ($condition) => $condition !== '' && $condition !== 'FINE')
        ->unique()
        ->values();
    $hasAdverseFinding = $adverseConditions->isNotEmpty();

    $expectedReturn = $job->custody?->original_due_at;
    $isLateReturn = $job->worker_received_at && $expectedReturn
        ? $job->worker_received_at->copy()->startOfDay()->gt($expectedReturn->copy()->startOfDay())
        : false;

    $hasAccountability = $hasAdverseFinding || $isLateReturn;
    $caseOutcomes = array_filter([
        $serviceableQuantity > 0 ? 'available' : null,
        $hasAccountability ? 'accountability' : null,
    ]);

    if (! $caseOutcomes) {
        $caseOutcomes = ['completed'];
    }

    $adverseReason = $adverseConditions
        ->map(fn ($condition) => match ($condition) {
            'DAMAGED' => 'Damaged Item',
            'DESTROYED' => 'Destroyed Item',
            'MISSING' => 'Missing Item',
            'LOST' => 'Lost Item',
            'STOLEN' => 'Stolen Item',
            default => ucwords(strtolower(str_replace('_', ' ', $condition))),
        })
        ->implode(' + ');
    $accountabilityReason = collect([
        $isLateReturn ? 'Late Return' : null,
        $hasAdverseFinding ? $adverseReason : null,
    ])->filter()->implode(' + ') ?: null;
    $itemSummary = $hasRecordedQuantities
        ? $job->lines->groupBy(fn ($line) => $line->custodyLine?->requestItem?->unit_snapshot ?: 'items')
            ->map(function ($lines, $unit) {
                $quantity = (int) $lines->sum('received_quantity');
                $label = match(strtolower($unit)) {
                    'piece', 'pieces', 'pc', 'pcs' => 'pcs',
                    'unit', 'units' => $quantity === 1 ? 'unit' : 'units',
                    default => $unit,
                };
                return $quantity.' '.$label;
            })->implode(' · ')
        : '—';
    $completedDate = $job->completed_at ?: $job->worker_completed_at;
    $caseSearch = implode(' ', [
        $caseId,
        $job->id,
        $caseBorrower,
        $job->custody?->request?->request_no,
        $job->custody?->custody_no,
        $job->lines->map(fn ($line) => $line->custodyLine?->requestItem?->description_snapshot)->implode(' '),
    ]);
@endphp
<tr data-completed-record data-search="{{ $caseSearch }}" data-outcomes="{{ implode(' ', $caseOutcomes) }}">
    <td class="completed-laundry-case-id">{{ $caseId }}</td>
    <td>{{ $caseBorrower }}</td>
    <td>{{ $itemSummary }}</td>
    <td class="completed-laundry-date">
        @if($completedDate)
            <time datetime="{{ $completedDate->toIso8601String() }}"><span>{{ $completedDate->format('d M Y') }},</span><span>{{ $completedDate->format('h:i A') }}</span></time>
        @else
            <span>Not recorded</span>
        @endif
    </td>
    <td>
        <div class="completed-laundry-outcomes">
            @if($serviceableQuantity > 0)
                <span class="completed-laundry-badge is-available" title="{{ $serviceableQuantity }} serviceable linen available">Available</span>
            @endif
            @if($hasAccountability)
                <span class="completed-laundry-badge is-maintenance" title="Referred to Accountability Processing: {{ $accountabilityReason }}">Accountability Required<br>{{ $accountabilityReason }}</span>
            @endif
            @if($serviceableQuantity === 0 && ! $hasAccountability)
                <span class="completed-laundry-badge is-neutral">Completed</span>
            @endif
        </div>
    </td>
    <td><a class="button secondary small ui-pressable completed-laundry-view" href="{{ route('laundry.show', $job) }}" aria-label="View details for {{ $caseId }}">View Details</a></td>
</tr>
