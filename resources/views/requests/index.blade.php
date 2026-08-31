@extends('layouts.app', ['title' => session('active_workspace') === 'BORROWER' ? 'My Requests' : 'Borrowing Requests'])

@section('content')
@php
    $isBorrower = session('active_workspace') === 'BORROWER';
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
            <select data-record-status-filter><option value="all">All statuses</option></select>
        </label>
        <label>Sort
            <select data-record-sort><option value="newest">Newest</option><option value="oldest">Oldest</option></select>
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
                    $recordStatus = $request->custody?->closed_at
                        ? 'COMPLETED'
                        : ($request->custody?->status ?: $request->status->value);
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
                        @php
                            $custody = $request->custody;
                            $custodyStatus = $custody?->status;

                $effectiveCustodyStatus = $custody?->closed_at
                    ? 'CLOSED'
                    : $custodyStatus;

                            /*
                             * Request Records must reflect the real custody lifecycle,
                             * not a stale request-level approval status.
                             *
                             * Example:
                             * borrowing_requests.status = APPROVED_READY_FOR_RELEASE
                             * custody_transactions.status = CLOSED
                             *
                             * The correct visible status is Completed.
                             */
                            $custodyCompleted =
                                $custody
                                && (
                                    $custodyStatus === 'CLOSED'
                                    || $custody->closed_at !== null
                                );

                            $effectiveCustodyStatus =
                                $custodyCompleted
                                    ? 'CLOSED'
                                    : $custodyStatus;

                            $tableDisplayStatus = match($effectiveCustodyStatus) {
                                'ACTIVE' => 'ACTIVE',
                                'RETURN_PROCESSING' => 'RETURN_PROCESSING',
                                'PARTIALLY_RETURNED' => 'RETURN_PROCESSING',
                                'OVERDUE' => 'OVERDUE',
                                'EARLY_RETURN' => 'RETURN_PROCESSING',
                                'INCIDENT_OPEN' => 'INCIDENT_OPEN',
                                'OBLIGATION_OPEN' => 'OBLIGATION_OPEN',
                                'CLOSED' => 'COMPLETED',
                                default => $request->status,
                            };

                            $tableDisplayLabel = match($effectiveCustodyStatus) {
                                'ACTIVE' => 'Released / On Custody',
                                'RETURN_PROCESSING',
                                'PARTIALLY_RETURNED' => 'Return Processing',
                                'OVERDUE' => 'Overdue',
                                'EARLY_RETURN' => 'Return Processing',
                                'INCIDENT_OPEN' => 'Incident Open',
                                'OBLIGATION_OPEN' => 'Obligation Open',
                                'CLOSED' => 'Completed',
                                default => null,
                            };
                        @endphp

                        <x-status-badge
                            :status="$tableDisplayStatus"
                            :label="$tableDisplayLabel"
                        />
                    </td>

                    <td>
                        <a class="table-action" href="{{ route('requests.show', $request) }}">
                            View details
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
.record-browser-toolbar label{display:grid;gap:6px;font-size:12px;font-weight:800;color:var(--muted)}
.record-browser-toolbar input,.record-browser-toolbar select{width:100%;min-height:42px}
@media(max-width:760px){.record-browser-toolbar{grid-template-columns:1fr}}
</style>
<script>
(() => {
 const rows=[...document.querySelectorAll('[data-request-record]')];
 const search=document.querySelector('[data-record-search]'); const status=document.querySelector('[data-record-status-filter]'); const sort=document.querySelector('[data-record-sort]'); const empty=document.querySelector('[data-record-empty]');
 if(!rows.length || !search || !status || !sort) return;
 const labels={PREPARING_RELEASE:'Preparing Release',ACTIVE:'Released / On Custody',RETURN_PROCESSING:'Return Processing',PARTIALLY_RETURNED:'Return Processing',OVERDUE:'Overdue',EARLY_RETURN:'Return Processing',INCIDENT_OPEN:'Incident Open',OBLIGATION_OPEN:'Obligation Open',CLOSED:'Completed',COMPLETED:'Completed',DRAFT:'Draft',UNDER_SPMU:'Under SPMU Review',APPROVED_READY_FOR_RELEASE:'Approved / Ready for Release',FINAL_APPROVED_AWAITING_DOWNLOAD:'Approved',RETURNED_FOR_REVISION:'Returned for Revision',REJECTED:'Rejected',CANCELLED:'Cancelled'};
 [...new Set(rows.map(r=>r.dataset.status).filter(Boolean))].sort().forEach(v=>{const o=document.createElement('option');o.value=v;o.textContent=labels[v]||v.replaceAll('_',' ').toLowerCase().replace(/\b\w/g,c=>c.toUpperCase());status.appendChild(o)});
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
