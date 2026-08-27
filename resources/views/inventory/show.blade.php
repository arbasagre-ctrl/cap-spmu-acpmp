@extends('layouts.app', ['title' => $item->unique_description])

@section('content')

@php
    $total = (float) ($balance['total'] ?? 0);
    $available = (float) ($balance['borrower_available'] ?? 0);
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
    $canEditInventory = auth()->user()?->access_classification?->value === 'SPMU_HEAD';

    $requestedInventoryTab = (string) request('tab', 'overview');
    $activeInventoryTab = in_array($requestedInventoryTab, [
        'overview',
        'stock-card',
        'borrowing-history',
        'item-information',
    ], true) ? $requestedInventoryTab : 'overview';
@endphp

<section class="page-heading inventory-detail-heading">
    <div>
        <p class="eyebrow">{{ $isBorrower ? 'Inventory reference' : 'Inventory details' }}</p>
        <h1>{{ $item->unique_description }}</h1>
        <p>{{ 'INV-'.str_pad((string) $item->id, 4, '0', STR_PAD_LEFT) }} &middot; {{ $item->category->category_name }} &middot; {{ $item->unit->unit_name }}</p>
    </div>

    <a class="button secondary ui-pressable" href="{{ route('inventory.index') }}">
        Back to Inventory
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
            <div class="inventory-admin-summary-item">
                <span>Total Stock</span>
                <strong>{{ $total + 0 }}</strong>
            </div>
            <div class="inventory-admin-summary-item is-available">
                <span>Available</span>
                <strong>{{ $available + 0 }}</strong>
            </div>
            <div class="inventory-admin-summary-item">
                <span>Reserved</span>
                <strong>{{ $reserved + 0 }}</strong>
            </div>
            <div class="inventory-admin-summary-item">
                <span>On Custody</span>
                <strong>{{ $issued + 0 }}</strong>
            </div>
            <div class="inventory-admin-summary-item">
                <span>Laundry / Incident</span>
                <strong>{{ ($laundry + $incident) + 0 }}</strong>
            </div>
        </section>

        <nav class="inventory-detail-tabs" aria-label="Inventory detail sections" role="tablist">
            <button type="button" class="inventory-detail-tab {{ $activeInventoryTab === 'overview' ? 'is-active' : '' }}" data-inventory-tab="overview" role="tab" aria-selected="{{ $activeInventoryTab === 'overview' ? 'true' : 'false' }}">Overview</button>
            <button type="button" class="inventory-detail-tab {{ $activeInventoryTab === 'stock-card' ? 'is-active' : '' }}" data-inventory-tab="stock-card" role="tab" aria-selected="{{ $activeInventoryTab === 'stock-card' ? 'true' : 'false' }}">Stock Card</button>
            <button type="button" class="inventory-detail-tab {{ $activeInventoryTab === 'borrowing-history' ? 'is-active' : '' }}" data-inventory-tab="borrowing-history" role="tab" aria-selected="{{ $activeInventoryTab === 'borrowing-history' ? 'true' : 'false' }}">Borrowing History</button>
            <button type="button" class="inventory-detail-tab {{ $activeInventoryTab === 'item-information' ? 'is-active' : '' }}" data-inventory-tab="item-information" role="tab" aria-selected="{{ $activeInventoryTab === 'item-information' ? 'true' : 'false' }}">Item Information</button>
        </nav>

        <section class="inventory-tab-panel inventory-tab-overview" data-inventory-panel="overview" role="tabpanel" @if($activeInventoryTab !== 'overview') hidden @endif>
        <div class="inventory-detail-grid">
            <article class="card">
                <div class="card-header">
                    <div>
                        <p class="eyebrow">Quantity status</p>
                        <h2>Operational breakdown</h2>
                    </div>
                </div>

                <div class="inventory-ops-list" role="list">
                    <div class="inventory-ops-row" role="listitem">
                        <span>Total inventory</span>
                        <strong>{{ $total + 0 }}</strong>
                        <small>Recorded quantity</small>
                    </div>
                    <div class="inventory-ops-row" role="listitem">
                        <span>Available for allocation</span>
                        <strong>{{ $available + 0 }}</strong>
                        <small>Ready for approved allocation</small>
                    </div>
                    <div class="inventory-ops-row" role="listitem">
                        <span>Reserved</span>
                        <strong>{{ $reserved + 0 }}</strong>
                        <small>Approved, not yet issued</small>
                    </div>
                    <div class="inventory-ops-row" role="listitem">
                        <span>Issued</span>
                        <strong>{{ $issued + 0 }}</strong>
                        <small>Under borrower custody</small>
                    </div>
                    <div class="inventory-ops-row" role="listitem">
                        <span>In laundry</span>
                        <strong>{{ $laundry + 0 }}</strong>
                        <small>Temporarily unavailable</small>
                    </div>
                    <div class="inventory-ops-row" role="listitem">
                        <span>Accountability / incidents</span>
                        <strong>{{ $incident + 0 }}</strong>
                        <small>Under incident resolution</small>
                    </div>
                    <div class="inventory-ops-row is-total" role="listitem">
                        <span>Unavailable total</span>
                        <strong>{{ $unavailable + 0 }}</strong>
                        <small>Not currently allocable</small>
                    </div>
                </div>
            </article>

            <article class="card">
                <div class="card-header">
                    <div>
                        <p class="eyebrow">Physical condition</p>
                        <h2>Condition breakdown</h2>
                    </div>
                    <x-status-badge :status="$item->condition_code" />
                </div>

                <dl class="detail-list compact inventory-breakdown-list">
                    <dt>Good / serviceable</dt>
                    <dd><strong>{{ $recordedGood + 0 }}</strong></dd>

                    <dt>Damaged / under repair</dt>
                    <dd><strong>{{ $damagedMaintenance + 0 }}</strong></dd>

                    @if($lost > 0)
                        <dt>Lost</dt>
                        <dd><strong>{{ $lost + 0 }}</strong></dd>
                    @endif

                    @if($stolen > 0)
                        <dt>Stolen</dt>
                        <dd><strong>{{ $stolen + 0 }}</strong></dd>
                    @endif

                    @if($destroyed > 0)
                        <dt>Destroyed</dt>
                        <dd><strong>{{ $destroyed + 0 }}</strong></dd>
                    @endif

                    <dt>Condemned</dt>
                    <dd><strong>{{ $condemned + 0 }}</strong></dd>
                </dl>
            </article>
        </div>
        </section>

        <section class="inventory-tab-panel" data-inventory-panel="stock-card" role="tabpanel" @if($activeInventoryTab !== 'stock-card') hidden @endif>
        <article class="card inventory-borrowing-history-card" id="stock-card">
            <div class="card-header inventory-history-header">
                <div>
                    <p class="eyebrow">Read-only inventory ledger</p>
                    <h2>Stock Card</h2>
                    <p class="meta">Latest recorded inventory movements for {{ 'INV-'.str_pad((string) $item->id, 4, '0', STR_PAD_LEFT) }}. This view is read-only.</p>
                </div>
            </div>
            <div class="table-wrap inventory-history-table-wrap">
                <table class="inventory-history-table">
                    <thead><tr><th>Date</th><th>Transaction</th><th>From</th><th>To</th><th>Quantity</th><th>Balance change</th><th>Reason / Actor</th></tr></thead>
                    <tbody>
                    @forelse($stockCard as $entry)
                        <tr>
                            <td>{{ \Illuminate\Support\Carbon::parse($entry->occurred_at)->format('d M Y, g:i A') }}</td>
                            <td>{{ str($entry->transaction_type)->replace('_',' ')->title() }}</td>
                            <td>{{ $entry->from_state ?: '—' }}</td>
                            <td>{{ $entry->to_state ?: '—' }}</td>
                            <td><strong>{{ (float) $entry->quantity + 0 }}</strong></td>
                            <td>{{ $entry->before_quantity !== null ? ((float)$entry->before_quantity + 0) : '—' }} → {{ $entry->after_quantity !== null ? ((float)$entry->after_quantity + 0) : '—' }}</td>
                            <td><strong>{{ $entry->reason ?: 'Recorded inventory movement' }}</strong><small>{{ $entry->actor_email ?: 'System' }}</small></td>
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
                        <option value="OPEN" @selected($historyStatus === 'OPEN')>On custody / outstanding</option>
                        <option value="RETURNED" @selected($historyStatus === 'RETURNED')>Returned</option>
                        <option value="OVERDUE" @selected($historyStatus === 'OVERDUE')>Overdue</option>
                    </select>
                </label>

                <label class="inventory-history-search-field">
                    Search
                    <input
                        type="search"
                        name="history_search"
                        value="{{ $historySearch }}"
                        placeholder="Borrower, office, request, custody, purpose..."
                    >
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
                                    <small>{{ $row['office'] ?: 'No office / department recorded' }}</small>
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

                                <td data-label="Status">
                                    @if($row['item_status'] === 'RETURNED')
                                        <x-status-badge status="CLOSED" label="Returned" />
                                    @elseif($row['item_status'] === 'OVERDUE')
                                        <x-status-badge status="OVERDUE" label="Overdue" />
                                    @else
                                        <x-status-badge status="ACTIVE" label="On Custody" />
                                    @endif

                                </td>

                                <td data-label="Action">
                                    <a
                                        class="button secondary small ui-pressable"
                                        href="{{ route('custody.show', $row['custody']) }}"
                                    >
                                        View Borrowing
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
                <dt>Description</dt>
                <dd>{{ $item->specification ?: 'No additional description.' }}</dd>

                <dt>Category</dt>
                <dd>{{ $item->category->category_name }}</dd>

                <dt>Unit of measure</dt>
                <dd>{{ $item->unit->unit_name }}</dd>

                <dt>Borrowing eligibility</dt>
                <dd>{{ $item->borrowable ? 'Borrowable' : 'Not borrowable' }}</dd>

                <dt>Use restriction</dt>
                <dd>{{ $item->off_campus_allowed ? 'Off-campus eligible' : 'On-campus only' }}</dd>

                <dt>Laundry requirement</dt>
                <dd>{{ $item->laundry_required ? 'Required after use' : 'Not required' }}</dd>
            </dl>
        </article>
        </section>
    @endif
</section>


<style>
.inventory-admin-summary {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    overflow: hidden;
    margin-bottom: 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface, #fff);
}

.inventory-admin-summary-item {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
    min-width: 0;
    padding: 13px 16px;
    border-right: 1px solid var(--border);
}

.inventory-admin-summary-item:last-child {
    border-right: 0;
}

.inventory-admin-summary-item span {
    color: var(--text-muted);
    font-size: .78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .025em;
}

.inventory-admin-summary-item strong {
    flex: 0 0 auto;
    color: var(--text);
    font-size: 1.16rem;
}

.inventory-admin-summary-item.is-available strong {
    color: #157f3f;
}

.inventory-detail-tabs {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 16px;
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
    margin-top: 0;
}

.inventory-tab-overview .inventory-detail-grid {
    grid-template-columns: minmax(0, 1.35fr) minmax(320px, .65fr);
    gap: 16px;
}

.inventory-detail-grid {
    align-items: stretch;
}

.inventory-ops-list {
    display: grid;
}

.inventory-ops-row {
    display: grid;
    grid-template-columns: minmax(220px, 1.25fr) minmax(70px, .35fr) minmax(220px, 1.45fr);
    gap: 18px;
    align-items: center;
    min-height: 46px;
    padding: 10px 0;
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

.inventory-ops-row > small {
    color: var(--text-muted);
    line-height: 1.35;
}

.inventory-ops-row.is-total {
    font-weight: 700;
}

.inventory-borrowing-history-card {
    display: grid;
    gap: 18px;
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
    overflow: auto;
    overscroll-behavior: contain;
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
    vertical-align: top;
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

    .inventory-admin-summary-item {
        border-bottom: 1px solid var(--border);
    }

    .inventory-admin-summary-item:nth-child(3n) {
        border-right: 0;
    }

    .inventory-tab-overview .inventory-detail-grid {
        grid-template-columns: 1fr;
    }

    .inventory-ops-row {
        grid-template-columns: minmax(190px, 1fr) 70px minmax(180px, 1.2fr);
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

@media (max-width: 700px) {
    .inventory-admin-summary {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .inventory-admin-summary-item,
    .inventory-admin-summary-item:nth-child(3n) {
        border-right: 1px solid var(--border);
    }

    .inventory-admin-summary-item:nth-child(2n) {
        border-right: 0;
    }

    .inventory-detail-tab {
        flex: 0 0 auto;
        min-width: 132px;
    }

    .inventory-ops-row {
        grid-template-columns: 1fr auto;
        gap: 4px 12px;
    }

    .inventory-ops-row > small {
        grid-column: 1 / -1;
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
