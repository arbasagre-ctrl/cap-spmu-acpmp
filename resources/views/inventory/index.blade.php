@extends('layouts.app', [
    'title' => session('active_workspace') === 'BORROWER'
        ? 'Available Items'
        : 'Inventory'
])

@section('content')

@php
    $isBorrower = session('active_workspace') === 'BORROWER';
    $isSpmu = session('active_workspace') === 'SPMU';
@endphp

<section class="page-heading">
    <div>
        <p class="eyebrow">
            {{ $isBorrower ? 'Borrowing availability' : 'Inventory monitoring' }}
        </p>

        <h1>{{ $isBorrower ? 'Available Items' : 'Inventory' }}</h1>

        <p>
            {{ $isBorrower
                ? 'Check which serviceable items have available quantity for your selected dates. Availability is a guide only; SPMU still decides whether the request can be approved.'
                : 'Monitor physical stock, reservations, active custody, laundry/incident states, condition, and borrowing restrictions.' }}
        </p>
    </div>

    @if($isBorrower)
        <a
            class="button primary ui-pressable"
            href="{{ route('requests.create') }}"
        >
            <x-icon name="plus" />
            New Request
        </a>
    @elseif($isSpmu)
        <a
            class="button primary ui-pressable"
            href="{{ route('inventory.create') }}"
        >
            <x-icon name="plus" />
            Add Inventory Item
        </a>
    @endif
</section>

<section class="content-area">
    <form
        method="get"
        class="filter-bar availability-filter"
        aria-label="Check availability for a borrowing period"
    >
        <label>
            Items needed from
            <input
                type="date"
                name="from"
                value="{{ $from->format('Y-m-d') }}"
            >
        </label>

        <label>
            Expected return date
            <input
                type="date"
                name="to"
                value="{{ $to->format('Y-m-d') }}"
            >
        </label>

        <button class="button primary">
            Check Availability
        </button>
    </form>
</section>


@if($isBorrower)

    @php
        $borrowerCategories = $items
            ->map(fn ($item) => $item->category?->category_name)
            ->filter()
            ->unique()
            ->sort()
            ->values();
    @endphp

    <section class="content-area borrower-availability-browser">

        <style>
            .borrower-availability-browser {
                --inventory-filter-line: var(--border, #d7e0ea);
                --inventory-filter-soft: var(--surface-subtle, #f7f9fc);
                --inventory-filter-muted: var(--text-muted, #64748b);
                --inventory-filter-ink: var(--text, #18324a);
            }

            .borrower-inventory-browser-toolbar {
                display: grid;
                grid-template-columns: minmax(280px, 1.5fr) minmax(190px, .65fr) minmax(190px, .65fr);
                gap: 12px;
                align-items: end;
                margin: 16px 0 12px;
                padding: 14px;
                border: 1px solid var(--inventory-filter-line);
                border-radius: 12px;
                background: var(--surface, #fff);
            }

            .borrower-inventory-browser-toolbar label {
                min-width: 0;
                margin: 0;
            }

            .borrower-inventory-browser-toolbar input,
            .borrower-inventory-browser-toolbar select {
                width: 100%;
                margin-top: 7px;
            }

            .borrower-inventory-browser-summary {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                flex-wrap: wrap;
                margin: 0 0 10px;
                color: var(--inventory-filter-muted);
                font-size: 12px;
            }

            .borrower-inventory-browser-summary strong {
                color: var(--inventory-filter-ink);
            }

            .borrower-inventory-page-size {
                display: inline-flex;
                align-items: center;
                min-height: 28px;
                padding: 5px 9px;
                border: 1px solid var(--inventory-filter-line);
                border-radius: 999px;
                background: var(--inventory-filter-soft);
                color: var(--inventory-filter-muted);
                font-size: 11px;
                font-weight: 700;
            }

            .borrower-inventory-table tbody tr[hidden] {
                display: none !important;
            }

            .borrower-inventory-pagination {
                display: grid;
                grid-template-columns: auto minmax(130px, 1fr) auto;
                align-items: center;
                gap: 10px;
                margin-top: 12px;
                padding: 11px 12px;
                border: 1px solid var(--inventory-filter-line);
                border-radius: 12px;
                background: var(--surface, #fff);
            }

            .borrower-inventory-page-label {
                color: var(--inventory-filter-muted);
                font-size: 12px;
                font-weight: 700;
                text-align: center;
            }

            .borrower-inventory-no-results {
                margin-top: 10px;
                padding: 24px 16px;
                border: 1px dashed var(--inventory-filter-line);
                border-radius: 12px;
                background: var(--inventory-filter-soft);
                color: var(--inventory-filter-muted);
                text-align: center;
                font-size: 13px;
            }

            .borrower-inventory-no-results[hidden] {
                display: none !important;
            }

            @media (max-width: 900px) {
                .borrower-inventory-browser-toolbar {
                    grid-template-columns: 1fr 1fr;
                }

                .borrower-inventory-browser-toolbar .borrower-inventory-search-field {
                    grid-column: 1 / -1;
                }
            }

            @media (max-width: 620px) {
                .borrower-inventory-browser-toolbar {
                    grid-template-columns: 1fr;
                }

                .borrower-inventory-browser-toolbar .borrower-inventory-search-field {
                    grid-column: auto;
                }

                .borrower-inventory-pagination {
                    grid-template-columns: 1fr 1fr;
                }

                .borrower-inventory-page-label {
                    grid-column: 1 / -1;
                    grid-row: 1;
                }

                .borrower-inventory-pagination .button {
                    width: 100%;
                    justify-content: center;
                }
            }
        </style>

        <div class="availability-window" role="note">
            <strong>
                {{ $from->format('d M Y') }}
                to
                {{ $to->format('d M Y') }}
            </strong>

            <span>
                Only active, borrowable, serviceable items are shown.
                A positive quantity does not guarantee approval and does not reserve stock.
            </span>
        </div>

        <div
            class="borrower-inventory-browser-toolbar"
            aria-label="Search and filter available items"
        >
            <label class="borrower-inventory-search-field">
                Search item or category
                <input
                    id="borrower-inventory-search"
                    type="search"
                    placeholder="Search item, category, description, or unit..."
                    autocomplete="off"
                >
            </label>

            <label>
                Category
                <select id="borrower-inventory-category">
                    <option value="">All Categories</option>

                    @foreach($borrowerCategories as $categoryName)
                        <option value="{{ strtolower($categoryName) }}">
                            {{ $categoryName }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label>
                Use
                <select id="borrower-inventory-use">
                    <option value="">All Use</option>
                    <option value="on-campus-only">On-campus only</option>
                    <option value="off-campus-allowed">Off-campus allowed</option>
                </select>
            </label>
        </div>

        <div class="borrower-inventory-browser-summary">
            <span id="borrower-inventory-result-label">
                Showing available inventory
            </span>

            <span class="borrower-inventory-page-size">
                10 items per page
            </span>
        </div>

        <div class="table-wrap borrower-inventory-table">
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Available for selected dates</th>
                        <th>Unit</th>
                        <th>Use</th>
                        <th>Condition</th>
                        <th>
                            <span class="visually-hidden">Action</span>
                        </th>
                    </tr>
                </thead>

                <tbody id="borrower-inventory-table-body">
                    @forelse($items as $item)

                        @php
                            $balance = $balances[$item->id] ?? [];

                            $available = (float) (
                                $balance['borrower_available']
                                ?? $balance['available']
                                ?? 0
                            );

                            $categoryName = $item->category?->category_name ?: 'Uncategorized';
                            $unitName = $item->unit?->unit_name ?: '';
                            $useFilter = $item->off_campus_allowed
                                ? 'off-campus-allowed'
                                : 'on-campus-only';

                            $searchText = strtolower(
                                $item->unique_description.' '.
                                ($item->specification ?? '').' '.
                                $categoryName.' '.
                                $unitName
                            );
                        @endphp

                        <tr
                            data-borrower-inventory-row
                            data-search="{{ $searchText }}"
                            data-category="{{ strtolower($categoryName) }}"
                            data-use="{{ $useFilter }}"
                        >
                            <td data-label="Item">
                                <strong>
                                    {{ $item->unique_description }}
                                </strong>

                                @if($item->specification)
                                    <small>
                                        {{ $item->specification }}
                                    </small>
                                @endif

                                <small>
                                    {{ $categoryName }}
                                </small>
                            </td>

                            <td data-label="Available">
                                <strong class="availability-number">
                                    {{ $available + 0 }}
                                </strong>

                                <x-status-badge
                                    :status="$available > 0 ? 'AVAILABLE' : 'UNAVAILABLE'"
                                    :label="$available > 0 ? 'Available' : 'Unavailable'"
                                />
                            </td>

                            <td data-label="Unit">
                                {{ $unitName }}
                            </td>

                            <td data-label="Use">
                                <strong>
                                    {{ $item->off_campus_allowed
                                        ? 'On-campus or Off-campus'
                                        : 'On-campus only' }}
                                </strong>

                                @if($item->off_campus_allowed)
                                    <small>
                                        Off-campus requests require a Gate Pass after approval.
                                    </small>
                                @endif

                                @if($item->laundry_required)
                                    <small>
                                        Laundry process required after use.
                                    </small>
                                @endif
                            </td>

                            <td data-label="Condition">
                                <x-status-badge :status="$item->condition_code" />
                            </td>

                            <td data-label="Action">
                                <a
                                    class="table-action ui-pressable"
                                    href="{{ route('inventory.show', $item) }}"
                                >
                                    <x-icon name="eye" size="16" />
                                    View Details
                                </a>
                            </td>
                        </tr>

                    @empty

                        <tr data-static-empty-row>
                            <td colspan="6" class="empty-state">
                                <strong>
                                    No items are currently available to display.
                                </strong>
                            </td>
                        </tr>

                    @endforelse
                </tbody>
            </table>
        </div>

        <div
            id="borrower-inventory-no-results"
            class="borrower-inventory-no-results"
            hidden
        >
            No available item matches the current search and filters.
        </div>

        <div
            class="borrower-inventory-pagination"
            aria-label="Available item pages"
        >
            <button
                id="borrower-inventory-previous"
                class="button secondary small ui-pressable"
                type="button"
            >
                &larr; Previous
            </button>

            <span
                id="borrower-inventory-page-label"
                class="borrower-inventory-page-label"
            >
                Page 1
            </span>

            <button
                id="borrower-inventory-next"
                class="button secondary small ui-pressable"
                type="button"
            >
                Next &rarr;
            </button>
        </div>

        <script>
        (() => {
            const initializeBorrowerInventoryBrowser = () => {
                const pageSize = 10;

                const search = document.getElementById(
                    'borrower-inventory-search'
                );

                const category = document.getElementById(
                    'borrower-inventory-category'
                );

                const use = document.getElementById(
                    'borrower-inventory-use'
                );

                const rows = Array.from(
                    document.querySelectorAll(
                        '[data-borrower-inventory-row]'
                    )
                );

                const staticEmptyRow = document.querySelector(
                    '[data-static-empty-row]'
                );

                const previous = document.getElementById(
                    'borrower-inventory-previous'
                );

                const next = document.getElementById(
                    'borrower-inventory-next'
                );

                const pageLabel = document.getElementById(
                    'borrower-inventory-page-label'
                );

                const resultLabel = document.getElementById(
                    'borrower-inventory-result-label'
                );

                const noResults = document.getElementById(
                    'borrower-inventory-no-results'
                );

                const pagination = document.querySelector(
                    '.borrower-inventory-pagination'
                );

                let currentPage = 1;

                const filteredRows = () => {
                    const query = (search?.value || '')
                        .trim()
                        .toLowerCase();

                    const selectedCategory = (category?.value || '')
                        .trim()
                        .toLowerCase();

                    const selectedUse = (use?.value || '')
                        .trim()
                        .toLowerCase();

                    return rows.filter((row) => {
                        const matchesSearch =
                            !query
                            || (row.dataset.search || '').includes(query);

                        const matchesCategory =
                            !selectedCategory
                            || (row.dataset.category || '') === selectedCategory;

                        const matchesUse =
                            !selectedUse
                            || (row.dataset.use || '') === selectedUse;

                        return (
                            matchesSearch
                            && matchesCategory
                            && matchesUse
                        );
                    });
                };

                const render = () => {
                    const matches = filteredRows();
                    const totalPages = Math.max(
                        1,
                        Math.ceil(matches.length / pageSize)
                    );

                    if (currentPage > totalPages) {
                        currentPage = totalPages;
                    }

                    if (currentPage < 1) {
                        currentPage = 1;
                    }

                    rows.forEach((row) => {
                        row.hidden = true;
                    });

                    staticEmptyRow?.removeAttribute('hidden');

                    if (rows.length > 0 && staticEmptyRow) {
                        staticEmptyRow.hidden = true;
                    }

                    const start =
                        (currentPage - 1) * pageSize;

                    const end = Math.min(
                        start + pageSize,
                        matches.length
                    );

                    matches
                        .slice(start, end)
                        .forEach((row) => {
                            row.hidden = false;
                        });

                    if (noResults) {
                        noResults.hidden =
                            rows.length === 0
                            || matches.length !== 0;
                    }

                    if (pagination) {
                        pagination.hidden =
                            rows.length === 0;
                    }

                    if (pageLabel) {
                        pageLabel.textContent =
                            matches.length
                                ? `Page ${currentPage} of ${totalPages}`
                                : 'No results';
                    }

                    if (resultLabel) {
                        if (rows.length === 0) {
                            resultLabel.textContent =
                                'No available items';
                        } else if (matches.length === 0) {
                            resultLabel.textContent =
                                'No matching available items';
                        } else {
                            resultLabel.textContent =
                                `Showing ${start + 1}-${end} of ${matches.length} available items`;
                        }
                    }

                    if (previous) {
                        previous.disabled =
                            matches.length === 0
                            || currentPage <= 1;
                    }

                    if (next) {
                        next.disabled =
                            matches.length === 0
                            || currentPage >= totalPages;
                    }
                };

                search?.addEventListener(
                    'input',
                    () => {
                        currentPage = 1;
                        render();
                    }
                );

                category?.addEventListener(
                    'change',
                    () => {
                        currentPage = 1;
                        render();
                    }
                );

                use?.addEventListener(
                    'change',
                    () => {
                        currentPage = 1;
                        render();
                    }
                );

                previous?.addEventListener(
                    'click',
                    () => {
                        currentPage = Math.max(
                            1,
                            currentPage - 1
                        );

                        render();

                        document
                            .querySelector('.borrower-inventory-browser-toolbar')
                            ?.scrollIntoView({
                                behavior: 'smooth',
                                block: 'start'
                            });
                    }
                );

                next?.addEventListener(
                    'click',
                    () => {
                        currentPage += 1;
                        render();

                        document
                            .querySelector('.borrower-inventory-browser-toolbar')
                            ?.scrollIntoView({
                                behavior: 'smooth',
                                block: 'start'
                            });
                    }
                );

                render();
            };

            if (document.readyState === 'loading') {
                document.addEventListener(
                    'DOMContentLoaded',
                    initializeBorrowerInventoryBrowser,
                    { once: true }
                );
            } else {
                initializeBorrowerInventoryBrowser();
            }
        })();
        </script>

    </section>


@else

    @php
        $spmuCategories = $items
            ->map(fn ($item) => $item->category?->category_name)
            ->filter()
            ->unique()
            ->sort()
            ->values();
    @endphp

    <section class="content-area spmu-inventory-browser">

        <style>
            .spmu-inventory-browser {
                --spmu-inventory-line: var(--border, #d7e0ea);
                --spmu-inventory-soft: var(--surface-subtle, #f7f9fc);
                --spmu-inventory-muted: var(--text-muted, #64748b);
                --spmu-inventory-ink: var(--text, #18324a);
            }

            .spmu-inventory-browser-toolbar {
                display: grid;
                grid-template-columns: minmax(320px, 1fr) minmax(220px, .34fr);
                gap: 12px;
                align-items: end;
                margin: 16px 0 12px;
                padding: 14px;
                border: 1px solid var(--spmu-inventory-line);
                border-radius: 12px;
                background: var(--surface, #fff);
            }

            .spmu-inventory-browser-toolbar label {
                min-width: 0;
                margin: 0;
            }

            .spmu-inventory-browser-toolbar input,
            .spmu-inventory-browser-toolbar select {
                width: 100%;
                margin-top: 7px;
            }

            .spmu-inventory-browser-summary {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                flex-wrap: wrap;
                margin: 0 0 10px;
                color: var(--spmu-inventory-muted);
                font-size: 12px;
            }

            .spmu-inventory-browser-summary strong {
                color: var(--spmu-inventory-ink);
            }

            .spmu-inventory-page-size {
                display: inline-flex;
                align-items: center;
                min-height: 28px;
                padding: 5px 9px;
                border: 1px solid var(--spmu-inventory-line);
                border-radius: 999px;
                background: var(--spmu-inventory-soft);
                color: var(--spmu-inventory-muted);
                font-size: 11px;
                font-weight: 700;
            }

            .spmu-inventory-browser tbody tr[hidden] {
                display: none !important;
            }

            .spmu-inventory-pagination {
                display: grid;
                grid-template-columns: auto minmax(130px, 1fr) auto;
                align-items: center;
                gap: 10px;
                margin-top: 12px;
                padding: 11px 12px;
                border: 1px solid var(--spmu-inventory-line);
                border-radius: 12px;
                background: var(--surface, #fff);
            }

            .spmu-inventory-page-label {
                color: var(--spmu-inventory-muted);
                font-size: 12px;
                font-weight: 700;
                text-align: center;
            }

            .spmu-inventory-no-results {
                margin-top: 10px;
                padding: 24px 16px;
                border: 1px dashed var(--spmu-inventory-line);
                border-radius: 12px;
                background: var(--spmu-inventory-soft);
                color: var(--spmu-inventory-muted);
                text-align: center;
                font-size: 13px;
            }

            .spmu-inventory-no-results[hidden] {
                display: none !important;
            }

            .physical-available-heading {
                white-space: nowrap;
            }

            @media (max-width: 760px) {
                .spmu-inventory-browser-toolbar {
                    grid-template-columns: 1fr;
                }

                .spmu-inventory-pagination {
                    grid-template-columns: 1fr 1fr;
                }

                .spmu-inventory-page-label {
                    grid-column: 1 / -1;
                    grid-row: 1;
                }

                .spmu-inventory-pagination .button {
                    width: 100%;
                    justify-content: center;
                }
            }
        </style>

        <div class="availability-window" role="note">
            <strong>Operational inventory</strong>

            <span>
                Physical Available = physically serviceable stock currently ready in SPMU custody.
                Allocated = reserved after Head approval.
                On Custody = physically issued.
                Laundry/incident quantities remain unavailable until final reconciliation.
            </span>
        </div>

        <div
            class="spmu-inventory-browser-toolbar"
            aria-label="Search and filter operational inventory"
        >
            <label>
                Search inventory
                <input
                    id="spmu-inventory-search"
                    type="search"
                    placeholder="Search item, category, description, or unit..."
                    autocomplete="off"
                >
            </label>

            <label>
                Category
                <select id="spmu-inventory-category">
                    <option value="">All Categories</option>

                    @foreach($spmuCategories as $categoryName)
                        <option value="{{ strtolower($categoryName) }}">
                            {{ $categoryName }}
                        </option>
                    @endforeach
                </select>
            </label>
        </div>

        <div class="spmu-inventory-browser-summary">
            <span id="spmu-inventory-result-label">
                Showing operational inventory
            </span>

            <span class="spmu-inventory-page-size">
                15 items per page
            </span>
        </div>

        <div class="table-wrap operational-table inventory-operations-table">
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="physical-available-heading">Physical Available</th>
                        <th>Allocated</th>
                        <th>On Custody</th>
                        <th>Laundry / Incident</th>
                        <th>Condition</th>
                        <th>Use</th>
                        <th>
                            <span class="visually-hidden">Actions</span>
                        </th>
                    </tr>
                </thead>

                <tbody id="spmu-inventory-table-body">
                    @forelse($items as $item)

                        @php
                            $b = $balances[$item->id] ?? [];

                            $available = (float) (
                                $b['current_available']
                                ?? $b['borrower_available']
                                ?? $b['available']
                                ?? 0
                            );

                            $allocated = (float) (
                                $b['allocated']
                                ?? $b['reserved']
                                ?? 0
                            );

                            $borrowed = (float) ($b['borrowed'] ?? 0);
                            $laundry = (float) ($b['laundry'] ?? 0);
                            $incident = (float) ($b['incident'] ?? 0);

                            $categoryName =
                                $item->category?->category_name
                                ?: 'Uncategorized';

                            $unitName =
                                $item->unit?->unit_name
                                ?: '';

                            $searchText = strtolower(
                                $item->unique_description.' '.
                                ($item->specification ?? '').' '.
                                $categoryName.' '.
                                $unitName
                            );
                        @endphp

                        <tr
                            data-spmu-inventory-row
                            data-search="{{ $searchText }}"
                            data-category="{{ strtolower($categoryName) }}"
                        >
                            <td>
                                <strong>
                                    {{ $item->unique_description }}
                                </strong>

                                <small>
                                    {{ $categoryName }}
                                    ·
                                    {{ $unitName }}
                                </small>
                            </td>

                            <td>
                                <strong>{{ $available + 0 }}</strong>
                            </td>

                            <td>
                                <strong>{{ $allocated + 0 }}</strong>
                            </td>

                            <td>
                                <strong>{{ $borrowed + 0 }}</strong>
                            </td>

                            <td>
                                <strong>
                                    {{ $laundry + $incident }}
                                </strong>

                                <small>
                                    Laundry {{ $laundry + 0 }}
                                    ·
                                    Issue {{ $incident + 0 }}
                                </small>
                            </td>

                            <td>
                                <x-status-badge :status="$item->condition_code" />
                            </td>

                            <td>
                                {{ $item->off_campus_allowed
                                    ? 'Off-campus allowed'
                                    : 'On-campus only'
                                }}

                                @if($item->laundry_required)
                                    <small>
                                        Laundry required
                                    </small>
                                @endif
                            </td>

                            <td>
                                <div class="inventory-row-actions">
                                    <a
                                        class="table-action ui-pressable"
                                        href="{{ route('inventory.show', $item) }}"
                                    >
                                        <x-icon name="eye" size="16" />
                                        View
                                    </a>

                                    <a
                                        class="table-action ui-pressable"
                                        href="{{ route('inventory.edit', $item) }}"
                                    >
                                        <x-icon name="edit" size="16" />
                                        Edit
                                    </a>
                                </div>
                            </td>
                        </tr>

                    @empty

                        <tr data-spmu-static-empty-row>
                            <td colspan="8" class="empty-state">
                                No inventory items found.
                            </td>
                        </tr>

                    @endforelse
                </tbody>
            </table>
        </div>

        <div
            id="spmu-inventory-no-results"
            class="spmu-inventory-no-results"
            hidden
        >
            No inventory item matches the current search and category filter.
        </div>

        <div
            class="spmu-inventory-pagination"
            aria-label="Operational inventory pages"
        >
            <button
                id="spmu-inventory-previous"
                class="button secondary small ui-pressable"
                type="button"
            >
                &larr; Previous
            </button>

            <span
                id="spmu-inventory-page-label"
                class="spmu-inventory-page-label"
            >
                Page 1
            </span>

            <button
                id="spmu-inventory-next"
                class="button secondary small ui-pressable"
                type="button"
            >
                Next &rarr;
            </button>
        </div>

        <script>
        (() => {
            const initializeSpmuInventoryBrowser = () => {
                const pageSize = 15;

                const search = document.getElementById(
                    'spmu-inventory-search'
                );

                const category = document.getElementById(
                    'spmu-inventory-category'
                );

                const rows = Array.from(
                    document.querySelectorAll(
                        '[data-spmu-inventory-row]'
                    )
                );

                const staticEmptyRow = document.querySelector(
                    '[data-spmu-static-empty-row]'
                );

                const previous = document.getElementById(
                    'spmu-inventory-previous'
                );

                const next = document.getElementById(
                    'spmu-inventory-next'
                );

                const pageLabel = document.getElementById(
                    'spmu-inventory-page-label'
                );

                const resultLabel = document.getElementById(
                    'spmu-inventory-result-label'
                );

                const noResults = document.getElementById(
                    'spmu-inventory-no-results'
                );

                const pagination = document.querySelector(
                    '.spmu-inventory-pagination'
                );

                let currentPage = 1;

                const filteredRows = () => {
                    const query = (search?.value || '')
                        .trim()
                        .toLowerCase();

                    const selectedCategory = (category?.value || '')
                        .trim()
                        .toLowerCase();

                    return rows.filter((row) => {
                        const matchesSearch =
                            !query
                            || (row.dataset.search || '').includes(query);

                        const matchesCategory =
                            !selectedCategory
                            || (row.dataset.category || '') === selectedCategory;

                        return matchesSearch && matchesCategory;
                    });
                };

                const render = () => {
                    const matches = filteredRows();

                    const totalPages = Math.max(
                        1,
                        Math.ceil(matches.length / pageSize)
                    );

                    if (currentPage > totalPages) {
                        currentPage = totalPages;
                    }

                    if (currentPage < 1) {
                        currentPage = 1;
                    }

                    rows.forEach((row) => {
                        row.hidden = true;
                    });

                    if (staticEmptyRow) {
                        staticEmptyRow.hidden = rows.length > 0;
                    }

                    const start =
                        (currentPage - 1) * pageSize;

                    const end = Math.min(
                        start + pageSize,
                        matches.length
                    );

                    matches
                        .slice(start, end)
                        .forEach((row) => {
                            row.hidden = false;
                        });

                    if (noResults) {
                        noResults.hidden =
                            rows.length === 0
                            || matches.length !== 0;
                    }

                    if (pagination) {
                        pagination.hidden =
                            rows.length === 0;
                    }

                    if (pageLabel) {
                        pageLabel.textContent =
                            matches.length
                                ? `Page ${currentPage} of ${totalPages}`
                                : 'No results';
                    }

                    if (resultLabel) {
                        if (rows.length === 0) {
                            resultLabel.textContent =
                                'No inventory items';
                        } else if (matches.length === 0) {
                            resultLabel.textContent =
                                'No matching inventory items';
                        } else {
                            resultLabel.textContent =
                                `Showing ${start + 1}-${end} of ${matches.length} inventory items`;
                        }
                    }

                    if (previous) {
                        previous.disabled =
                            matches.length === 0
                            || currentPage <= 1;
                    }

                    if (next) {
                        next.disabled =
                            matches.length === 0
                            || currentPage >= totalPages;
                    }
                };

                search?.addEventListener(
                    'input',
                    () => {
                        currentPage = 1;
                        render();
                    }
                );

                category?.addEventListener(
                    'change',
                    () => {
                        currentPage = 1;
                        render();
                    }
                );

                previous?.addEventListener(
                    'click',
                    () => {
                        currentPage = Math.max(
                            1,
                            currentPage - 1
                        );

                        render();

                        document
                            .querySelector('.spmu-inventory-browser-toolbar')
                            ?.scrollIntoView({
                                behavior: 'smooth',
                                block: 'start'
                            });
                    }
                );

                next?.addEventListener(
                    'click',
                    () => {
                        currentPage += 1;
                        render();

                        document
                            .querySelector('.spmu-inventory-browser-toolbar')
                            ?.scrollIntoView({
                                behavior: 'smooth',
                                block: 'start'
                            });
                    }
                );

                render();
            };

            if (document.readyState === 'loading') {
                document.addEventListener(
                    'DOMContentLoaded',
                    initializeSpmuInventoryBrowser,
                    { once: true }
                );
            } else {
                initializeSpmuInventoryBrowser();
            }
        })();
        </script>

    </section>

@endif

@endsection