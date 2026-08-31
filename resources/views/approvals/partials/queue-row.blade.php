@php
    $version = $request->currentVersion;
    $submittedAt = $version->submitted_at ?: $request->updated_at;

    $currentSupporting = $version->supportingDocuments->where('is_current', true);

    $requestLetter = $currentSupporting->firstWhere(
        'document_type',
        App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER
    );

    $requiresPtc = (bool) $version->represents_student_activity;

    $ptc = $currentSupporting->firstWhere(
        'document_type',
        App\Models\RequestSupportingDocument::TYPE_PERMISSION_TO_CONDUCT
    );

    /*
     * A queued request is flagged Needs Attention when a document the SPMU
     * Head has to read before deciding is not attached to the current version.
     */
    $missingDocuments = collect([
        $requestLetter ? null : 'Request Letter',
        ($requiresPtc && ! $ptc) ? 'Permission to Conduct' : null,
    ])->filter();

    $needsAttention = $missingDocuments->isNotEmpty();

    $borrowerName = $request->borrower?->full_name ?: 'Borrower';
    $borrowerUnit = $request->borrower?->organizationalUnit;

    // Group borrowers carry their own name as the unit name; show the unit
    // classification for those so the second line still adds something.
    $borrowerContext = match (true) {
        $borrowerUnit?->unit_name && $borrowerUnit->unit_name !== $borrowerName => $borrowerUnit->unit_name,
        (bool) $borrowerUnit?->unit_type => str($borrowerUnit->unit_type)->replace('_', ' ')->title()->value(),
        default => null,
    };

    $searchText = strtolower(trim(
        $request->request_no.' '.
        $borrowerName.' '.
        ($borrowerContext ?? '').' '.
        ($version->purpose_event ?? '').' '.
        $version->items->map(fn ($item) => $item->inventoryItem?->unique_description)->filter()->implode(' ')
    ));
@endphp

<tr
    data-approval-record
    data-created="{{ optional($submittedAt)->timestamp ?? 0 }}"
    data-search="{{ $searchText }}"
>
    <td class="approval-queue-no">{{ $request->request_no }}</td>

    <td class="approval-queue-borrower">
        <strong>{{ $borrowerName }}</strong>

        @if($borrowerContext)
            <small>{{ $borrowerContext }}</small>
        @endif
    </td>

    <td>{{ $version->purpose_event ?: 'Borrowing request' }}</td>

    <td class="approval-queue-submitted">
        @if($submittedAt)
            <time datetime="{{ $submittedAt->toIso8601String() }}">
                <span>{{ $submittedAt->format('d M Y') }},</span>
                <span>{{ $submittedAt->format('h:i A') }}</span>
            </time>
        @else
            <span>Not recorded</span>
        @endif
    </td>

    <td>
        <x-status-badge
            :status="$needsAttention ? 'AWAITING_DOCUMENTS' : 'UNDER_SPMU'"
            :label="$needsAttention ? 'Needs Attention' : 'For Review'"
            :title="$needsAttention ? 'Missing: '.$missingDocuments->implode(', ') : 'Ready for SPMU verification'"
        />
    </td>

    <td>
        <a
            class="button secondary small ui-pressable approval-queue-review"
            href="{{ route('requests.show', $request) }}"
            aria-label="Review {{ $request->request_no }}"
        >
            Review
        </a>
    </td>
</tr>
