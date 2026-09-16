@extends('layouts.app', [
    'title' => $item->unique_description,
    'topbarTitle' => 'Inventory Overview',
])

@section('content')

@php
    $total = (float) ($balance['total'] ?? 0);
    $available = (float) ($balance['borrower_available'] ?? $balance['current_available'] ?? $balance['available'] ?? 0);
    $reserved = (float) ($balance['reserved'] ?? 0);
    $issued = (float) ($balance['borrowed'] ?? 0);
    $laundry = (float) ($balance['laundry'] ?? 0);
    $incident = (float) ($balance['incident'] ?? 0);
    $unavailable = max(0, $total - $available - $reserved - $issued);

    $damagedMaintenance = min($total, (float) ($balance['damaged_maintenance'] ?? 0));
    $lost = min($total, (float) ($balance['lost'] ?? 0));
    $stolen = min($total, (float) ($balance['stolen'] ?? 0));
    $destroyed = min($total, (float) ($balance['destroyed'] ?? 0));
    $condemned = min($total, (float) ($balance['condemned'] ?? 0));

    $knownNonGood = min(
        $total,
        $damagedMaintenance + $lost + $stolen + $destroyed + $condemned
    );

    $recordedGood = $item->condition_code === 'SERVICEABLE'
        ? max(0, $total - $knownNonGood)
        : 0;

    $borrowerStatus = $available > 0 && $item->condition_code === 'SERVICEABLE'
        ? 'AVAILABLE'
        : 'UNAVAILABLE';

    // Inventory modification belongs to the SPMU Head / Administrator only.
    // The Action Officer may inspect all operational details, stock-card
    // movements, and borrowing history but must remain read-only.
    $isInventoryAdmin = auth()->user()?->access_classification?->value === 'SPMU_HEAD';
    $isActionOfficer = auth()->user()?->access_classification?->value === 'SPMU_OFFICER';
    $canEditInventory = $isInventoryAdmin;

    $requestedInventoryTab = (string) request('tab', 'overview');
    $activeInventoryTab = in_array($requestedInventoryTab, [
        'overview',
        'stock-card',
        'borrowing-history',
        'item-information',
    ], true) ? $requestedInventoryTab : 'overview';

    // Reuse the read-only Stock Card as the provenance source for the detail
    // page. This adds context without inventing another inventory audit trail.
    $lastStockEntry = collect($stockCard)->first();
    $lastStockReference = $lastStockEntry
        ? ($stockCardReferences[(int) $lastStockEntry->id] ?? null)
        : null;

    $currentSources = collect($currentInventorySources ?? []);
    $currentSourceGroups = $currentSources->groupBy('group');
    $currentSourceOrder = [
        'RESERVED' => 'Reserved',
        'CUSTODY' => 'On custody',
        'LAUNDRY' => 'Laundry',
        'ISSUE' => 'Inventory exceptions',
        'CONDITION' => 'Item condition',
    ];
@endphp

<section class="page-heading inventory-detail-heading">
    <div>
        <p class="eyebrow">
            @if($isBorrower)
                Borrowable item
            @elseif($isInventoryAdmin)
                Inventory management
            @else
                Inventory operations
            @endif
        </p>
        <h1>{{ $item->unique_description }}</h1>
        <p>{{ 'INV-'.str_pad((string) $item->id, 4, '0', STR_PAD_LEFT) }} &middot; {{ $item->category->category_name }} &middot; {{ $item->unit->unit_name }}</p>
    </div>

    <a class="button secondary ui-pressable" href="{{ route('inventory.index') }}">
        <x-icon name="arrow-left" size="16" />
        <span>Back</span>
    </a>
</section>

<section class="content-area inventory-detail-page">
    @if($isBorrower)
        <article class="card borrower-inventory-detail-card borrower-inventory-polished">
            <div class="card-header borrower-inventory-card-header">
                <div>
                    <p class="eyebrow">Current availability</p>
                    <h2>Available for borrowing</h2>
                    <p class="meta">Current reference quantity for this item.</p>
                </div>

                @if($borrowerStatus === 'AVAILABLE')
                    <x-status-badge status="AVAILABLE" label="Available" />
                @else
                    <x-status-badge status="UNAVAILABLE" label="Unavailable" />
                @endif
            </div>

            <div class="borrower-availability-highlight">
                <div class="borrower-availability-number">
                    <strong>{{ $available + 0 }}</strong>
                    <span>{{ $item->unit->unit_name }}</span>
                </div>
                <div class="borrower-availability-copy">
                    <strong>available now</strong>
                    <span>Reference availability before SPMU approval.</span>
                </div>
            </div>

            <div class="borrower-item-details" aria-label="Borrowing information">
                <div class="borrower-item-detail borrower-item-detail-wide">
                    <span>Description</span>
                    <strong>{{ $item->specification ?: 'No additional description.' }}</strong>
                </div>

                <div class="borrower-item-detail">
                    <span>Condition</span>
                    <strong class="borrower-detail-with-icon">
                        @if($item->condition_code === 'SERVICEABLE')
                            <x-icon name="success" size="16" />
                            Good / Serviceable
                        @else
                            Not currently suitable
                        @endif
                    </strong>
                </div>

                <div class="borrower-item-detail">
                    <span>Use restriction</span>
                    <strong>{{ $item->off_campus_allowed ? 'Off-campus eligible' : 'On-campus only' }}</strong>
                </div>

                <div class="borrower-item-detail">
                    <span>Laundry after use</span>
                    <strong>{{ $item->laundry_required ? 'Required' : 'Not required' }}</strong>
                </div>

                <div class="borrower-item-detail">
                    <span>Borrowing eligibility</span>
                    <strong>{{ $borrowerStatus === 'AVAILABLE' ? 'Available for request' : 'Currently unavailable' }}</strong>
                </div>
            </div>
        </article>

        <div class="inventory-reference-note borrower-reference-note" role="note">
            <x-icon name="information" size="18" />
            <div>
                <strong>Reference only — this does not reserve the item.</strong>
                <p>
                    Availability may change until your request is approved.
                    Submitting a request does not hold this quantity.
                    SPMU confirms the final available quantity during review.
                </p>
            </div>
        </div>
    @else
        <section class="inventory-admin-summary" aria-label="Current inventory summary">
            <div class="inventory-admin-summary-item is-total" title="Recorded physical quantity for this inventory item.">
                <span class="inventory-summary-icon" aria-hidden="true"><x-icon name="box" size="18" /></span>
                <div class="inventory-summary-copy">
                    <span>Total Stock</span>
                    <strong>{{ $total + 0 }}</strong>
                </div>
            </div>
            <div class="inventory-admin-summary-item is-available" title="Serviceable units currently free for a new allocation; approved reservations are already excluded.">
                <span class="inventory-summary-icon" aria-hidden="true"><x-icon name="success" size="18" /></span>
                <div class="inventory-summary-copy">
                    <span>Available</span>
                    <strong>{{ $available + 0 }}</strong>
                </div>
            </div>
            <div class="inventory-admin-summary-item is-reserved" title="Units allocated to approved requests and awaiting physical release.">
                <span class="inventory-summary-icon" aria-hidden="true"><x-icon name="bookmark" size="18" /></span>
                <div class="inventory-summary-copy">
                    <span>Reserved</span>
                    <strong>{{ $reserved + 0 }}</strong>
                </div>
            </div>
            <div class="inventory-admin-summary-item is-custody" title="Units physically released to borrowers and not yet returned.">
                <span class="inventory-summary-icon" aria-hidden="true"><x-icon name="profile" size="18" /></span>
                <div class="inventory-summary-copy">
                    <span>On Custody</span>
                    <strong>{{ $issued + 0 }}</strong>
                </div>
            </div>
            <div class="inventory-admin-summary-item is-unavailable" title="Units excluded from allocation by laundry, incident, or physical-condition state.">
                <span class="inventory-summary-icon" aria-hidden="true"><x-icon name="warning" size="18" /></span>
                <div class="inventory-summary-copy">
                    <span>Unavailable</span>
                    <strong>{{ $unavailable + 0 }}</strong>
                </div>
            </div>
        </section>

        <div class="inventory-last-movement" role="note" aria-label="Last stock movement">
            <span class="inventory-last-movement-icon" aria-hidden="true"><x-icon name="box" size="17" /></span>
            <div class="inventory-last-movement-copy">
                <span>Last stock movement</span>
                @if($lastStockEntry)
                    <strong>
                        {{ str((string) $lastStockEntry->transaction_type)->replace('_', ' ')->title() }}
                        &middot; {{ (float) $lastStockEntry->quantity + 0 }} {{ \Illuminate\Support\Str::plural($item->unit->unit_name, (int) $lastStockEntry->quantity) }}
                    </strong>
                    <small>
                        {{ \Illuminate\Support\Carbon::parse($lastStockEntry->occurred_at)->format('d M Y, g:i A') }}
                        @if($lastStockReference)
                            &middot;
                            @if(filled($lastStockReference['url'] ?? null))
                                <a class="inventory-inline-reference" href="{{ $lastStockReference['url'] }}">{{ $lastStockReference['label'] }}</a>
                            @else
                                {{ $lastStockReference['label'] }}
                            @endif
                        @endif
                    </small>
                @else
                    <strong>No stock movement recorded</strong>
                @endif
            </div>
        </div>

        <nav class="inventory-detail-tabs" aria-label="Inventory detail sections" role="tablist">
            <button type="button" class="inventory-detail-tab {{ $activeInventoryTab === 'overview' ? 'is-active' : '' }}" data-inventory-tab="overview" role="tab" aria-selected="{{ $activeInventoryTab === 'overview' ? 'true' : 'false' }}">Overview</button>
            <button type="button" class="inventory-detail-tab {{ $activeInventoryTab === 'stock-card' ? 'is-active' : '' }}" data-inventory-tab="stock-card" role="tab" aria-selected="{{ $activeInventoryTab === 'stock-card' ? 'true' : 'false' }}">Stock Card</button>
            <button type="button" class="inventory-detail-tab {{ $activeInventoryTab === 'borrowing-history' ? 'is-active' : '' }}" data-inventory-tab="borrowing-history" role="tab" aria-selected="{{ $activeInventoryTab === 'borrowing-history' ? 'true' : 'false' }}">Borrowing History</button>
            <button type="button" class="inventory-detail-tab {{ $activeInventoryTab === 'item-information' ? 'is-active' : '' }}" data-inventory-tab="item-information" role="tab" aria-selected="{{ $activeInventoryTab === 'item-information' ? 'true' : 'false' }}">Item Information</button>
        </nav>

        <section class="inventory-tab-panel inventory-tab-overview" data-inventory-panel="overview" role="tabpanel" @if($activeInventoryTab !== 'overview') hidden @endif>
            <div class="inventory-detail-grid inventory-overview-grid">
                <article class="card inventory-overview-card">
                    <div class="card-header">
                        <div>
                            <p class="eyebrow">Stock exceptions</p>
                            <h2>Unavailable breakdown</h2>
                            <p class="meta">Why {{ $unavailable + 0 }} {{ \Illuminate\Support\Str::plural('unit', (int) $unavailable) }} cannot be allocated right now.</p>
                        </div>
                    </div>

                    <div class="inventory-ops-list" role="list">
                        <div class="inventory-ops-row" role="listitem">
                            <span>In laundry</span>
                            <strong>{{ $laundry + 0 }}</strong>
                        </div>
                        <div class="inventory-ops-row" role="listitem">
                            <span>Incident / condition hold</span>
                            <strong>{{ $incident + 0 }}</strong>
                        </div>
                    </div>

                    @if($unavailable <= 0)
                        <p class="inventory-overview-empty">No stock is currently unavailable.</p>
                    @endif
                </article>

                <article class="card inventory-overview-card">
                    <div class="card-header">
                        <div>
                            <p class="eyebrow">Condition breakdown</p>
                            <h2>Physical condition</h2>
                            <p class="meta">Recorded condition of the {{ $total + 0 }} {{ \Illuminate\Support\Str::plural('unit', (int) $total) }} in stock.</p>
                        </div>
                    </div>

                    <div class="inventory-ops-list" role="list">
                        <div class="inventory-ops-row" role="listitem">
                            <span>Good / serviceable</span>
                            <strong>{{ $recordedGood + 0 }}</strong>
                        </div>
                        @if($damagedMaintenance > 0)
                            <div class="inventory-ops-row" role="listitem"><span>Damaged / under repair</span><strong>{{ $damagedMaintenance + 0 }}</strong></div>
                        @endif
                        @if($lost > 0)
                            <div class="inventory-ops-row" role="listitem"><span>Lost</span><strong>{{ $lost + 0 }}</strong></div>
                        @endif
                        @if($stolen > 0)
                            <div class="inventory-ops-row" role="listitem"><span>Stolen</span><strong>{{ $stolen + 0 }}</strong></div>
                        @endif
                        @if($destroyed > 0)
                            <div class="inventory-ops-row" role="listitem"><span>Destroyed</span><strong>{{ $destroyed + 0 }}</strong></div>
                        @endif
                        @if($condemned > 0)
                            <div class="inventory-ops-row" role="listitem"><span>Condemned</span><strong>{{ $condemned + 0 }}</strong></div>
                        @endif
                    </div>

                    @if($knownNonGood <= 0)
                        <p class="inventory-overview-empty">No condition issue is recorded for this item.</p>
                    @endif
                </article>
            </div>

            <article class="card inventory-source-records-card">
                <div class="card-header inventory-source-header">
                    <div>
                        <p class="eyebrow">Current stock source records</p>
                        <h2>Records behind the current counts</h2>
                        <p class="meta">Active reservations, custody, laundry, and inventory exceptions that explain the summary above.</p>
                    </div>
                    <div class="inventory-source-header-actions">
                        <span class="inventory-source-live">Current</span>
                        <a
                            class="button secondary small ui-pressable"
                            href="{{ route('reports.index', ['report' => 'inventory', 'equipment' => $item->id, 'generated' => 1]) }}"
                        >
                            <span>View source report</span>
                            <x-icon name="arrow-right" size="14" />
                        </a>
                    </div>
                </div>

                @if($currentSources->isEmpty())
                    <div class="inventory-source-empty-state">
                        <strong>No active stock hold or custody record.</strong>
                        <span>All current non-available states are clear for this item.</span>
                    </div>
                @else
                    <div class="inventory-source-groups">
                        @foreach($currentSourceOrder as $groupKey => $groupLabel)
                            @php($groupRows = $currentSourceGroups->get($groupKey, collect()))
                            @continue($groupRows->isEmpty())

                            <section class="inventory-source-group" aria-label="{{ $groupLabel }} source records">
                                <div class="inventory-source-group-head">
                                    <div>
                                        <span>{{ $groupLabel }}</span>
                                        <strong>{{ (float) $groupRows->sum('quantity') + 0 }} {{ \Illuminate\Support\Str::plural($item->unit->unit_name, (int) $groupRows->sum('quantity')) }}</strong>
                                    </div>
                                    <small>{{ $groupRows->count() }} {{ \Illuminate\Support\Str::plural('record', $groupRows->count()) }}</small>
                                </div>

                                <div class="inventory-source-records" role="list">
                                    @foreach($groupRows as $record)
                                        <div class="inventory-source-record" role="listitem">
                                            <div class="inventory-source-record-main">
                                                <span class="inventory-source-kind">{{ $record['group_label'] }}</span>
                                                <strong>{{ $record['reference'] }}</strong>
                                                <small>{{ $record['primary'] }}</small>
                                                @if(filled($record['secondary']))
                                                    <small>{{ $record['secondary'] }}</small>
                                                @endif
                                            </div>

                                            <div class="inventory-source-record-state">
                                                <strong>{{ (float) $record['quantity'] + 0 }} {{ \Illuminate\Support\Str::plural($item->unit->unit_name, (int) $record['quantity']) }}</strong>
                                                <span>{{ $record['status'] }}</span>
                                            </div>

                                            @if(filled($record['url']))
                                                <a class="button secondary small ui-pressable inventory-source-open" href="{{ $record['url'] }}"><span>{{ $record['action_label'] ?? 'View Record' }}</span><x-icon name="arrow-right" size="14" /></a>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                    </div>

                    <p class="inventory-source-note">
                        A resolved accountability case can remain listed here when the physical unit is still damaged, lost, or otherwise unavailable. Accountability resolution does not automatically restore inventory.
                    </p>
                @endif
            </article>
        </section>

        <section class="inventory-tab-panel" data-inventory-panel="stock-card" role="tabpanel" @if($activeInventoryTab !== 'stock-card') hidden @endif>
        <article class="card inventory-borrowing-history-card" id="stock-card">
            <div class="card-header inventory-history-header">
                <div>
                    <p class="eyebrow">Read-only inventory ledger</p>
                    <h2>Stock Card</h2>
                    <p class="meta">System-generated inventory movement history for {{ 'INV-'.str_pad((string) $item->id, 4, '0', STR_PAD_LEFT) }}. Records cannot be edited from this page. The latest up to 100 movements are shown.</p>
                </div>
            </div>
            <div class="table-wrap inventory-history-table-wrap">
                <table class="inventory-history-table inventory-ledger-table">
                    <thead>
                        <tr><th>Date</th><th>Transaction</th><th>From</th><th>To</th><th>Quantity</th><th>Reference</th><th>Actor / Reason</th></tr>
                    </thead>
                    <tbody>
                    @forelse($stockCard as $entry)
                        @php($sourceReference = $stockCardReferences[(int) $entry->id] ?? null)
                        <tr>
                            <td>{{ \Illuminate\Support\Carbon::parse($entry->occurred_at)->format('d M Y, g:i A') }}</td>
                            <td>{{ str($entry->transaction_type)->replace('_',' ')->title() }}</td>
                            <td>{{ $entry->from_state ?: '—' }}</td>
                            <td>{{ $entry->to_state ?: '—' }}</td>
                            <td><strong>{{ (float) $entry->quantity + 0 }}</strong></td>
                            <td class="inventory-ledger-reference">
                                @if($sourceReference)
                                    @if(filled($sourceReference['url'] ?? null))
                                        <a class="inventory-ledger-reference-link" href="{{ $sourceReference['url'] }}">{{ $sourceReference['label'] }}</a>
                                    @else
                                        <strong>{{ $sourceReference['label'] }}</strong>
                                    @endif
                                    <small>{{ $sourceReference['kind'] }}</small>
                                @else
                                    <span>System movement</span>
                                @endif
                            </td>
                            <td class="inventory-ledger-actor">
                                <strong>{{ $entry->actor_name ?: 'System' }}</strong>
                                @if($entry->actor_email)<small>{{ $entry->actor_email }}</small>@endif
                                <small class="inventory-ledger-reason" title="{{ $entry->reason ?: 'Recorded inventory movement' }}">{{ \Illuminate\Support\Str::limit($entry->reason ?: 'Recorded inventory movement', 92) }}</small>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="empty-state">No stock-card movements have been recorded for this item yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </article>
        </section>

        <section class="inventory-tab-panel" data-inventory-panel="borrowing-history" role="tabpanel" @if($activeInventoryTab !== 'borrowing-history') hidden @endif>
        <article class="card inventory-borrowing-history-card" id="borrowing-history">
            <div class="card-header inventory-history-header">
                <div>
                    <p class="eyebrow">Item borrowing history</p>
                    <h2>Borrowing records</h2>
                    <p class="meta">
                        Shows actual physical releases of this item within the selected period.
                        Reservations that were never issued are excluded.
                    </p>
                </div>
            </div>

            <form
                method="get"
                action="{{ route('inventory.show', $item) }}"
                class="inventory-history-filter"
            >
                <input type="hidden" name="tab" value="borrowing-history">

                <label>
                    From
                    <input
                        type="date"
                        name="history_from"
                        value="{{ $historyFrom?->toDateString() }}"
                    >
                </label>

                <label>
                    To
                    <input
                        type="date"
                        name="history_to"
                        value="{{ $historyTo?->toDateString() }}"
                    >
                </label>

                <label>
                    Status
                    <select name="history_status">
                        <option value="ALL" @selected($historyStatus === 'ALL')>All</option>
                        <option value="ON_CUSTODY" @selected($historyStatus === 'ON_CUSTODY')>On Custody</option>
                        <option value="OVERDUE" @selected($historyStatus === 'OVERDUE')>Overdue</option>
                        <option value="RETURNED_ON_TIME" @selected($historyStatus === 'RETURNED_ON_TIME')>Returned On Time</option>
                        <option value="RETURNED_LATE" @selected($historyStatus === 'RETURNED_LATE')>Returned Late</option>
                        @if($item->laundry_required)
                            <option value="IN_LAUNDRY" @selected($historyStatus === 'IN_LAUNDRY')>In Laundry</option>
                        @endif
                    </select>
                </label>

                <label class="inventory-history-search-field">
                    Search
                    <span class="search-input-shell">
                        <span class="search-input-icon" aria-hidden="true"><x-icon name="search" /></span>
                        <input
                            type="search"
                            name="history_search"
                            value="{{ $historySearch }}"
                            placeholder="Borrower, office, request, custody, purpose..."
                        >
                    </span>
                </label>

                <div class="inventory-history-filter-actions">
                    <button class="button primary ui-pressable" type="submit">
                        Apply Filter
                    </button>
                    <a
                        class="button secondary ui-pressable"
                        href="{{ route('inventory.show', ['inventory' => $item, 'tab' => 'borrowing-history']) }}"
                    >
                        Reset
                    </a>
                </div>
            </form>

            @error('history_from')
                <p class="field-error">{{ $message }}</p>
            @enderror
            @error('history_to')
                <p class="field-error">{{ $message }}</p>
            @enderror

            <div class="inventory-history-period-note">
                <x-icon name="information" size="17" />
                <span>
                    Period:
                    <strong>{{ $historyFrom?->format('d M Y') }}</strong>
                    to
                    <strong>{{ $historyTo?->format('d M Y') }}</strong>.
                    Includes records whose actual custody overlaps the selected dates.
                </span>
            </div>

            <div class="inventory-history-summary" aria-label="Filtered borrowing history summary">
                <div class="inventory-history-metric">
                    <strong>{{ $historySummary['borrowers'] }}</strong>
                    <span>Borrowers</span>
                </div>
                <div class="inventory-history-metric">
                    <strong>{{ $historySummary['records'] }}</strong>
                    <span>Records</span>
                </div>
                <div class="inventory-history-metric">
                    <strong>{{ $historySummary['issued'] + 0 }}</strong>
                    <span>Issued</span>
                </div>
                <div class="inventory-history-metric">
                    <strong>{{ $historySummary['returned'] + 0 }}</strong>
                    <span>Returned</span>
                </div>
                <div class="inventory-history-metric">
                    <strong>{{ $historySummary['outstanding'] + 0 }}</strong>
                    <span>Outstanding</span>
                </div>
            </div>

            <div class="table-wrap inventory-history-table-wrap">
                <table class="inventory-history-table">
                    <thead>
                        <tr>
                            <th>Borrower / Office</th>
                            <th>Request / Custody</th>
                            <th>Purpose / Location</th>
                            <th>Dates</th>
                            <th>Issued</th>
                            <th>Returned</th>
                            <th>Outstanding</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($borrowingHistory as $row)
                            <tr>
                                <td data-label="Borrower / Office">
                                    <strong>{{ $row['borrower']?->full_name ?: 'Unknown borrower' }}</strong>
                                    <small>{{ $row['office'] ?: 'No Office / College / Unit recorded' }}</small>
                                </td>

                                <td data-label="Request / Custody">
                                    <strong>{{ $row['request_no'] ?: '—' }}</strong>
                                    <small>{{ $row['custody']->custody_no }}</small>
                                </td>

                                <td data-label="Purpose / Location">
                                    <strong>{{ $row['purpose'] ?: 'Borrowing request' }}</strong>
                                    <small>{{ $row['location'] ?: 'No location recorded' }}</small>
                                    <small>
                                        {{ str($row['use_location'] ?: 'ON_CAMPUS')->replace('_', ' ')->title() }}
                                    </small>
                                </td>

                                <td data-label="Dates" class="inventory-history-dates">
                                    <span>
                                        <b>Schedule</b>
                                        <em>{{ optional($row['schedule_date'])->format('d M Y') ?: '—' }}</em>
                                    </span>
                                    <span>
                                        <b>Released</b>
                                        <em>{{ optional($row['released_at'])->format('d M Y · g:i A') ?: '—' }}</em>
                                    </span>
                                    <span>
                                        <b>Expected</b>
                                        <em>{{ optional($row['expected_return_date'])->format('d M Y') ?: '—' }}</em>
                                    </span>
                                    <span>
                                        <b>Actual return</b>
                                        <em>{{ optional($row['actual_return_at'])->format('d M Y · g:i A') ?: 'Not returned yet' }}</em>
                                    </span>
                                </td>

                                <td data-label="Issued">
                                    <strong>{{ $row['issued_quantity'] + 0 }}</strong>
                                    <small>{{ $item->unit->unit_name }}</small>
                                </td>

                                <td data-label="Returned">
                                    <strong>{{ $row['returned_quantity'] + 0 }}</strong>
                                </td>

                                <td data-label="Outstanding">
                                    <strong>{{ $row['outstanding_quantity'] + 0 }}</strong>
                                </td>

                                <td data-label="Status" class="inventory-history-status-cell">
                                    @if($row['item_status'] === 'RETURNED_ON_TIME')
                                        <x-status-badge status="CLOSED" label="Returned On Time" />
                                    @elseif($row['item_status'] === 'RETURNED_LATE')
                                        <x-status-badge status="OVERDUE" label="Returned Late" />
                                    @elseif($row['item_status'] === 'OVERDUE')
                                        <x-status-badge status="OVERDUE" label="Overdue" />
                                    @elseif($row['item_status'] === 'IN_LAUNDRY')
                                        <x-status-badge status="PENDING" label="In Laundry" />
                                    @else
                                        <x-status-badge status="ACTIVE" label="On Custody" />
                                    @endif

                                    @if(filled($row['accountability_label'] ?? null))
                                        <small class="inventory-history-accountability {{ ($row['accountability_active'] ?? false) ? 'is-active' : 'is-resolved' }}">
                                            {{ $row['accountability_label'] }}
                                        </small>
                                    @endif
                                </td>

                                <td data-label="Action">
                                    <a
                                        class="button secondary small ui-pressable"
                                        href="{{ route('custody.show', $row['custody']) }}"
                                    >
                                        <span>View Custody</span>
                                        <x-icon name="arrow-right" size="14" />
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9">
                                    <div class="empty-state inventory-history-empty">
                                        <strong>No actual borrowing record found for this period.</strong>
                                        <span>
                                            Try another date range or search. Reservations that
                                            were never physically issued are not counted here.
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>
        </section>

        <section class="inventory-tab-panel" data-inventory-panel="item-information" role="tabpanel" @if($activeInventoryTab !== 'item-information') hidden @endif>
        <article class="card inventory-master-detail-card">
            <div class="card-header">
                <div>
                    <p class="eyebrow">Inventory master record</p>
                    <h2>Item information</h2>
                </div>

                @if($canEditInventory)
                    <a class="button secondary small ui-pressable" href="{{ route('inventory.edit', $item) }}">
                        <x-icon name="edit" size="16" />
                        Edit item
                    </a>
                @endif
            </div>

            <dl class="detail-list compact">
                <dt>Item ID</dt>
                <dd>{{ 'INV-'.str_pad((string) $item->id, 4, '0', STR_PAD_LEFT) }}</dd>

                <dt>Description</dt>
                <dd>{{ $item->specification ?: 'No additional description.' }}</dd>

                <dt>Category</dt>
                <dd>{{ $item->category->category_name }}</dd>

                <dt>Unit of measure</dt>
                <dd>{{ $item->unit->unit_name }}</dd>

                <dt>Borrowing eligibility</dt>
                <dd><span class="inventory-info-pill {{ $item->borrowable ? 'is-positive' : 'is-neutral' }}">{{ $item->borrowable ? 'Borrowable' : 'Not borrowable' }}</span></dd>

                <dt>Use restriction</dt>
                <dd><span class="inventory-info-pill is-neutral">{{ $item->off_campus_allowed ? 'Off-campus eligible' : 'On-campus only' }}</span></dd>

                <dt>Laundry requirement</dt>
                <dd><span class="inventory-info-pill {{ $item->laundry_required ? 'is-positive' : 'is-neutral' }}">{{ $item->laundry_required ? 'Required after use' : 'Not required' }}</span></dd>

                <dt>Date added</dt>
                <dd>{{ optional($item->created_at)->format('d M Y, g:i A') ?: '—' }}</dd>

                <dt>Last updated</dt>
                <dd>{{ optional($item->updated_at)->format('d M Y, g:i A') ?: '—' }}</dd>
            </dl>
        </article>
        </section>
    @endif
</section>


<style>
.inventory-admin-summary {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 18px;
}

.inventory-admin-summary-item {
    --inventory-summary-accent: var(--border-strong);
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
    min-height: 96px;
    padding: 15px 16px;
    border: 1px solid var(--border);
    border-top: 3px solid var(--inventory-summary-accent);
    border-radius: 12px;
    background: var(--surface, #fff);
    box-shadow: 0 1px 2px rgba(7, 27, 53, .05);
}
.inventory-admin-summary-item.is-total { --inventory-summary-accent: #2f80ed; }
.inventory-admin-summary-item.is-available { --inventory-summary-accent: var(--success); }
.inventory-admin-summary-item.is-reserved { --inventory-summary-accent: var(--warning); }
.inventory-admin-summary-item.is-custody { --inventory-summary-accent: #6d5ce7; }
.inventory-admin-summary-item.is-unavailable { --inventory-summary-accent: var(--danger); }

.inventory-summary-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 34px;
    height: 34px;
    border-radius: 9px;
    background: #eef2f7;
    color: #64748b;
}

.inventory-admin-summary-item.is-total .inventory-summary-icon {
    background: #e5edff;
    color: #2f5fd6;
}

.inventory-admin-summary-item.is-available .inventory-summary-icon {
    background: #e2f6ea;
    color: #157f3f;
}

.inventory-admin-summary-item.is-reserved .inventory-summary-icon {
    background: #fdf1dd;
    color: #b1710a;
}

.inventory-admin-summary-item.is-custody .inventory-summary-icon {
    background: #f0e9fb;
    color: #7341c9;
}

.inventory-admin-summary-item.is-unavailable .inventory-summary-icon {
    background: #fde6e6;
    color: #c62b2b;
}

.inventory-summary-copy {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.inventory-summary-copy span {
    color: var(--text-muted);
    font-size: .74rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .025em;
}

.inventory-summary-copy strong {
    color: var(--text);
    font-size: 1.16rem;
}

.inventory-admin-summary-item.is-available strong {
    color: #157f3f;
}

.inventory-last-movement {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 0 0 20px;
    padding: 13px 15px;
    border: 1px solid var(--border);
    border-radius: 11px;
    background: var(--surface-subtle, #f7f9fc);
}
.inventory-last-movement-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 34px;
    width: 34px;
    height: 34px;
    border-radius: 9px;
    background: #eaf3ff;
    color: #1769c2;
}
.inventory-last-movement-copy {
    display: grid;
    gap: 2px;
    min-width: 0;
}
.inventory-last-movement-copy > span {
    color: var(--text-muted);
    font-size: .71rem;
    font-weight: 800;
    letter-spacing: .04em;
    text-transform: uppercase;
}
.inventory-last-movement-copy strong {
    color: var(--heading);
    font-size: .88rem;
}
.inventory-last-movement-copy small {
    color: var(--text-muted);
    font-size: .76rem;
}
.inventory-inline-reference,
.inventory-ledger-reference-link {
    color: var(--interactive, #1769c2);
    font-weight: 750;
    text-decoration: none;
}
.inventory-inline-reference:hover,
.inventory-inline-reference:focus-visible,
.inventory-ledger-reference-link:hover,
.inventory-ledger-reference-link:focus-visible {
    text-decoration: underline;
}

.inventory-overview-grid { align-items: stretch; }
.inventory-overview-card { min-width: 0; height: 100%; overflow: hidden; }
.inventory-overview-card .card-header { min-height: 98px; padding: 20px 22px 16px; }
.inventory-overview-card .card-header .meta { margin: 5px 0 0; max-width: 620px; line-height: 1.45; }
.inventory-overview-card .inventory-ops-list { padding: 6px 22px 20px; }
.inventory-overview-empty { margin: -2px 22px 20px; color: var(--text-muted); font-size: .83rem; }

.inventory-source-records-card {
    margin-top: 24px;
    overflow: hidden;
}
.inventory-source-records-card .card-header {
    padding: 20px 22px 18px;
}
.inventory-source-records-card .card-header .meta {
    margin: 5px 0 0;
    max-width: 850px;
    line-height: 1.45;
}
.inventory-source-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 18px;
}
.inventory-source-header-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    flex-wrap: wrap;
}
.inventory-source-live {
    flex: 0 0 auto;
    padding: 5px 9px;
    border: 1px solid #bfe1cf;
    border-radius: 999px;
    background: #eef9f3;
    color: #177246;
    font-size: .7rem;
    font-weight: 800;
    letter-spacing: .035em;
    text-transform: uppercase;
}
.inventory-source-groups {
    display: grid;
    gap: 16px;
    padding: 18px 22px 16px;
}
.inventory-source-group {
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: 11px;
    background: var(--surface, #fff);
}
.inventory-source-group-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 13px 16px;
    border-bottom: 1px solid var(--border);
    background: var(--surface-subtle, #f8fafc);
}
.inventory-source-group-head > div {
    display: flex;
    align-items: baseline;
    gap: 10px;
    min-width: 0;
}
.inventory-source-group-head span {
    color: var(--text-secondary);
    font-size: .76rem;
    font-weight: 800;
    letter-spacing: .025em;
    text-transform: uppercase;
}
.inventory-source-group-head strong {
    color: var(--heading);
    font-size: .86rem;
}
.inventory-source-group-head small {
    color: var(--text-muted);
    font-size: .74rem;
    white-space: nowrap;
}
.inventory-source-records {
    display: grid;
}
.inventory-source-record {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(140px, .42fr) auto;
    align-items: center;
    gap: 20px;
    min-width: 0;
    padding: 15px 16px;
    border-bottom: 1px solid var(--border);
}
.inventory-source-record:last-child {
    border-bottom: 0;
}
.inventory-source-record-main,
.inventory-source-record-state {
    display: grid;
    gap: 3px;
    min-width: 0;
}
.inventory-source-kind {
    color: var(--text-muted);
    font-size: .68rem;
    font-weight: 800;
    letter-spacing: .035em;
    text-transform: uppercase;
}
.inventory-source-record-main strong {
    color: var(--heading);
    font-size: .86rem;
    overflow-wrap: anywhere;
}
.inventory-source-record-main small {
    color: var(--text-muted);
    font-size: .75rem;
    line-height: 1.35;
    overflow-wrap: anywhere;
}
.inventory-source-record-state {
    text-align: right;
}
.inventory-source-record-state strong {
    color: var(--heading);
    font-size: .86rem;
}
.inventory-source-record-state span {
    color: var(--text-secondary);
    font-size: .75rem;
    font-weight: 700;
}
.inventory-source-open {
    min-width: 64px;
    justify-content: center;
}
.inventory-source-note {
    margin: 0;
    padding: 0 22px 20px;
    color: var(--text-muted);
    font-size: .8rem;
    line-height: 1.45;
}
.inventory-source-empty-state {
    display: grid;
    gap: 4px;
    margin: 18px 22px 22px;
    padding: 15px 16px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--surface-subtle, #f8fafc);
}
.inventory-source-empty-state strong {
    color: var(--heading);
    font-size: .88rem;
}
.inventory-source-empty-state span {
    color: var(--text-muted);
    font-size: .8rem;
}

.inventory-history-status-cell {
    min-width: 148px;
}
.inventory-history-accountability {
    display: block;
    margin-top: 6px;
    color: var(--text-muted);
    font-size: .7rem;
    font-weight: 700;
    line-height: 1.35;
}
.inventory-history-accountability.is-active { color: var(--warning); }
.inventory-history-accountability.is-resolved { color: var(--success); }

@media (max-width: 900px) {
    .inventory-source-record {
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 12px 16px;
    }
    .inventory-source-record-state {
        text-align: right;
    }
    .inventory-source-open {
        grid-column: 1 / -1;
        width: 100%;
    }
}

@media (max-width: 620px) {
    .inventory-source-header,
    .inventory-source-header-actions,
    .inventory-source-group-head,
    .inventory-source-group-head > div {
        align-items: flex-start;
        flex-direction: column;
    }
    .inventory-source-record {
        grid-template-columns: 1fr;
    }
    .inventory-source-record-state {
        text-align: left;
    }
}

.inventory-ledger-table { min-width: 1180px; }
.inventory-ledger-reference strong, .inventory-ledger-actor strong { display: block; color: var(--heading); font-size: .82rem; }
.inventory-ledger-reference small, .inventory-ledger-actor small { display: block; margin-top: 3px; color: var(--text-muted); font-size: .75rem; }
.inventory-ledger-reason { max-width: 330px; line-height: 1.35; }

.inventory-detail-tabs {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
    margin: 18px 0 22px;
    padding: 0;
    border: 0;
    background: transparent;
}

.inventory-detail-tab {
    appearance: none;
    position: relative;
    min-width: 0;
    min-height: 48px;
    padding: 11px 16px;
    border: 1px solid #c7d5e4;
    border-radius: 10px;
    background: #fff;
    color: #334155;
    font: inherit;
    font-size: .88rem;
    font-weight: 800;
    line-height: 1.15;
    cursor: pointer;
    transition: background-color .16s ease, border-color .16s ease, color .16s ease, box-shadow .16s ease, transform .16s ease;
}

.inventory-detail-tab::before {
    content: '';
    position: absolute;
    top: -1px;
    left: 12px;
    right: 12px;
    height: 3px;
    border-radius: 0 0 999px 999px;
    background: transparent;
    transition: background-color .16s ease;
}

.inventory-detail-tab:hover:not(.is-active) {
    border-color: #9fc8ec;
    background: #f4f9ff;
    color: #075ea8;
    box-shadow: 0 4px 12px rgba(15, 42, 67, .07);
    transform: translateY(-1px);
}

.inventory-detail-tab:focus-visible {
    outline: 3px solid rgba(37, 136, 214, .20);
    outline-offset: 2px;
}

.inventory-detail-tab.is-active {
    border-color: #9fc8ec;
    background: #eaf5ff;
    color: #075ea8;
    box-shadow: 0 4px 12px rgba(7, 94, 168, .10);
}

.inventory-detail-tab.is-active::before {
    background: #0b66c3;
}

.inventory-detail-tab.is-active:hover {
    background: #e4f2ff;
}

@media (max-width: 900px) {
    .inventory-detail-tabs {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 560px) {
    .inventory-detail-tabs {
        grid-template-columns: 1fr;
    }
}

.inventory-tab-panel[hidden] {
    display: none !important;
}

.inventory-tab-panel > .card,
.inventory-tab-panel > .inventory-detail-grid {
    margin-top: 12px;
}

.inventory-tab-overview .inventory-detail-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 26px;
}

.inventory-detail-grid {
    display: grid;
    align-items: stretch;
}

.inventory-ops-list {
    display: grid;
}

.inventory-ops-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    min-height: 52px;
    padding: 13px 0;
    border-bottom: 1px solid var(--border);
}

.inventory-ops-row:last-child {
    border-bottom: 0;
}

.inventory-ops-row > span {
    font-weight: 600;
    color: var(--text-secondary, #475569);
}

.inventory-ops-row > strong {
    font-size: 1rem;
    color: var(--text);
}

.inventory-ops-row.is-total {
    margin-top: 4px;
    padding-top: 13px;
    border-top: 1px solid var(--border);
    border-bottom: 0;
    font-weight: 700;
}


.inventory-info-pill {
    display: inline-block;
    padding: 3px 11px;
    border-radius: 999px;
    font-size: .82rem;
    font-weight: 600;
}

.inventory-info-pill.is-positive {
    background: #e2f6ea;
    color: #157f3f;
}

.inventory-info-pill.is-neutral {
    background: #e5edff;
    color: #2f5fd6;
}

.inventory-borrowing-history-card {
    display: grid;
    gap: 20px;
    overflow: hidden;
}
.inventory-borrowing-history-card > .inventory-history-filter,
.inventory-borrowing-history-card > .inventory-history-period-note,
.inventory-borrowing-history-card > .inventory-history-summary {
    margin-left: 18px;
    margin-right: 18px;
}

.inventory-history-header .meta {
    max-width: 900px;
    margin-bottom: 0;
}

.inventory-history-filter {
    display: grid;
    grid-template-columns: minmax(150px, .7fr) minmax(150px, .7fr) minmax(190px, .9fr) minmax(260px, 1.5fr) auto;
    gap: 12px;
    align-items: end;
}

.inventory-history-filter label {
    display: grid;
    gap: 6px;
    min-width: 0;
}

.inventory-history-filter input,
.inventory-history-filter select {
    width: 100%;
}

.inventory-history-filter-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    white-space: nowrap;
}

.inventory-history-period-note {
    display: flex;
    gap: 9px;
    align-items: flex-start;
    padding: 11px 13px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface-subtle, #f7f9fc);
    color: var(--text-secondary, #475569);
    font-size: .88rem;
}

.inventory-history-summary {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    background: var(--surface, #fff);
}

.inventory-history-metric {
    display: flex;
    align-items: baseline;
    gap: 7px;
    min-width: 0;
    padding: 12px 14px;
    border-right: 1px solid var(--border);
}

.inventory-history-metric:last-child {
    border-right: 0;
}

.inventory-history-metric strong {
    font-size: 1.05rem;
}

.inventory-history-metric span {
    color: var(--text-muted);
    font-size: .82rem;
}

.inventory-history-table-wrap {
    max-height: 560px;
    margin: 0 18px 18px;
    overflow: auto;
    overscroll-behavior: contain;
    border: 1px solid var(--border);
    border-radius: 10px;
}

.inventory-history-table {
    min-width: 1280px;
}

.inventory-history-table thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: var(--surface-subtle, #f5f7fb);
}

.inventory-history-table td {
    padding-top: 15px;
    padding-bottom: 15px;
    vertical-align: top;
}
.inventory-history-table thead th {
    padding-top: 13px;
    padding-bottom: 13px;
}

.inventory-history-table td > small,
.inventory-history-custody-status {
    display: block;
    margin-top: 4px;
    color: var(--text-muted);
}

.inventory-history-dates {
    min-width: 245px;
}

.inventory-history-dates span {
    display: grid;
    grid-template-columns: 86px 1fr;
    gap: 8px;
    align-items: baseline;
    margin-bottom: 5px;
    font-size: .81rem;
}

.inventory-history-dates b {
    color: var(--text-secondary, #475569);
    font-weight: 600;
}

.inventory-history-dates em {
    color: var(--text);
    font-style: normal;
    white-space: nowrap;
}

.inventory-history-empty {
    min-height: 150px;
}

@media (max-width: 1180px) {
    .inventory-admin-summary {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .inventory-history-filter {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .inventory-history-search-field,
    .inventory-history-filter-actions {
        grid-column: 1 / -1;
    }

    .inventory-history-summary {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

/* Keep Unavailable Breakdown and Physical Condition on one desktop row.
   Stack only when the content area is genuinely narrow. */
@media (max-width: 860px) {
    .inventory-tab-overview .inventory-detail-grid {
        grid-template-columns: 1fr;
        gap: 18px;
    }
}

@media (max-width: 700px) {
    .inventory-admin-summary {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .inventory-detail-tab {
        flex: 0 0 auto;
        min-width: 132px;
    }

    .inventory-ops-row {
        gap: 4px 12px;
    }

    .inventory-last-activity {
        align-items: flex-start;
        flex-direction: column;
        gap: 2px;
    }

    .inventory-history-filter,
    .inventory-history-summary {
        grid-template-columns: 1fr;
    }

    .inventory-history-metric {
        border-right: 0;
        border-bottom: 1px solid var(--border);
    }

    .inventory-history-metric:last-child {
        border-bottom: 0;
    }

    .inventory-history-search-field,
    .inventory-history-filter-actions {
        grid-column: auto;
    }

    .inventory-history-filter-actions {
        align-items: stretch;
        flex-direction: column;
    }

    .inventory-history-filter-actions .button {
        width: 100%;
    }
}

@media print {
    .sidebar,
    .topbar,
    .inventory-history-filter,
    .inventory-history-filter-actions,
    .inventory-master-detail-card .button,
    .inventory-history-table .button,
    .page-heading > .button {
        display: none !important;
    }

    .inventory-history-table-wrap {
        max-height: none;
        overflow: visible;
    }
}
</style>


<script>
document.addEventListener('DOMContentLoaded', () => {
    const tabButtons = Array.from(document.querySelectorAll('[data-inventory-tab]'));
    const tabPanels = Array.from(document.querySelectorAll('[data-inventory-panel]'));

    if (!tabButtons.length || !tabPanels.length) {
        return;
    }

    const validTabs = new Set(tabButtons.map((button) => button.dataset.inventoryTab));

    const activateTab = (tabName, updateUrl = true) => {
        if (!validTabs.has(tabName)) {
            tabName = 'overview';
        }

        tabButtons.forEach((button) => {
            const active = button.dataset.inventoryTab === tabName;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        tabPanels.forEach((panel) => {
            panel.hidden = panel.dataset.inventoryPanel !== tabName;
        });

        if (updateUrl) {
            const url = new URL(window.location.href);
            if (tabName === 'overview') {
                url.searchParams.delete('tab');
            } else {
                url.searchParams.set('tab', tabName);
            }
            url.hash = '';
            window.history.replaceState({}, '', url);
        }
    };

    tabButtons.forEach((button) => {
        button.addEventListener('click', () => activateTab(button.dataset.inventoryTab));
    });

    const initialTab = tabButtons.find((button) => button.classList.contains('is-active'))?.dataset.inventoryTab || 'overview';
    activateTab(initialTab, false);
});
</script>

{{-- BORROWER_INVENTORY_POLISH_V3_START --}}
<style>
    .borrower-inventory-polished {
        overflow: hidden;
    }

    .borrower-inventory-card-header .meta {
        margin: 4px 0 0;
        color: var(--text-muted);
        font-size: 13px;
    }

    .borrower-availability-highlight {
        display: flex;
        align-items: center;
        gap: 18px;
        margin: 18px 0 20px;
        padding: 18px 20px;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        background: var(--surface-subtle, var(--surface));
    }

    .borrower-availability-number {
        display: flex;
        align-items: baseline;
        gap: 7px;
        min-width: max-content;
    }

    .borrower-availability-number strong {
        color: var(--heading);
        font-size: 34px;
        line-height: 1;
        letter-spacing: -0.03em;
    }

    .borrower-availability-number span {
        color: var(--text-muted);
        font-size: 14px;
        font-weight: 700;
    }

    .borrower-availability-copy {
        display: grid;
        gap: 3px;
        min-width: 0;
        padding-left: 18px;
        border-left: 1px solid var(--border);
    }

    .borrower-availability-copy strong {
        color: var(--heading);
        font-size: 15px;
    }

    .borrower-availability-copy span {
        color: var(--text-muted);
        font-size: 12px;
        line-height: 1.45;
    }

    .borrower-item-details {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        border-top: 1px solid var(--border);
    }

    .borrower-item-detail {
        display: grid;
        gap: 6px;
        min-width: 0;
        padding: 16px 18px;
        border-bottom: 1px solid var(--border);
    }

    .borrower-item-detail:nth-child(odd):not(.borrower-item-detail-wide) {
        border-right: 1px solid var(--border);
    }

    .borrower-item-detail-wide {
        grid-column: 1 / -1;
    }

    .borrower-item-detail span {
        color: var(--text-muted);
        font-size: 11px;
        font-weight: 800;
        letter-spacing: .035em;
        text-transform: uppercase;
    }

    .borrower-item-detail strong {
        color: var(--heading);
        font-size: 14px;
        font-weight: 700;
        line-height: 1.5;
        overflow-wrap: anywhere;
    }

    .borrower-detail-with-icon {
        display: inline-flex;
        align-items: center;
        gap: 7px;
    }

    .borrower-reference-note {
        margin-top: 14px;
    }

    @media (max-width: 700px) {
        .borrower-availability-highlight {
            align-items: flex-start;
            flex-direction: column;
            gap: 12px;
        }

        .borrower-availability-copy {
            padding-left: 0;
            padding-top: 12px;
            border-left: 0;
            border-top: 1px solid var(--border);
            width: 100%;
        }

        .borrower-item-details {
            grid-template-columns: 1fr;
        }

        .borrower-item-detail,
        .borrower-item-detail:nth-child(odd):not(.borrower-item-detail-wide) {
            border-right: 0;
        }

        .borrower-item-detail-wide {
            grid-column: auto;
        }
    }
</style>
{{-- BORROWER_INVENTORY_POLISH_V3_END --}}

@endsection
