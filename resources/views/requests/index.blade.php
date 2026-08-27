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

    <section class="request-browser" aria-label="Borrowing request controls">
        <div class="request-browser-controls">
            <label class="request-search-field">
                <span>Search requests</span>
                <span class="request-control-shell">
                    <span class="search-input-icon" aria-hidden="true">
                        <x-icon name="search" />
                    </span>
                    <input
                        id="request-search"
                        type="search"
                        placeholder="Request no. or purpose"
                        autocomplete="off"
                    >
                </span>
            </label>

            <label class="request-filter-field">
                <span>Status</span>
                <select id="request-status-filter">
                    <option value="all">All requests</option>
                    <option value="action">Action required</option>
                    <option value="review">Under review</option>
                    <option value="approved">Approved / release</option>
                    <option value="custody">Released / on custody</option>
                    <option value="completed">Completed</option>
                    <option value="closed">Rejected / cancelled / inactive</option>
                </select>
            </label>
        </div>
    </section>

    <div class="request-list" id="borrower-request-list" aria-label="My borrowing requests">
        @forelse($requests as $request)
            @php
                $version = $request->currentVersion;
                $custody = $request->custody;
                $custodyStatus = $custody?->status;

                $effectiveCustodyStatus = $custody?->closed_at
                    ? 'CLOSED'
                    : $custodyStatus;

                /*
                 * The borrowing request row may remain APPROVED_READY_FOR_RELEASE
                 * even after the physical custody workflow has advanced.
                 *
                 * Once custody exists, the custody lifecycle is the authoritative
                 * borrower-facing status. closed_at is also accepted as a final
                 * completion signal so older/stale rows cannot still display
                 * "Ready for Release" after final reconciliation.
                 */
                $custodyCompleted =
                    $custody
                    && (
                        $effectiveCustodyStatus === 'CLOSED'
                        || $custody->closed_at !== null
                    );

                $effectiveCustodyStatus =
                    $custodyCompleted
                        ? 'CLOSED'
                        : $custodyStatus;

                /*
                |--------------------------------------------------------------------------
                | Borrower-facing status
                |--------------------------------------------------------------------------
                |
                | Custody status takes priority after physical release.
                | Legacy GSU/VPAF/final-download states remain readable for old records,
                | but the borrower-facing wording follows the current SPMU-only flow.
                |
                */

                $displayStatus = match($effectiveCustodyStatus) {
                    'ACTIVE' => 'ACTIVE',
                    'RETURN_PROCESSING' => 'RETURN_PROCESSING',
                    'PARTIALLY_RETURNED' => 'PARTIALLY_RETURNED',
                    'OVERDUE' => 'OVERDUE',
                    'EARLY_RETURN' => 'RETURN_PROCESSING',
                    'INCIDENT_OPEN' => 'INCIDENT_OPEN',
                    'OBLIGATION_OPEN' => 'OBLIGATION_OPEN',
                    'CLOSED' => 'CLOSED',

                    default => match($request->status) {
                        App\Enums\RequestStatus::UnderGsu,
                        App\Enums\RequestStatus::UnderVpaf
                            => App\Enums\RequestStatus::UnderSpmu,

                        App\Enums\RequestStatus::FinalApprovedAwaitingDownload
                            => App\Enums\RequestStatus::ApprovedReadyForRelease,

                        default => $request->status,
                    },
                };

                $displayStatusLabel = match($effectiveCustodyStatus) {
                    'ACTIVE' => 'Released / On Custody',
                    'RETURN_PROCESSING' => 'Return Processing',
                    'PARTIALLY_RETURNED' => 'Return Processing',
                    'OVERDUE' => 'Overdue',
                    'EARLY_RETURN' => 'Return Processing',
                    'INCIDENT_OPEN' => 'Incident Open',
                    'OBLIGATION_OPEN' => 'Obligation Open',
                    'CLOSED' => 'Completed',

                    default => match($request->status) {
                        App\Enums\RequestStatus::UnderSpmu => 'Under SPMU Review',

                        App\Enums\RequestStatus::UnderGsu,
                        App\Enums\RequestStatus::UnderVpaf => 'Under Review',

                        App\Enums\RequestStatus::FinalApprovedAwaitingDownload,
                        App\Enums\RequestStatus::ApprovedReadyForRelease => 'Approved',

                        default => null,
                    },
                };

                /*
                |--------------------------------------------------------------------------
                | Borrower next action
                |--------------------------------------------------------------------------
                */

                $action = match($effectiveCustodyStatus) {
                    'ACTIVE' => [
                        'Open borrowing',
                        'The items have been released and are currently under your custody.',
                    ],

                    'RETURN_PROCESSING',
                    'PARTIALLY_RETURNED' => [
                        'Open return status',
                        'The borrowing is in return processing. Review the current reconciliation, laundry, or accountability status.',
                    ],

                    'OVERDUE' => [
                        'Review overdue borrowing',
                        'The return deadline has passed. Review the current return and accountability status.',
                    ],

                    'EARLY_RETURN' => [
                        'Open borrowing',
                        'Return processing is recorded for this borrowing.',
                    ],

                    'INCIDENT_OPEN' => [
                        'Review incident',
                        'An incident remains open for this borrowing. Review the recorded details and next steps.',
                    ],

                    'OBLIGATION_OPEN' => [
                        'Review obligations',
                        'The items have been returned, but an outstanding obligation still requires resolution.',
                    ],

                    'CLOSED' => [
                        'View completed borrowing',
                        'This borrowing is complete and its custody record is closed.',
                    ],

                    default => match($request->status) {
                        App\Enums\RequestStatus::Draft => [
                            'Continue request',
                            'Complete the request details and prepare the required documents before submission.',
                        ],

                        App\Enums\RequestStatus::ReturnedForRevision => [
                            'Revise request',
                            'Review the SPMU remarks, correct the request, and prepare it for resubmission.',
                        ],

                        App\Enums\RequestStatus::UnderSpmu => [
                            'View progress',
                            'Submitted to SPMU for review. No action is required unless the request is returned for revision.',
                        ],

                        App\Enums\RequestStatus::UnderGsu,
                        App\Enums\RequestStatus::UnderVpaf => [
                            'View progress',
                            'This legacy request is still being processed. Open the record to review its latest status.',
                        ],

                        App\Enums\RequestStatus::FinalApprovedAwaitingDownload,
                        App\Enums\RequestStatus::ApprovedReadyForRelease => [
                            'View release status',
                            'Your request is approved. Review the request and follow the release instructions from SPMU.',
                        ],

                        App\Enums\RequestStatus::Rejected => [
                            'View decision',
                            'Review the SPMU decision and recorded remarks.',
                        ],

                        App\Enums\RequestStatus::Cancelled => [
                            'View record',
                            'This request was cancelled.',
                        ],

                        App\Enums\RequestStatus::Expired => [
                            'View record',
                            'This request is no longer active. Open the record for its final status.',
                        ],

                        default => [
                            'View details',
                            'Open the request to review its latest recorded status and next step.',
                        ],
                    },
                };

                $requiresAction =
                    ! $custody
                    && in_array(
                        $request->status,
                        [
                            App\Enums\RequestStatus::Draft,
                            App\Enums\RequestStatus::ReturnedForRevision,
                        ],
                        true
                    );

                $statusGroup = match(true) {
                    $effectiveCustodyStatus === 'CLOSED' => 'completed',

                    in_array(
                        $effectiveCustodyStatus,
                        [
                            'ACTIVE',
                            'RETURN_PROCESSING',
                            'PARTIALLY_RETURNED',
                            'OVERDUE',
                            'INCIDENT_OPEN',
                            'OBLIGATION_OPEN',
                        ],
                        true
                    ) => 'custody',

                    $requiresAction => 'action',

                    in_array(
                        $request->status,
                        [
                            App\Enums\RequestStatus::UnderSpmu,
                            App\Enums\RequestStatus::UnderGsu,
                            App\Enums\RequestStatus::UnderVpaf,
                        ],
                        true
                    ) => 'review',

                    in_array(
                        $request->status,
                        [
                            App\Enums\RequestStatus::FinalApprovedAwaitingDownload,
                            App\Enums\RequestStatus::ApprovedReadyForRelease,
                        ],
                        true
                    ) => 'approved',

                    in_array(
                        $request->status,
                        [
                            App\Enums\RequestStatus::Rejected,
                            App\Enums\RequestStatus::Cancelled,
                            App\Enums\RequestStatus::Expired,
                        ],
                        true
                    ) => 'closed',

                    default => 'review',
                };

                $searchText = strtolower(
                    trim(
                        ($request->request_no ?? '')
                        .' '
                        .($version?->purpose_event ?? '')
                        .' '
                        .($displayStatusLabel ?? '')
                    )
                );

                $scheduleStart = optional($version?->needed_from)->format('d M Y');
                $scheduleEnd = optional($version?->return_due_at)->format('d M Y');
            @endphp

            <a
                class="request-list-item ui-pressable {{ $requiresAction ? 'is-action-required' : '' }}"
                href="{{ route('requests.show', $request) }}"
                data-request-card
                data-status-group="{{ $statusGroup }}"
                data-search="{{ $searchText }}"
            >
                <span class="request-list-main">
                    <span class="request-list-purpose">
                        {{ $version?->purpose_event ?: 'Borrowing request' }}
                    </span>

                    <span class="request-list-heading">
                        <span class="record-reference">{{ $request->request_no }}</span>

                        <x-status-badge
                            :status="$displayStatus"
                            :label="$displayStatusLabel"
                        />

                        @if($requiresAction)
                            <span class="request-attention-label">Action required</span>
                        @endif
                    </span>

                    <small>{{ $action[1] }}</small>
                </span>

                <span class="request-list-meta">
                    <span class="request-period-label">Borrowing period</span>

                    <strong>
                        {{ $scheduleStart ?: 'Schedule pending' }}
                        @if($scheduleEnd)
                            <span aria-hidden="true">→</span>
                            {{ $scheduleEnd }}
                        @endif
                    </strong>

                    <small>
                        {{ $version?->items->count() ?? 0 }}
                        {{ ($version?->items->count() ?? 0) === 1 ? 'item type' : 'item types' }}
                        <span aria-hidden="true">·</span>
                        Updated {{ $request->updated_at->format('d M Y') }}
                    </small>
                </span>

                <span class="request-list-action {{ $requiresAction ? 'is-required' : '' }}">
                    {{ $action[0] }}
                    <x-icon name="chevron-right" />
                </span>
            </a>
        @empty
            <div class="empty-state borrower-empty-state request-empty-state">
                <div>
                    <strong>No borrowing requests yet.</strong>
                    <span>Create your first request when you need to borrow institutional property.</span>
                </div>

                <a class="button primary ui-pressable" href="{{ route('requests.create') }}">
                    <x-icon name="plus" size="17" />
                    Create new request
                </a>
            </div>
        @endforelse
    </div>

    @if($requests->isNotEmpty())
        <div
            id="request-filter-empty"
            class="empty-state borrower-empty-state request-empty-state request-filter-empty"
            hidden
        >
            <div>
                <strong>No matching requests.</strong>
                <span>Try another search term or choose a different status filter.</span>
            </div>

            <button class="button secondary ui-pressable" type="button" id="request-filter-reset">
                Clear filters
            </button>
        </div>
    @endif

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
<style>
    .request-browser {
        display: block;
        margin-bottom: 14px;
        padding: 16px 17px;
        background: var(--surface-elevated);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow-sm);
    }

    .request-browser-controls {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(180px, 220px);
        gap: 12px;
        width: 100%;
    }

    .request-search-field,
    .request-filter-field {
        display: grid;
        gap: 5px;
        margin: 0;
        color: var(--text-muted);
        font-size: 11px;
        font-weight: 750;
    }

    .request-control-shell {
        position: relative;
        display: block;
        min-height: 40px;
        background: var(--surface-elevated);
        border: 1px solid var(--border);
        border-radius: 7px;
    }

    .request-control-shell:focus-within {
        border-color: var(--interactive);
        box-shadow: var(--focus-ring);
    }

    .request-control-shell input {
        width: 100%;
        min-height: 38px;
        min-width: 0;
        padding: 0 11px 0 40px;
        background: transparent;
        border: 0;
        box-shadow: none;
        outline: 0;
    }

    .request-filter-field select {
        min-height: 40px;
        margin: 0;
    }

    .request-attention-label {
        display: inline-flex;
        align-items: center;
        min-height: 22px;
        padding: 2px 7px;
        color: var(--warning);
        background: var(--warning-subtle, #fff8e7);
        border: 1px solid var(--warning-border, #ead8a7);
        border-radius: 999px;
        font-size: 10px;
        font-weight: 800;
        white-space: nowrap;
    }

    .request-period-label {
        color: var(--text-muted);
        font-size: 10px;
        font-weight: 800;
        letter-spacing: .04em;
        text-transform: uppercase;
    }

    .request-list-meta > strong {
        color: var(--heading);
        font-size: 12px;
        font-weight: 750;
    }

    .request-filter-empty {
        margin-top: 10px;
    }

    /*
    |--------------------------------------------------------------------------
    | Borrower My Requests row alignment
    |--------------------------------------------------------------------------
    | Keep every request card on the same three-column grid regardless of
    | purpose length, status label width, or action text.
    */
    #borrower-request-list .request-list-item {
        display: grid;
        grid-template-columns:
            minmax(0, 1fr)
            minmax(270px, 350px)
            minmax(170px, 220px);
        align-items: center;
        column-gap: 18px;
    }

    #borrower-request-list .request-list-main {
        min-width: 0;
        padding-right: 4px;
    }

    #borrower-request-list .request-list-purpose,
    #borrower-request-list .request-list-heading,
    #borrower-request-list .request-list-main > small {
        min-width: 0;
    }

    #borrower-request-list .request-list-meta {
        min-width: 0;
        align-self: stretch;
        display: flex;
        flex-direction: column;
        justify-content: center;
        padding-left: 18px;
        border-left: 1px solid var(--border);
    }

    #borrower-request-list .request-list-action {
        min-width: 0;
        display: inline-flex;
        align-items: center;
        justify-content: flex-end;
        gap: 7px;
        justify-self: stretch;
        text-align: right;
        white-space: nowrap;
    }

    #borrower-request-list .request-list-action .ui-icon {
        flex: 0 0 auto;
    }

    @media (max-width: 1100px) {
        #borrower-request-list .request-list-item {
            grid-template-columns:
                minmax(0, 1fr)
                minmax(240px, 310px)
                minmax(145px, 190px);
            column-gap: 14px;
        }
    }

    @media (max-width: 900px) {
        #borrower-request-list .request-list-item {
            grid-template-columns: minmax(0, 1fr) minmax(220px, 280px);
        }

        #borrower-request-list .request-list-action {
            grid-column: 1 / -1;
            justify-content: flex-end;
            padding-top: 8px;
            border-top: 1px solid var(--border);
        }

        .request-browser-controls {
            width: 100%;
        }
    }

    @media (max-width: 620px) {
        #borrower-request-list .request-list-item {
            grid-template-columns: 1fr;
            row-gap: 10px;
        }

        #borrower-request-list .request-list-meta {
            padding-top: 10px;
            padding-left: 0;
            border-top: 1px solid var(--border);
            border-left: 0;
        }

        #borrower-request-list .request-list-action {
            grid-column: auto;
        }

        .request-browser-controls {
            grid-template-columns: 1fr;
        }

        .request-browser {
            padding: 14px;
        }

        .request-list-purpose {
            white-space: normal;
        }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('request-search');
    const statusFilter = document.getElementById('request-status-filter');
    const requestCards = Array.from(document.querySelectorAll('[data-request-card]'));
    const emptyState = document.getElementById('request-filter-empty');
    const resetButton = document.getElementById('request-filter-reset');

    if (!searchInput || !statusFilter || requestCards.length === 0) {
        return;
    }

    const applyFilters = () => {
        const query = searchInput.value.trim().toLowerCase();
        const status = statusFilter.value;
        let visibleCount = 0;

        requestCards.forEach((card) => {
            const matchesSearch = !query || (card.dataset.search || '').includes(query);
            const matchesStatus = status === 'all' || card.dataset.statusGroup === status;
            const visible = matchesSearch && matchesStatus;

            card.hidden = !visible;

            if (visible) {
                visibleCount++;
            }
        });

        if (emptyState) {
            emptyState.hidden = visibleCount !== 0;
        }
    };

    searchInput.addEventListener('input', applyFilters);
    statusFilter.addEventListener('change', applyFilters);

    resetButton?.addEventListener('click', () => {
        searchInput.value = '';
        statusFilter.value = 'all';
        applyFilters();
        searchInput.focus();
    });
});
</script>
@endif

@endsection
