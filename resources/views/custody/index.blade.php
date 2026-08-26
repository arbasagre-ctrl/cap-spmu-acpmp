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
        $isBorrower => 'Use My Borrowings for pickup schedules, items issued to you, outstanding returns, linen/laundry progress, and final reconciliation. Request approval and documents stay under My Requests.',
        $mode === 'release' => 'Schedule pickup, confirm item preparation, print the required physical documents, and record the actual handover.',
        $mode === 'return' => 'Inspect physically returned items, record full-quantity accounting, monitor linen/laundry return, and complete reconciliation.',
        $isHead => 'Monitor release preparation, active custody, return processing, overdue or unresolved cases, and completed transactions.',
        default => null,
    };

    $isOversightView = ! $isBorrower && $isHead && $mode === null;

    $groupForCustody = function ($custody): string {
        if ($custody->status === 'CLOSED' || $custody->closed_at !== null) {
            return 'completed';
        }

        if (in_array($custody->status, ['OVERDUE', 'INCIDENT_OPEN', 'OBLIGATION_OPEN'], true)) {
            return 'attention';
        }

        if (in_array($custody->status, ['RETURN_PROCESSING', 'PARTIALLY_RETURNED', 'EARLY_RETURN'], true)) {
            return 'return';
        }

        if ($custody->released_at) {
            return 'custody';
        }

        if ($custody->status === 'PREPARING_RELEASE') {
            return 'release';
        }

        return 'active';
    };

    $priorityForCustody = fn ($custody): int => match ($groupForCustody($custody)) {
        'attention' => 1,
        'return' => 2,
        'custody' => 3,
        'release' => 4,
        'active' => 5,
        'completed' => 9,
        default => 8,
    };

    $oversightCounts = $isOversightView
        ? [
            'active' => $custodies->filter(fn ($custody) => $groupForCustody($custody) !== 'completed')->count(),
            'attention' => $custodies->filter(fn ($custody) => $groupForCustody($custody) === 'attention')->count(),
            'release' => $custodies->filter(fn ($custody) => $groupForCustody($custody) === 'release')->count(),
            'custody' => $custodies->filter(fn ($custody) => $groupForCustody($custody) === 'custody')->count(),
            'return' => $custodies->filter(fn ($custody) => $groupForCustody($custody) === 'return')->count(),
            'completed' => $custodies->filter(fn ($custody) => $groupForCustody($custody) === 'completed')->count(),
            'all' => $custodies->count(),
        ]
        : [];
@endphp

@if($isOversightView)
<style>
    .custody-oversight-workspace {
        --oversight-line: var(--border, #d7e0ea);
        --oversight-muted: var(--text-muted, #64748b);
        --oversight-ink: var(--text, #18324a);
        --oversight-soft: var(--surface-subtle, #f7f9fc);
        display: grid;
        gap: 14px;
    }

    .custody-oversight-toolbar {
        display: grid;
        gap: 12px;
        padding: 14px;
        border: 1px solid var(--oversight-line);
        border-radius: 12px;
        background: var(--surface, #fff);
    }

    .custody-oversight-tabs {
        display: flex;
        align-items: center;
        gap: 7px;
        flex-wrap: wrap;
    }

    .custody-oversight-tab {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        min-height: 36px;
        padding: 7px 11px;
        border: 1px solid var(--oversight-line);
        border-radius: 999px;
        background: var(--surface, #fff);
        color: var(--oversight-muted);
        font: inherit;
        font-size: 12px;
        font-weight: 800;
        line-height: 1;
        cursor: pointer;
    }

    .custody-oversight-tab:hover {
        border-color: #b8c9dc;
        color: var(--oversight-ink);
        background: var(--oversight-soft);
    }

    .custody-oversight-tab.is-active {
        border-color: #9fc8ec;
        background: #eaf5ff;
        color: #075ea8;
    }

    .custody-oversight-tab-count {
        display: inline-grid;
        place-items: center;
        min-width: 22px;
        height: 22px;
        padding: 0 6px;
        border-radius: 999px;
        background: rgba(15, 42, 67, .07);
        font-size: 10px;
        font-weight: 900;
    }

    .custody-oversight-filters {
        display: grid;
        grid-template-columns: minmax(280px, 1.4fr) minmax(170px, .45fr) minmax(170px, .45fr) auto;
        gap: 10px;
        align-items: end;
    }

    .custody-oversight-filters label {
        min-width: 0;
        margin: 0;
    }

    .custody-oversight-filters input {
        width: 100%;
        margin-top: 7px;
    }

    .custody-oversight-date-error {
        margin: -2px 0 0;
        padding: 9px 11px;
        border: 1px solid #efb5b0;
        border-radius: 9px;
        background: #fff3f2;
        color: #a52a23;
        font-size: 11px;
        font-weight: 700;
        line-height: 1.4;
    }

    .custody-oversight-date-error[hidden] {
        display: none !important;
    }

    .custody-oversight-filters input.is-invalid {
        border-color: #c53b32;
        box-shadow: 0 0 0 2px rgba(197, 59, 50, .08);
    }

    .custody-oversight-summary {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
        color: var(--oversight-muted);
        font-size: 12px;
    }

    .custody-oversight-summary strong {
        color: var(--oversight-ink);
    }

    .custody-operations-list {
        display: grid;
        gap: 10px;
    }

    .custody-operations-list .operational-record {
        transition: transform .14s ease, box-shadow .14s ease, border-color .14s ease;
    }

    .custody-operations-list .operational-record:hover {
        transform: translateY(-1px);
        box-shadow: 0 8px 20px rgba(15, 42, 67, .07);
    }

    .custody-operations-list .operational-record[data-custody-group="attention"] { border-left-color: #c53b32; }
    .custody-operations-list .operational-record[data-custody-group="return"] { border-left-color: #b97a05; }
    .custody-operations-list .operational-record[data-custody-group="custody"] { border-left-color: #1268df; }
    .custody-operations-list .operational-record[data-custody-group="completed"] { border-left-color: #21865b; }

    .custody-outstanding-value.has-outstanding {
        font-size: 16px;
        font-weight: 900;
        color: var(--oversight-ink);
    }

    .custody-oversight-no-results {
        padding: 30px 18px;
        border: 1px dashed var(--oversight-line);
        border-radius: 12px;
        background: var(--oversight-soft);
        color: var(--oversight-muted);
        text-align: center;
    }

    .custody-oversight-no-results strong {
        display: block;
        margin-bottom: 4px;
        color: var(--oversight-ink);
    }

    .custody-oversight-no-results[hidden],
    .custody-operations-list .operational-record[hidden] {
        display: none !important;
    }

    @media (max-width: 980px) {
        .custody-oversight-filters {
            grid-template-columns: 1fr 1fr;
        }

        .custody-oversight-search {
            grid-column: 1 / -1;
        }
    }

    @media (max-width: 620px) {
        .custody-oversight-filters {
            grid-template-columns: 1fr;
        }

        .custody-oversight-search {
            grid-column: auto;
        }

        #custody-oversight-clear {
            width: 100%;
            justify-content: center;
        }
    }
</style>
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
    <section class="content-area custody-oversight-workspace">
        <div class="custody-oversight-toolbar">
            <nav class="custody-oversight-tabs" aria-label="Release and return status filters">
                @foreach([
                    'all' => 'All',
                    'active' => 'Active',
                    'attention' => 'Needs Attention',
                    'release' => 'Release Queue',
                    'custody' => 'On Custody',
                    'return' => 'Return Processing',
                    'completed' => 'Completed',
                ] as $key => $label)
                    <button
                        class="custody-oversight-tab {{ $key === 'all' ? 'is-active' : '' }}"
                        type="button"
                        data-custody-tab="{{ $key }}"
                        aria-pressed="{{ $key === 'all' ? 'true' : 'false' }}"
                    >
                        <span>{{ $label }}</span>
                        <span class="custody-oversight-tab-count">{{ $oversightCounts[$key] ?? 0 }}</span>
                    </button>
                @endforeach
            </nav>

            <div class="custody-oversight-filters">
                <label class="custody-oversight-search">
                    Search
                    <input
                        id="custody-oversight-search"
                        type="search"
                        placeholder="Search borrower, request no., custody no., or event..."
                        autocomplete="off"
                    >
                </label>

                <label>
                    Schedule from
                    <input id="custody-oversight-from" type="date">
                </label>

                <label>
                    Schedule to
                    <input id="custody-oversight-to" type="date">
                </label>

                <button
                    id="custody-oversight-clear"
                    class="button secondary ui-pressable"
                    type="button"
                >
                    Clear
                </button>
            </div>

            <p
                id="custody-oversight-date-error"
                class="custody-oversight-date-error"
                role="alert"
                hidden
            >
                Schedule From cannot be later than Schedule To.
                Adjust the dates or use Clear.
            </p>

            <div class="custody-oversight-summary">
                <span id="custody-oversight-result-summary">Showing all release and return transactions.</span>
                <span>Search starts across <strong>All</strong> statuses. Select a status tab afterward to refine the results.</span>
            </div>
        </div>

        <div
            id="custody-oversight-list"
            class="operational-record-list custody-operations-list"
            aria-label="Release and return oversight records"
        >
            @forelse($custodies as $custody)
                @php
                    $outstanding = $custody->lines->sum(
                        fn ($line) => max(
                            0,
                            (float) $line->actual_released_quantity - (float) $line->returned_quantity
                        )
                    );

                    $version = $custody->request?->currentVersion;
                    $scheduleDateValue = $version?->schedule_date ?: $version?->needed_from;
                    $returnDateValue = $version?->return_date ?: $version?->return_due_at ?: $custody->due_at;

                    $scheduleDate = $scheduleDateValue
                        ? \Illuminate\Support\Carbon::parse($scheduleDateValue)
                        : null;

                    $returnDate = $returnDateValue
                        ? \Illuminate\Support\Carbon::parse($returnDateValue)
                        : null;

                    $hasActivePickupSchedule = (bool) $custody->scheduled_release_at
                        && (bool) $custody->pickup_expires_at
                        && ! $custody->pickup_expired_at;

                    $isCompleted = $custody->status === 'CLOSED' || $custody->closed_at !== null;

                    $operationalLabel = match (true) {
                        $isCompleted => 'Completed',
                        $custody->status === 'OBLIGATION_OPEN' => 'Obligation Open',
                        $custody->status === 'INCIDENT_OPEN' => 'Incident Open',
                        in_array($custody->status, ['RETURN_PROCESSING', 'PARTIALLY_RETURNED', 'EARLY_RETURN'], true) => 'Return Processing',
                        $custody->status === 'OVERDUE' => 'Overdue',
                        (bool) $custody->released_at => 'Items Released / On Custody',
                        (bool) $custody->prepared_at && $hasActivePickupSchedule => 'Ready for Release',
                        $hasActivePickupSchedule => 'Pickup Scheduled / For Item Preparation',
                        $custody->status === 'PREPARING_RELEASE' => 'For Pickup Scheduling',
                        default => null,
                    };

                    $group = $groupForCustody($custody);
                    $priority = $priorityForCustody($custody);

                    $searchText = strtolower(trim(
                        ($custody->borrower?->full_name ?? '').' '.
                        ($custody->request?->request_no ?? '').' '.
                        ($custody->custody_no ?? '').' '.
                        ($version?->purpose_event ?? '')
                    ));
                @endphp

                <a
                    class="operational-record ui-pressable"
                    href="{{ route('custody.show', $custody) }}"
                    data-custody-record
                    data-custody-group="{{ $group }}"
                    data-custody-priority="{{ $priority }}"
                    data-created="{{ optional($custody->created_at)->timestamp ?? 0 }}"
                    data-search="{{ $searchText }}"
                    data-schedule="{{ optional($scheduleDate)->format('Y-m-d') }}"
                >
                    <span class="operational-record-primary">
                        <strong>{{ $custody->borrower?->full_name ?: 'Borrower' }}</strong>
                        <span>Request {{ $custody->request?->request_no }}</span>
                        <small>
                            Schedule {{ optional($scheduleDate)->format('d M Y') ?: 'Not set' }}
                            · Return {{ optional($returnDate)->format('d M Y') ?: 'Not set' }}
                        </small>
                    </span>

                    <span class="operational-record-facts">
                        <span>
                            <small>Pickup</small>
                            <strong>{{ optional($custody->scheduled_release_at)->format('d M Y, g:i A') ?: 'Not scheduled' }}</strong>
                        </span>

                        <span>
                            <small>Issued</small>
                            <strong>{{ optional($custody->released_at)->format('d M Y, g:i A') ?: 'Not yet' }}</strong>
                        </span>

                        <span>
                            <small>Outstanding</small>
                            <strong class="custody-outstanding-value {{ $outstanding > 0 ? 'has-outstanding' : '' }}">
                                {{ $outstanding + 0 }}
                            </strong>
                        </span>
                    </span>

                    <span class="operational-record-action">
                        <x-status-badge
                            :status="$isCompleted ? 'COMPLETED' : $custody->status"
                            :label="$operationalLabel"
                        />

                        <strong>
                            View
                            <x-icon name="chevron-right" size="16" />
                        </strong>
                    </span>
                </a>
            @empty
                <div class="empty-state">
                    <strong>No custody/pickup records.</strong>
                    <span>A record appears after the SPMU Head approves a request and the approved quantities are allocated for pickup.</span>
                </div>
            @endforelse
        </div>

        <div id="custody-oversight-no-results" class="custody-oversight-no-results" hidden>
            <strong>No matching release or return transaction.</strong>
            <span>Try another status tab, clear the search, or adjust the schedule dates.</span>
        </div>
    </section>

    <script>
    (() => {
        const initializeCustodyOversight = () => {
            const list = document.getElementById('custody-oversight-list');

            if (!list) {
                return;
            }

            const records = Array.from(list.querySelectorAll('[data-custody-record]'));
            const tabs = Array.from(document.querySelectorAll('[data-custody-tab]'));
            const search = document.getElementById('custody-oversight-search');
            const from = document.getElementById('custody-oversight-from');
            const to = document.getElementById('custody-oversight-to');
            const clear = document.getElementById('custody-oversight-clear');
            const noResults = document.getElementById('custody-oversight-no-results');
            const summary = document.getElementById('custody-oversight-result-summary');
            const dateError = document.getElementById('custody-oversight-date-error');

            let activeTab = 'all';

            records
                .sort((left, right) => {
                    const priorityDifference =
                        Number(left.dataset.custodyPriority || 99)
                        - Number(right.dataset.custodyPriority || 99);

                    if (priorityDifference !== 0) {
                        return priorityDifference;
                    }

                    return Number(right.dataset.created || 0) - Number(left.dataset.created || 0);
                })
                .forEach((record) => list.appendChild(record));

            const matchesTab = (record) => {
                const group = record.dataset.custodyGroup || 'active';

                if (activeTab === 'all') {
                    return true;
                }

                if (activeTab === 'active') {
                    return group !== 'completed';
                }

                return group === activeTab;
            };

            const render = () => {
                const query = (search?.value || '').trim().toLowerCase();
                const fromDate = from?.value || '';
                const toDate = to?.value || '';

                const invalidDateRange =
                    Boolean(fromDate && toDate && fromDate > toDate);

                from?.classList.toggle('is-invalid', invalidDateRange);
                to?.classList.toggle('is-invalid', invalidDateRange);

                if (dateError) {
                    dateError.hidden = !invalidDateRange;
                }

                let visible = 0;

                records.forEach((record) => {
                    const recordSearch = record.dataset.search || '';
                    const schedule = record.dataset.schedule || '';

                    const searchMatches =
                        !query || recordSearch.includes(query);

                    // Never silently apply an impossible date range.
                    const fromMatches =
                        invalidDateRange
                        || !fromDate
                        || (schedule && schedule >= fromDate);

                    const toMatches =
                        invalidDateRange
                        || !toDate
                        || (schedule && schedule <= toDate);

                    const show =
                        matchesTab(record)
                        && searchMatches
                        && fromMatches
                        && toMatches;

                    record.hidden = !show;

                    if (show) {
                        visible += 1;
                    }
                });

                tabs.forEach((tab) => {
                    const selected = tab.dataset.custodyTab === activeTab;
                    tab.classList.toggle('is-active', selected);
                    tab.setAttribute('aria-pressed', selected ? 'true' : 'false');
                });

                if (noResults) {
                    noResults.hidden = visible !== 0 || records.length === 0;
                }

                if (summary) {
                    const selectedTab = tabs.find(
                        (tab) => tab.dataset.custodyTab === activeTab
                    );

                    const label =
                        selectedTab?.querySelector('span')?.textContent?.trim()
                        || 'transactions';

                    const searchSuffix =
                        query
                            ? ` matching "${search.value.trim()}"`
                            : '';

                    summary.textContent = visible === 1
                        ? `Showing 1 ${label.toLowerCase()} transaction${searchSuffix}.`
                        : `Showing ${visible} ${label.toLowerCase()} transactions${searchSuffix}.`;
                }
            };

            tabs.forEach((tab) => {
                tab.addEventListener('click', () => {
                    activeTab = tab.dataset.custodyTab || 'active';
                    render();
                });
            });

            search?.addEventListener(
                'input',
                () => {
                    activeTab = 'all';
                    render();
                }
            );

            from?.addEventListener('change', render);
            to?.addEventListener('change', render);

            clear?.addEventListener('click', () => {
                if (search) search.value = '';
                if (from) from.value = '';
                if (to) to.value = '';

                activeTab = 'all';
                render();
            });

            render();
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initializeCustodyOversight, { once: true });
        } else {
            initializeCustodyOversight();
        }
    })();
    </script>
@else
    <section class="content-area">
        @if(in_array($mode, ['release','return'], true))
        <div class="operational-browser-toolbar">
            <label>Search
                <input type="search" id="operational-search" placeholder="Search borrower, request no., custody no., or event..." autocomplete="off">
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

                    $operationalLabel = match (true) {
                        $custody->status === 'CLOSED' || $custody->closed_at !== null => 'Completed',
                        $custody->status === 'OBLIGATION_OPEN' => 'Obligation Open',
                        $custody->status === 'INCIDENT_OPEN' => 'Incident Open',
                        in_array($custody->status, ['RETURN_PROCESSING', 'PARTIALLY_RETURNED', 'EARLY_RETURN'], true) => 'Return Processing',
                        $custody->status === 'OVERDUE' => 'Overdue',
                        (bool) $custody->released_at => 'Items Released / On Custody',
                        (bool) $custody->prepared_at && $hasActivePickupSchedule => 'Ready for Release',
                        $hasActivePickupSchedule => 'For Item Preparation',
                        $custody->status === 'PREPARING_RELEASE' => 'For Pickup Scheduling',
                        default => null,
                    };

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
                    data-status="{{ $operationalLabel ?: $custody->status }}"
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
                            <span><small>Return Due</small><strong>{{ optional($returnDate)->format('d M Y') ?: '—' }}</strong></span>
                            <span><small>Outstanding</small><strong>{{ $outstanding + 0 }}</strong></span>
                        @else
                            <span><small>Pickup</small><strong>{{ optional($custody->scheduled_release_at)->format('d M Y, g:i A') ?: 'Not scheduled' }}</strong></span>
                            <span><small>Issued</small><strong>{{ optional($custody->released_at)->format('d M Y, g:i A') ?: 'Not yet' }}</strong></span>
                            <span><small>Outstanding</small><strong>{{ $outstanding + 0 }}</strong></span>
                        @endif
                    </span>

                    <span class="operational-record-action">
                        <x-status-badge
                            :status="$custody->status === 'CLOSED' || $custody->closed_at !== null ? 'COMPLETED' : $custody->status"
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
    @media(max-width:760px){.operational-browser-toolbar{grid-template-columns:1fr}}
    </style>
    <script>
    (()=>{const list=document.getElementById('operational-filter-list');const rows=[...document.querySelectorAll('[data-operational-record]')];const search=document.getElementById('operational-search');const status=document.getElementById('operational-status');const sort=document.getElementById('operational-sort');const empty=document.getElementById('operational-filter-empty');if(!list||!rows.length||!search||!status||!sort)return;[...new Set(rows.map(r=>r.dataset.status).filter(Boolean))].sort().forEach(v=>{const o=document.createElement('option');o.value=v;o.textContent=v;status.appendChild(o)});const render=()=>{const q=search.value.trim().toLowerCase();const st=status.value;const ordered=[...rows].sort((a,b)=>(Number(b.dataset.created)-Number(a.dataset.created))*(sort.value==='newest'?1:-1));ordered.forEach(r=>list.appendChild(r));let n=0;rows.forEach(r=>{const show=(!q||r.dataset.search.includes(q))&&(st==='all'||r.dataset.status===st);r.hidden=!show;if(show)n++});if(empty)empty.hidden=n>0};search.addEventListener('input',render);status.addEventListener('change',render);sort.addEventListener('change',render);render()})();
    </script>
    @endif
@endif

@endsection
