@extends('layouts.app', ['title' => session('active_workspace') === 'BORROWER' ? 'My Requests' : 'Borrowing Requests'])

@section('content')
@php
    $isBorrower = session('active_workspace') === 'BORROWER';
    $classification = auth()->user()?->access_classification?->value;
    $isActionOfficer = ! $isBorrower && $classification === 'SPMU_OFFICER';
    $isSpmuHead = ! $isBorrower && $classification === 'SPMU_HEAD';

    /*
     * Request Records uses only the user-facing statuses that are actually
     * represented by the current records. Internal/retired workflow names are
     * normalized to the current SPMU terminology instead of appearing as
     * separate filter choices.
     */
    $requestRecordStatus = static function ($request) use ($isBorrower, $isActionOfficer, $isSpmuHead): array {
        if ($request->custody) {
            $workflow = $request->custody->workflowStatus();

            return match ($workflow['key']) {
                'INCIDENT_OPEN', 'OBLIGATION_OPEN' => [
                    'key' => 'ACCOUNTABILITY_PENDING',
                    'label' => 'Accountability Pending',
                    'group' => 'attention',
                ],
                'BORROWED' => [
                    'key' => 'BORROWED',
                    'label' => 'Items Released / On Custody',
                    'group' => 'custody',
                ],
                default => $workflow,
            };
        }

        $status = $request->status;
        $rawKey = strtoupper($status instanceof BackedEnum ? $status->value : (string) $status);

        return match ($rawKey) {
            'DRAFT' => ['key' => 'DRAFT', 'label' => 'Draft', 'group' => 'request'],
            'SIGNED' => ['key' => 'SUBMITTED', 'label' => 'Submitted', 'group' => 'request'],
            'SUBMITTED' => ['key' => 'SUBMITTED', 'label' => 'Submitted', 'group' => 'request'],
            'UNDER_SPMU', 'UNDER_GSU', 'UNDER_VPAF' => (function () use ($request, $isBorrower, $isActionOfficer, $isSpmuHead): array {
                if ($isBorrower) {
                    return [
                        'key' => 'UNDER_SPMU_REVIEW',
                        'label' => 'Under SPMU Review',
                        'group' => 'request',
                    ];
                }

                if ($isActionOfficer) {
                    $steps = $request->currentVersion?->approvalSteps ?? collect();
                    $verificationPending = $steps->contains(
                        fn ($step) => (int) $step->sequence_no === 1
                            && strtoupper((string) ($step->stage_code instanceof BackedEnum ? $step->stage_code->value : $step->stage_code)) === 'SPMU'
                            && in_array((string) $step->decision, ['PENDING', 'RECEIVED'], true)
                    );

                    if ($verificationPending) {
                        return [
                            'key' => 'FOR_VERIFICATION',
                            'label' => 'For Verification',
                            'group' => 'request',
                        ];
                    }
                }

                return [
                    'key' => 'FOR_APPROVAL',
                    'label' => $isSpmuHead ? 'For Approval' : 'For Approval',
                    'group' => 'request',
                ];
            })(),
            'RETURNED_FOR_REVISION' => [
                'key' => 'RETURNED_FOR_REVISION',
                'label' => 'Returned for Revision',
                'group' => 'request',
            ],
            'FINAL_APPROVED_AWAITING_DOWNLOAD' => [
                'key' => 'APPROVED',
                'label' => 'Approved',
                'group' => 'request',
            ],
            'APPROVED_READY_FOR_RELEASE' => [
                'key' => 'READY_FOR_RELEASE',
                'label' => 'Ready for Release',
                'group' => 'request',
            ],
            'REJECTED' => ['key' => 'REJECTED', 'label' => 'Rejected', 'group' => 'request'],
            'CANCELLED' => ['key' => 'CANCELLED', 'label' => 'Cancelled', 'group' => 'request'],
            'EXPIRED' => ['key' => 'INACTIVE', 'label' => 'Inactive', 'group' => 'request'],
            default => [
                'key' => $rawKey,
                'label' => str($rawKey)->replace('_', ' ')->lower()->title()->toString(),
                'group' => 'request',
            ],
        };
    };

    $requestStatusOrder = [
        'DRAFT' => 10,
        'SUBMITTED' => 20,
        'UNDER_SPMU_REVIEW' => 30,
        'FOR_VERIFICATION' => 30,
        'FOR_APPROVAL' => 35,
        'RETURNED_FOR_REVISION' => 40,
        'APPROVED' => 50,
        'PICKUP_SCHEDULING' => 60,
        'ITEM_PREPARATION' => 70,
        'PICKUP_SCHEDULED' => 80,
        'READY_FOR_RELEASE' => 90,
        'PREPARING_RELEASE' => 100,
        'PICKUP_EXPIRED' => 110,
        'BORROWED' => 120,
        'RETURN_PROCESSING' => 130,
        'OVERDUE' => 140,
        'ACCOUNTABILITY_PENDING' => 150,
        'BORROWER_CLEARED' => 160,
        'COMPLETED' => 170,
        'CANCELLED' => 180,
        'REJECTED' => 190,
        'INACTIVE' => 200,
    ];

    /*
     * Build the dropdown from the same records/status labels rendered below.
     * This keeps the list short: no unused statuses and no legacy GSU/VPAF
     * entries just because those enum values still exist for old data.
     */
    $requestRecordsForFilters = method_exists($requests, 'items')
        ? collect($requests->items())
        : collect($requests);

    $requestFilterStatuses = $requestRecordsForFilters
        ->map($requestRecordStatus)
        ->unique('key')
        ->sortBy(fn (array $status): int => $requestStatusOrder[$status['key']] ?? 999)
        ->values();
@endphp

<section class="page-heading">
    <div>
        <p class="eyebrow">Request tracking</p>
        <h1>{{ $isBorrower ? 'My Requests' : 'Borrowing request records' }}</h1>

        @if($isBorrower)
            <p>Review your borrowing requests, current status, required actions, and release progress.</p>
        @endif
    </div>

    @if($isBorrower)
        <a class="button primary ui-pressable" href="{{ route('requests.create') }}">
            <x-icon name="plus" size="17" />
            Create new request
        </a>
    @endif
</section>

<section class="content-area">
@if($isBorrower)

<div class="my-requests">
    @if($requests->isEmpty())
        @include('requests.partials.my-requests-empty')
    @else
        @include('requests.partials.my-requests-toolbar')

        @include('requests.partials.my-requests-results')
    @endif
</div>

@else

    <div class="record-browser-toolbar" data-record-browser-toolbar>
        <label class="record-browser-search">Search
            <span class="search-input-shell">
                <span class="search-input-icon" aria-hidden="true"><x-icon name="search" /></span>
                <input type="search" data-record-search placeholder="Search request no., borrower, event, or item..." autocomplete="off">
            </span>
        </label>
        <label>Status
            <select data-record-status-filter>
                <option value="all">All statuses</option>
                @foreach($requestFilterStatuses as $filterStatus)
                    <option value="{{ $filterStatus['key'] }}">{{ $filterStatus['label'] }}</option>
                @endforeach
            </select>
        </label>
        <label>Sort
            <select data-record-sort><option value="newest">Newest first</option><option value="oldest">Oldest first</option></select>
        </label>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Request</th>
                    <th>Borrower</th>
                    <th>Event and period</th>
                    <th>Items</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>

            <tbody>
            @forelse($requests as $request)
                @php
                    $recordWorkflowStatus = $requestRecordStatus($request);
                    $recordStatus = $recordWorkflowStatus['key'];
                    $recordStatusLabel = $recordWorkflowStatus['label'];
                    $recordSearch = strtolower(trim(
                        $request->request_no.' '.
                        ($request->borrower?->full_name ?? '').' '.
                        ($request->currentVersion?->purpose_event ?? '').' '.
                        $request->currentVersion?->items?->map(fn($ri) => $ri->inventoryItem?->unique_description)->filter()->implode(' ')
                    ));
                @endphp
                <tr data-request-record data-search="{{ $recordSearch }}" data-status="{{ $recordStatus }}" data-created="{{ optional($request->created_at)->timestamp ?? 0 }}">
                    <td>
                        <strong>{{ $request->request_no }}</strong>
                        <small>Version {{ $request->current_version_no }}</small>
                    </td>

                    <td>{{ $request->borrower->full_name }}</td>

                    <td>
                        {{ $request->currentVersion?->purpose_event }}

                        <small>
                            {{ optional($request->currentVersion?->needed_from)->format('d M Y, g:i A') }}
                            to
                            {{ optional($request->currentVersion?->return_due_at)->format('d M Y') }}
                        </small>
                    </td>

                    <td>
                        {{ $request->currentVersion?->items->count() ?? 0 }} item type(s)
                    </td>

                    <td>
                        <x-status-badge
                            :status="$recordStatus"
                            :label="$recordStatusLabel"
                        />
                    </td>

                    <td>
                        <a class="table-action" href="{{ route('requests.show', $request) }}">
                            <span>View Request</span>
                            <x-icon name="arrow-right" size="14" style="margin-left:4px" />
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="empty-state">
                        No borrowing requests found.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="empty-state top-gap" data-record-empty hidden><strong>No matching request records.</strong><span>Try another search term or status.</span></div>

@endif
</section>

@unless($isBorrower)
<style>
.record-browser-toolbar{display:grid;grid-template-columns:minmax(260px,1fr) minmax(170px,220px) minmax(150px,190px);gap:12px;align-items:end;margin-bottom:14px;padding:14px;background:var(--surface-elevated);border:1px solid var(--border);border-radius:var(--radius)}
.record-browser-toolbar label{display:grid;gap:6px;font-size:12px;font-weight:800;color:var(--text-muted)}
.record-browser-toolbar input,.record-browser-toolbar select{width:100%;min-height:42px}
@media(max-width:760px){.record-browser-toolbar{grid-template-columns:1fr}}
</style>
<script>
(() => {
 const rows=[...document.querySelectorAll('[data-request-record]')];
 const search=document.querySelector('[data-record-search]'); const status=document.querySelector('[data-record-status-filter]'); const sort=document.querySelector('[data-record-sort]'); const empty=document.querySelector('[data-record-empty]');
 if(!rows.length || !search || !status || !sort) return;
 const body=rows[0].parentElement;
 const render=()=>{const q=search.value.trim().toLowerCase(); const st=status.value; const ordered=[...rows].sort((a,b)=>(Number(b.dataset.created)-Number(a.dataset.created))*(sort.value==='newest'?1:-1)); ordered.forEach(r=>body.appendChild(r)); let n=0; rows.forEach(r=>{const show=(!q||r.dataset.search.includes(q))&&(st==='all'||r.dataset.status===st);r.hidden=!show;if(show)n++}); if(empty)empty.hidden=n>0;};
 [search,status,sort].forEach(el=>el.addEventListener(el===search?'input':'change',render)); render();
})();
</script>
@endunless

@if($isBorrower)
@include('requests.partials.my-requests-styles')
@include('requests.partials.my-requests-scripts')
@endif

@endsection
