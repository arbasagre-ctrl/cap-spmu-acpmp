@extends('layouts.app', ['title' => session('active_workspace') === 'BORROWER' ? 'My Borrowings' : (auth()->user()?->access_classification?->value === 'SPMU_HEAD' ? 'Release & Return Oversight' : (($spmuMode ?? null) === 'return' ? 'Return' : 'Release'))])

@section('content')

@php
    $isBorrower = session('active_workspace') === 'BORROWER';
    $isHead = auth()->user()?->access_classification?->value === 'SPMU_HEAD';
    $mode = $spmuMode ?? null;

    $pageTitle = $isBorrower
        ? 'My Borrowings'
        : ($isHead
            ? 'Release & Return Oversight'
            : ($mode === 'return' ? 'Return' : 'Release'));

    $pageCopy = match (true) {
        $isBorrower => 'Track your pickup, issued items, returns, and completed borrowings.',
        $mode === 'release' => 'Schedule pickup, confirm item preparation, print the required physical documents, and record the actual handover.',
        $mode === 'return' => 'Inspect physically returned items, record full-quantity accounting, monitor linen/laundry return, and complete reconciliation.',
        $isHead => 'Monitor release preparation, active custody, return processing, overdue or unresolved cases, and completed transactions.',
        default => null,
    };

    $isOversightView = ! $isBorrower && $isHead && $mode === null;

    $groupForCustody = fn ($custody): string => $custody->workflowStatus()['group'];

    $priorityForCustody = fn ($custody): int => match ($groupForCustody($custody)) {
        'attention' => 1,
        'return' => 2,
        'custody' => 3,
        'release' => 4,
        'active' => 5,
        'cancelled' => 8,
        'completed' => 9,
        default => 8,
    };

    $borrowerCounts = $isBorrower
        ? [
            'active' => $custodies->filter(fn ($custody) => ! in_array($groupForCustody($custody), ['completed', 'cancelled'], true))->count(),
            'completed' => $custodies->filter(fn ($custody) => $groupForCustody($custody) === 'completed')->count(),
            'cancelled' => $custodies->filter(fn ($custody) => $groupForCustody($custody) === 'cancelled')->count(),
        ]
        : [];

    $oversightTabs = [
        'all' => 'All',
        'active' => 'Active',
        'attention' => 'Needs Attention',
        'release' => 'Release Queue',
        'custody' => 'On Custody',
        'return' => 'Return Processing',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    $oversightTabIcons = [
        'all' => 'dashboard',
        'active' => 'check-circle',
        'attention' => 'warning',
        'release' => 'clock',
        'custody' => 'box',
        'return' => 'cycle',
        'completed' => 'check-circle',
        'cancelled' => 'close',
    ];

    $oversightCounts = $isOversightView
        ? [
            'active' => $custodies->filter(fn ($custody) => ! in_array($groupForCustody($custody), ['completed', 'cancelled'], true))->count(),
            'attention' => $custodies->filter(fn ($custody) => $groupForCustody($custody) === 'attention')->count(),
            'release' => $custodies->filter(fn ($custody) => $groupForCustody($custody) === 'release')->count(),
            'custody' => $custodies->filter(fn ($custody) => $groupForCustody($custody) === 'custody')->count(),
            'return' => $custodies->filter(fn ($custody) => $groupForCustody($custody) === 'return')->count(),
            'completed' => $custodies->filter(fn ($custody) => $groupForCustody($custody) === 'completed')->count(),
            'cancelled' => $custodies->filter(fn ($custody) => $groupForCustody($custody) === 'cancelled')->count(),
            'all' => $custodies->count(),
        ]
        : [];
@endphp

@if($isOversightView)
    @include('custody.partials.oversight-styles')
@elseif($isBorrower)
    @include('custody.partials.borrowings-styles')
@endif

<section class="page-heading">
    <div>
        <p class="eyebrow">
            {{ $mode === 'return' ? 'Physical return and reconciliation' : ($mode === 'release' ? 'Pickup and physical issuance' : 'Pickup, issuance and return') }}
        </p>

        <h1>{{ $pageTitle }}</h1>

        @if($pageCopy)
            <p>{{ $pageCopy }}</p>
        @endif
    </div>
</section>

@if($isOversightView)
    <div class="content-area custody-oversight" data-custody-oversight>
        @include('custody.partials.oversight-toolbar')

        @if($custodies->isEmpty())
            @include('custody.partials.oversight-empty', [
                'emptyId' => null,
                'emptyHidden' => false,
                'emptyTitle' => 'No transactions to display.',
                'emptyMessage' => 'Release and return transactions will appear here when available.',
            ])
        @else
            <div class="custody-oversight-section-head">
                <h2>Transactions</h2>

                <span
                    class="custody-oversight-record-count"
                    id="custody-oversight-result-summary"
                    role="status"
                    aria-live="polite"
                >
                    {{ $custodies->count() }} {{ $custodies->count() === 1 ? 'record' : 'records' }}
                </span>
            </div>

            <div
                id="custody-oversight-list"
                class="custody-oversight-list"
                aria-label="Release and return oversight records"
            >
                @foreach($custodies as $custody)
                    @include('custody.partials.oversight-row')
                @endforeach
            </div>

            @include('custody.partials.oversight-empty', [
                'emptyId' => 'custody-oversight-no-results',
                'emptyHidden' => true,
                'emptyTitle' => 'No matching release or return transaction.',
                'emptyMessage' => 'Try another status tab, clear the search, or adjust the date range.',
            ])

            <div class="custody-oversight-footer" id="custody-oversight-footer">
                <label class="custody-oversight-page-size">
                    Show
                    <select id="custody-oversight-page-size" aria-label="Transactions per page">
                        <option value="5">5</option>
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                    </select>
                    per page
                </label>

                <div
                    class="custody-oversight-pagination"
                    id="custody-oversight-pagination"
                    role="group"
                    aria-label="Release and return oversight pages"
                ></div>
            </div>
        @endif
    </div>

    @include('custody.partials.oversight-interactions')
@elseif($isBorrower)
    <div class="content-area my-borrowings" data-my-borrowings>
        <div class="my-borrowings-card">
            <div class="borrowings-tabs" role="tablist" aria-label="Borrowing status">
                <button
                    class="borrowings-tab is-active"
                    type="button"
                    role="tab"
                    data-borrowings-tab="active"
                    aria-selected="true"
                    aria-controls="borrowings-panel-active"
                >
                    Active Borrowings
                    <span class="borrowings-tab-count">{{ $borrowerCounts['active'] ?? 0 }}</span>
                </button>

                <button
                    class="borrowings-tab"
                    type="button"
                    role="tab"
                    data-borrowings-tab="completed"
                    aria-selected="false"
                    aria-controls="borrowings-panel-completed"
                    tabindex="-1"
                >
                    Completed
                    <span class="borrowings-tab-count">{{ $borrowerCounts['completed'] ?? 0 }}</span>
                </button>


                <button
                    class="borrowings-tab"
                    type="button"
                    role="tab"
                    data-borrowings-tab="cancelled"
                    aria-selected="false"
                    aria-controls="borrowings-panel-cancelled"
                    tabindex="-1"
                >
                    Cancelled
                    <span class="borrowings-tab-count">{{ $borrowerCounts['cancelled'] ?? 0 }}</span>
                </button>
            </div>

            @foreach([
                'active' => [
                    'No active borrowings yet.',
                    'Approved borrowings will appear here once items are allocated and ready for pickup.',
                ],
                'completed' => [
                    'No completed borrowings yet.',
                    'A borrowing moves here once every item is returned and the record is cleared.',
                ],
                'cancelled' => [
                    'No cancelled borrowings.',
                    'Cancelled borrowing records will appear here for reference.',
                ],
            ] as $panel => $emptyCopy)
                @php
                    $panelCustodies = $custodies->filter(function ($custody) use ($panel, $groupForCustody) {
                        $group = $groupForCustody($custody);

                        return match ($panel) {
                            'completed' => $group === 'completed',
                            'cancelled' => $group === 'cancelled',
                            default => ! in_array($group, ['completed', 'cancelled'], true),
                        };
                    });
                @endphp

                <div
                    class="borrowings-panel"
                    id="borrowings-panel-{{ $panel }}"
                    role="tabpanel"
                    data-borrowings-panel="{{ $panel }}"
                    @if($panel !== 'active') hidden @endif
                >
                    @if($panelCustodies->isEmpty())
                        @include('custody.partials.borrowings-empty', [
                            'emptyHidden' => false,
                            'emptyTitle' => $emptyCopy[0],
                            'emptyMessage' => $emptyCopy[1],
                        ])
                    @else
                        <div class="operational-record-list borrowings-list">
                            @foreach($panelCustodies as $custody)
                                @include('custody.partials.borrowings-row')
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    @include('custody.partials.borrowings-interactions')
@else
    <section class="content-area">
        @if(in_array($mode, ['release','return'], true))
        <div class="operational-browser-toolbar">
            <label>Search
                <span class="search-input-shell">
                    <span class="search-input-icon" aria-hidden="true"><x-icon name="search" /></span>
                    <input type="search" id="operational-search" placeholder="Search borrower, request no., custody no., or event..." autocomplete="off">
                </span>
            </label>
            <label>Status
                <select id="operational-status"><option value="all">All statuses</option></select>
            </label>
            <label>Sort
                <select id="operational-sort"><option value="newest">Newest</option><option value="oldest">Oldest</option></select>
            </label>
        </div>
        @endif
        <div class="operational-record-list" id="operational-filter-list">
            @forelse($custodies as $custody)
                @php
                    $outstanding = $custody->lines->sum(
                        fn ($line) => max(
                            0,
                            (float) $line->actual_released_quantity - (float) $line->returned_quantity
                        )
                    );

                    $scheduleDate = $custody->request->currentVersion?->schedule_date
                        ?: $custody->request->currentVersion?->needed_from;

                    $returnDate = $custody->request->currentVersion?->return_date
                        ?: $custody->request->currentVersion?->return_due_at
                        ?: $custody->due_at;

                    $hasActivePickupSchedule = (bool) $custody->scheduled_release_at
                        && (bool) $custody->pickup_expires_at
                        && ! $custody->pickup_expired_at;

                    $activeEarlyReturn = $mode === 'return'
                        && $custody->relationLoaded('earlyReturnRequests')
                        ? $custody->earlyReturnRequests
                            ->first(fn ($earlyReturn) => $earlyReturn->status === 'REQUESTED')
                        : null;

                    $workflowStatus = $custody->workflowStatus();
                    $operationalLabel = $workflowStatus['label'];
                    $operationalStatusKey = $workflowStatus['key'];
                    $isCompleted = $workflowStatus['group'] === 'completed';
                    $isCancelled = $workflowStatus['group'] === 'cancelled';
                    $isFullyComplete = $operationalStatusKey === 'COMPLETED';

                    $detailRoute = match ($mode) {
                        'release' => route('custody.release.show', $custody),
                        'return' => route('custody.return.show', $custody),
                        default => route('custody.show', $custody),
                    };
                @endphp

                <a class="operational-record ui-pressable" href="{{ $detailRoute }}"
                    @if(in_array($mode, ['release','return'], true))
                    data-operational-record
                    data-created="{{ optional($custody->updated_at)->timestamp ?? 0 }}"
                    data-priority="{{ $activeEarlyReturn ? 1 : 0 }}"
                    data-status="{{ $activeEarlyReturn ? 'Early Return Requested' : $operationalLabel }}"
                    data-search="{{ strtolower(trim(($custody->borrower?->full_name ?? '').' '.($custody->request?->request_no ?? '').' '.($custody->custody_no ?? '').' '.($custody->request?->currentVersion?->purpose_event ?? ''))) }}"
                    @endif
                >
                    <span class="operational-record-primary">
                        <strong>{{ $isBorrower ? $custody->custody_no : $custody->borrower->full_name }}</strong>
                        <span>Request {{ $custody->request->request_no }}</span>
                        <small>Schedule {{ optional($scheduleDate)->format('d M Y') }} · Return {{ optional($returnDate)->format('d M Y') }}</small>
                    </span>

                    <span class="operational-record-facts">
                        @if($mode === 'release')
                            <span><small>Pickup</small><strong>{{ optional($custody->scheduled_release_at)->format('d M Y, g:i A') ?: 'Not scheduled' }}</strong></span>
                            <span><small>Preparation</small><strong>{{ $custody->prepared_at ? 'Confirmed' : 'Pending' }}</strong></span>
                            <span><small>Issued</small><strong>Not yet</strong></span>
                        @elseif($mode === 'return')
                            <span><small>Issued</small><strong>{{ optional($custody->released_at)->format('d M Y, g:i A') ?: '—' }}</strong></span>
                            @if($activeEarlyReturn)
                                <span class="early-return-fact">
                                    <small>Early Return</small>
                                    <strong>{{ optional($activeEarlyReturn->proposed_return_at)->format('d M Y, g:i A') ?: 'Schedule pending' }}</strong>
                                </span>
                            @else
                                <span><small>Return Due</small><strong>{{ optional($returnDate)->format('d M Y') ?: '—' }}</strong></span>
                            @endif
                            <span><small>{{ $custody->status === 'OVERDUE' ? 'Overdue' : 'On Custody' }}</small><strong>{{ $outstanding + 0 }}</strong></span>
                        @else
                            <span><small>Pickup</small><strong>{{ optional($custody->scheduled_release_at)->format('d M Y, g:i A') ?: 'Not scheduled' }}</strong></span>
                            <span><small>Issued</small><strong>{{ optional($custody->released_at)->format('d M Y, g:i A') ?: 'Not yet' }}</strong></span>
                            @if($isCancelled)
                                <span><small>Cancelled</small><strong>{{ optional($custody->closed_at)->format('d M Y, g:i A') ?: 'Cancelled' }}</strong></span>
                            @elseif($isCompleted)
                                <span><small>{{ $operationalLabel }}</small><strong>{{ optional($custody->closed_at)->format('d M Y, g:i A') ?: $operationalLabel }}</strong></span>
                            @else
                                <span><small>{{ $custody->status === 'OVERDUE' ? 'Overdue' : 'On Custody' }}</small><strong>{{ $outstanding + 0 }}</strong></span>
                            @endif
                        @endif
                    </span>

                    <span class="operational-record-action">
                        @if($activeEarlyReturn)
                            <x-status-badge status="INFORMATIONAL" label="Early Return Requested" />
                        @endif
                        <x-status-badge
                            :status="$operationalStatusKey"
                            :label="$operationalLabel"
                        />
                        <strong>View<x-icon name="chevron-right" size="16" /></strong>
                    </span>
                </a>
            @empty
                <div class="empty-state">
                    @if($mode === 'release')
                        <strong>No transactions waiting for release.</strong>
                        <span>Approved transactions appear here until physical release is confirmed.</span>
                    @elseif($mode === 'return')
                        <strong>No released transactions to return.</strong>
                        <span>After physical release, the transaction moves here for return tracking and reconciliation.</span>
                    @else
                        <strong>No custody/pickup records.</strong>
                        <span>A record appears after the SPMU Head verifies and approves a request and the approved quantities are allocated/held for pickup.</span>
                    @endif
                </div>
            @endforelse
        </div>
        @if(in_array($mode, ['release','return'], true))
            <div class="empty-state top-gap" id="operational-filter-empty" hidden><strong>No matching records.</strong><span>Try another search term or status.</span></div>
        @endif
    </section>
    @if(in_array($mode, ['release','return'], true))
    <style>
    .operational-browser-toolbar{display:grid;grid-template-columns:minmax(280px,1fr) minmax(190px,230px) minmax(150px,190px);gap:12px;align-items:end;margin-bottom:14px;padding:14px;background:var(--surface-elevated);border:1px solid var(--border);border-radius:var(--radius)}
    .operational-browser-toolbar label{display:grid;gap:6px;font-size:12px;font-weight:800;color:var(--muted)}
    .operational-browser-toolbar input,.operational-browser-toolbar select{min-height:42px;width:100%}
    .early-return-fact small,.early-return-fact strong{color:#0b6f8c}
    .operational-record-action{align-content:center}
    @media(max-width:760px){.operational-browser-toolbar{grid-template-columns:1fr}}
    </style>
    <script>
    (()=>{const list=document.getElementById('operational-filter-list');const rows=[...document.querySelectorAll('[data-operational-record]')];const search=document.getElementById('operational-search');const status=document.getElementById('operational-status');const sort=document.getElementById('operational-sort');const empty=document.getElementById('operational-filter-empty');if(!list||!rows.length||!search||!status||!sort)return;[...new Set(rows.map(r=>r.dataset.status).filter(Boolean))].sort().forEach(v=>{const o=document.createElement('option');o.value=v;o.textContent=v;status.appendChild(o)});const render=()=>{const q=search.value.trim().toLowerCase();const st=status.value;const ordered=[...rows].sort((a,b)=>{const priority=Number(b.dataset.priority||0)-Number(a.dataset.priority||0);if(priority!==0)return priority;return(Number(b.dataset.created)-Number(a.dataset.created))*(sort.value==='newest'?1:-1)});ordered.forEach(r=>list.appendChild(r));let n=0;rows.forEach(r=>{const show=(!q||r.dataset.search.includes(q))&&(st==='all'||r.dataset.status===st);r.hidden=!show;if(show)n++});if(empty)empty.hidden=n>0};search.addEventListener('input',render);status.addEventListener('change',render);sort.addEventListener('change',render);render()})();
    </script>
    @endif
@endif

@endsection
