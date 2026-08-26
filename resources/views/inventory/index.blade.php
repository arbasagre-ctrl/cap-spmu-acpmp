@extends('layouts.app', [
    'title' => session('active_workspace') === 'BORROWER'
        ? 'Available Items'
        : 'Inventory'
])

@section('content')

@php
    $isBorrower = session('active_workspace') === 'BORROWER';
    $isSpmu = session('active_workspace') === 'SPMU';
    $isInventoryAdmin = auth()->user()?->access_classification?->value === 'SPMU_HEAD';
    $isActionOfficer = auth()->user()?->access_classification?->value === 'SPMU_OFFICER';
@endphp

<section class="page-heading">
    <div>
        <p class="eyebrow">
            {{ $isBorrower ? 'Borrowing availability' : 'Inventory monitoring' }}
        </p>

        <h1>{{ $isBorrower ? 'Available Items' : 'Inventory' }}</h1>

        <p>
            {{ $isBorrower
                ? 'Browse active, borrowable, serviceable items currently available for borrowing. Displayed stock does not reserve an item or guarantee approval.'
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
    @elseif($isInventoryAdmin)
        <a
            class="button primary ui-pressable"
            href="{{ route('inventory.create') }}"
        >
            <x-icon name="plus" />
            Add Inventory Item
        </a>
    @endif
</section>

@unless($isBorrower)
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
@endunless


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
                grid-template-columns: minmax(280px, 1fr) minmax(190px, 240px);
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

            .borrower-inventory-helper {
                display: inline-flex;
                align-items: flex-start;
                gap: 6px;
                margin: 0 0 10px;
                padding: 2px 0;
                color: var(--inventory-filter-muted);
                font-size: 12px;
                line-height: 1.45;
            }

            .borrower-inventory-helper-icon {
                flex: 0 0 auto;
                color: var(--primary, #175da8);
                font-size: 12px;
                line-height: 1.45;
                opacity: .7;
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

            .borrower-inventory-table table {
                width: 100%;
                table-layout: fixed;
            }

            .borrower-inventory-table th,
            .borrower-inventory-table td {
                padding: 8px 9px;
                vertical-align: middle;
            }

            .borrower-inventory-table th {
                white-space: nowrap;
                font-size: 11px;
            }

            .borrower-inventory-table td {
                font-size: 12px;
                line-height: 1.35;
            }

            .borrower-inventory-table .col-number {
                width: 6%;
                text-align: center;
            }

            .borrower-inventory-table .col-id {
                width: 11%;
            }

            .borrower-inventory-table .col-description {
                width: 31%;
            }

            .borrower-inventory-table .col-category {
                width: 15%;
            }

            .borrower-inventory-table .col-unit,
            .borrower-inventory-table .col-quantity {
                width: 10%;
            }

            .borrower-inventory-table .col-quantity {
                text-align: center;
            }

            .borrower-inventory-table .col-premises {
                width: 17%;
            }

            .borrower-item-id,
            .borrower-premises {
                display: inline-flex;
                align-items: center;
                min-height: 24px;
                padding: 3px 7px;
                border: 1px solid var(--inventory-filter-line);
                background: var(--inventory-filter-soft);
                color: var(--inventory-filter-ink);
                font-size: 11px;
                font-weight: 700;
                white-space: nowrap;
            }

            .borrower-item-id {
                border-radius: 6px;
                font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            }

            .borrower-premises {
                border-radius: 999px;
                font-size: 10.5px;
            }

            .borrower-item-title {
                display: block;
                margin-bottom: 2px;
                color: var(--inventory-filter-ink);
                font-weight: 700;
            }

            .borrower-description {
                color: var(--inventory-filter-muted);
                font-size: 11.5px;
            }

            .borrower-description-more {
                padding: 0;
                border: 0;
                background: transparent;
                color: var(--primary, #175da8);
                font: inherit;
                font-weight: 700;
                cursor: pointer;
            }

            .borrower-description-more:hover {
                text-decoration: underline;
            }

            .borrower-quantity {
                font-size: 13px;
                font-variant-numeric: tabular-nums;
                font-weight: 800;
            }

            .borrower-inventory-pagination {
                display: grid;
                grid-template-columns: auto minmax(130px, 1fr) auto;
                align-items: center;
                gap: 10px;
                margin: 0 0 10px;
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

                .borrower-inventory-table table {
                    min-width: 850px;
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

        <div
            class="borrower-inventory-browser-toolbar"
            aria-label="Search and filter available items"
        >
            <label class="borrower-inventory-search-field">
                Search
                <input
                    id="borrower-inventory-search"
                    type="search"
                    placeholder="Search Item ID, article, description, category, or unit..."
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

        </div>

        <p class="borrower-inventory-helper" role="note">
            <span class="borrower-inventory-helper-icon" aria-hidden="true">ⓘ</span>
            <span>
                Only active, borrowable, serviceable items are shown.
                Availability does not guarantee approval or reserve stock.
            </span>
        </p>

        <div class="borrower-inventory-browser-summary">
            <span id="borrower-inventory-result-label">
                Showing available inventory
            </span>

            <span class="borrower-inventory-page-size">
                7 items per page
            </span>
        </div>

        <div
            class="borrower-inventory-pagination"
            aria-label="Available inventory pages"
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

        <div class="table-wrap borrower-inventory-table">
            <table>
                <thead>
                    <tr>
                        <th class="col-number">No.</th>
                        <th class="col-id">Item ID</th>
                        <th class="col-description">Article / Description</th>
                        <th class="col-category">Category</th>
                        <th class="col-unit">Unit</th>
                        <th class="col-quantity">Quantity</th>
                        <th class="col-premises">Premises</th>
                    </tr>
                </thead>

                <tbody id="borrower-inventory-table-body">
                    @forelse($items as $item)

                        @php
                            $balance = $balances[$item->id] ?? [];

                            $available = max(
                                0,
                                (int) floor((float) (
                                    $balance['borrower_available']
                                    ?? $balance['available']
                                    ?? 0
                                ))
                            );

                            $categoryName = $item->category?->category_name ?: 'Uncategorized';
                            $unitName = $item->unit?->unit_name ?: '—';
                            $itemUiId = 'INV-'.str_pad((string) $item->id, 4, '0', STR_PAD_LEFT);
                            $description = trim((string) ($item->specification ?? ''));
                            $hasLongDescription = mb_strlen($description) > 120;
                            $descriptionPreview = $hasLongDescription
                                ? \Illuminate\Support\Str::limit($description, 120)
                                : $description;

                            $searchText = strtolower(
                                $itemUiId.' '.
                                $item->unique_description.' '.
                                $description.' '.
                                $categoryName.' '.
                                $unitName
                            );
                        @endphp

                        <tr
                            data-borrower-inventory-row
                            data-search="{{ $searchText }}"
                            data-category="{{ strtolower($categoryName) }}"
                        >
                            <td class="col-number" data-label="No." data-row-number></td>

                            <td class="col-id" data-label="Item ID">
                                <span class="borrower-item-id">{{ $itemUiId }}</span>
                            </td>

                            <td class="col-description" data-label="Article / Description">
                                <span class="borrower-item-title">
                                    {{ $item->unique_description }}
                                </span>

                                <span class="borrower-description" data-description>
                                    @if($description !== '')
                                        <span data-description-preview>{{ $descriptionPreview }}</span>

                                        @if($hasLongDescription)
                                            <span data-description-full hidden>{{ $description }}</span>

                                            <button
                                                class="borrower-description-more"
                                                type="button"
                                                data-description-toggle
                                                aria-expanded="false"
                                            >
                                                More
                                            </button>
                                        @endif
                                    @else
                                        No additional description.
                                    @endif
                                </span>
                            </td>

                            <td class="col-category" data-label="Category">
                                {{ $categoryName }}
                            </td>

                            <td class="col-unit" data-label="Unit">
                                {{ $unitName }}
                            </td>

                            <td class="col-quantity" data-label="Quantity">
                                <span class="borrower-quantity">{{ $available }}</span>
                            </td>

                            <td class="col-premises" data-label="Premises">
                                <span class="borrower-premises">
                                    {{ $item->off_campus_allowed
                                        ? 'Off-campus eligible'
                                        : 'On-campus only' }}
                                </span>
                            </td>
                        </tr>

                    @empty

                        <tr data-static-empty-row>
                            <td colspan="7" class="empty-state">
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

        <script>
        (() => {
            const initializeBorrowerInventoryBrowser = () => {
                const pageSize = 7;

                const search = document.getElementById(
                    'borrower-inventory-search'
                );

                const category = document.getElementById(
                    'borrower-inventory-category'
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
                        .forEach((row, index) => {
                            row.hidden = false;

                            const number = row.querySelector(
                                '[data-row-number]'
                            );

                            if (number) {
                                number.textContent = String(start + index + 1);
                            }
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

                document
                    .querySelectorAll('[data-description-toggle]')
                    .forEach((button) => {
                        button.addEventListener('click', () => {
                            const holder = button.closest('[data-description]');
                            const preview = holder?.querySelector(
                                '[data-description-preview]'
                            );
                            const full = holder?.querySelector(
                                '[data-description-full]'
                            );

                            if (!preview || !full) {
                                return;
                            }

                            const expanded =
                                button.getAttribute('aria-expanded') === 'true';

                            preview.hidden = !expanded;
                            full.hidden = expanded;
                            button.setAttribute(
                                'aria-expanded',
                                String(!expanded)
                            );
                            button.textContent = expanded ? 'More' : 'Less';
                        });
                    });

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
                margin: 0 0 12px;
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

            .inventory-operations-table table {
                min-width: 1320px;
            }

            .inventory-item-id-heading,
            .inventory-total-stock-heading {
                white-space: nowrap;
            }

            .inventory-item-id {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-height: 28px;
                padding: 4px 9px;
                border: 1px solid var(--spmu-inventory-line);
                border-radius: 8px;
                background: var(--spmu-inventory-soft);
                color: var(--spmu-inventory-ink);
                font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
                font-size: 11px;
                font-weight: 800;
                letter-spacing: .01em;
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

        <div
            class="spmu-inventory-browser-toolbar"
            aria-label="Search and filter operational inventory"
        >
            <label>
                Search inventory
                <input
                    id="spmu-inventory-search"
                    type="search"
                    placeholder="Search Item ID, item, category, description, or unit..."
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
                Showing inventory items
            </span>

            <span class="spmu-inventory-page-size">
                15 items per page
            </span>
        </div>

        <div
            class="spmu-inventory-pagination"
            aria-label="Inventory pages"
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

        <div class="table-wrap operational-table inventory-operations-table">
            <table>
                <thead>
                    <tr>
                        <th class="inventory-item-id-heading">Item ID</th>
                        <th>Item</th>
                        <th class="inventory-total-stock-heading">Total Stock</th>
                        <th class="physical-available-heading">Available Stock</th>
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
                            $totalStock = (float) $item->total_quantity;
                            $itemCode = 'INV-'.str_pad(
                                (string) $item->id,
                                4,
                                '0',
                                STR_PAD_LEFT
                            );

                            $categoryName =
                                $item->category?->category_name
                                ?: 'Uncategorized';

                            $unitName =
                                $item->unit?->unit_name
                                ?: '';

                            $searchText = strtolower(
                                $itemCode.' '.
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
                                <span class="inventory-item-id">
                                    {{ $itemCode }}
                                </span>
                            </td>

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
                                <strong>{{ $totalStock + 0 }}</strong>
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
                                        View Details
                                    </a>

                                    @if($isInventoryAdmin)
                                        <a
                                            class="table-action ui-pressable"
                                            href="{{ route('inventory.edit', $item) }}"
                                        >
                                            <x-icon name="edit" size="16" />
                                            Edit
                                        </a>
                                    @endif
                                </div>
                            </td>
                        </tr>

                    @empty

                        <tr data-spmu-static-empty-row>
                            <td colspan="10" class="empty-state">
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

@unless($isBorrower)
<style>
.inventory-operations-table th,.inventory-operations-table td{padding-top:8px!important;padding-bottom:8px!important;vertical-align:middle}
.inventory-operations-table td small{margin-top:2px;line-height:1.25}
.inventory-row-actions{display:flex;align-items:center;gap:12px;flex-wrap:nowrap;white-space:nowrap}
</style>
@endunless
@endsection
