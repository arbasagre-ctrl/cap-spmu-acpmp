@php
    // Display-only ID; links continue to use the original LaundryJob key.
    $caseId = 'LND-'.($job->created_at ? $job->created_at->format('Y').'-' : '').str_pad((string) $job->id, 5, '0', STR_PAD_LEFT);
    $caseBorrower = $job->custody?->borrower?->full_name ?: '—';
    $cleanedQuantity = (int) $job->lines->sum('completed_quantity');
    $maintenanceQuantity = (int) $job->lines->where('issue_type', 'DAMAGED')->sum('affected_quantity');
    $receivedQuantity = (int) $job->lines->sum('received_quantity');
    $hasRecordedQuantities = $job->lines->isNotEmpty()
        && $job->lines->every(fn ($line) => $line->received_quantity !== null);
    $noProcessingRequired = $hasRecordedQuantities && $receivedQuantity === 0 && $job->worker_received_at;
    $caseOutcomes = array_filter([
        $cleanedQuantity > 0 ? 'available' : null,
        $maintenanceQuantity > 0 ? 'maintenance' : null,
    ]);
    if (! $caseOutcomes) {
        $caseOutcomes = [$noProcessingRequired ? 'not-needed' : 'unrecorded'];
    }
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
            @if($cleanedQuantity > 0)
                <span class="completed-laundry-badge is-available" title="{{ $cleanedQuantity }} clean / available">Cleaned /<br>Available</span>
            @endif
            @if($maintenanceQuantity > 0)
                <span class="completed-laundry-badge is-maintenance" title="{{ $maintenanceQuantity }} routed to maintenance">Routed to<br>Maintenance</span>
            @endif
            @if($cleanedQuantity === 0 && $maintenanceQuantity === 0)
                <span class="completed-laundry-badge is-neutral">{{ $noProcessingRequired ? 'No laundry required' : 'Outcome not recorded' }}</span>
            @endif
        </div>
    </td>
    <td><a class="button secondary small ui-pressable completed-laundry-view" href="{{ route('laundry.show', $job) }}" aria-label="View details for {{ $caseId }}">View Details</a></td>
</tr>
