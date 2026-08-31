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

<section class="page-heading heading-action-lowered">
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
    @include('inventory.partials.operations-styles')
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

    @include('inventory.partials.borrower-styles')

    <div class="content-area borrower-inventory" data-borrower-inventory>
        <div
            class="borrower-inventory-card borrower-inventory-filters"
            aria-label="Search and filter available items"
        >
            <label>
                Search
                <span class="search-input-shell">
                    <span class="search-input-icon" aria-hidden="true"><x-icon name="search" size="17" /></span>
                    <input
                        id="borrower-inventory-search"
                        type="search"
                        placeholder="Search Item ID, article, description, category, or unit..."
                        autocomplete="off"
                    >
                </span>
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
            <x-icon name="information" size="16" />
            <span>
                Only active, borrowable, serviceable items are shown.
                Availability does not guarantee approval or reserve stock.
            </span>
        </p>

        <div class="borrower-inventory-summary">
            <span data-borrower-inventory-summary role="status" aria-live="polite">
                Showing {{ $items->count() }} available items
            </span>

            <span class="borrower-inventory-page-size">
                <label class="visually-hidden" for="borrower-inventory-page-size-top">Available items per page</label>
                <select id="borrower-inventory-page-size-top" data-borrower-inventory-page-size>
                    <option value="7">7 items per page</option>
                    <option value="15">15 items per page</option>
                    <option value="30">30 items per page</option>
                    <option value="50">50 items per page</option>
                </select>
            </span>
        </div>

        <div class="borrower-inventory-table-wrap">
            <div class="borrower-inventory-table-scroll">
                <table class="borrower-inventory-table">
                    <thead>
                        <tr>
                            <th scope="col" class="col-number">No.</th>
                            <th scope="col" class="col-id">Item ID</th>
                            <th scope="col" class="col-description">Article / Description</th>
                            <th scope="col" class="col-category">Category</th>
                            <th scope="col" class="col-unit">Unit</th>
                            <th scope="col" class="col-quantity is-numeric">Quantity</th>
                            <th scope="col" class="col-premises">Premises</th>
                        </tr>
                    </thead>

                    <tbody id="borrower-inventory-table-body">
                        @forelse($items as $item)
                            @include('inventory.partials.borrower-row')
                        @empty
                            <tr data-static-empty-row>
                                <td colspan="7" class="borrower-inventory-static-empty">
                                    No items are currently available to display.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div id="borrower-inventory-no-results" class="borrower-inventory-no-results" hidden>
                <x-icon name="search" size="26" />
                <strong>No matching available item.</strong>
                <p>No available item matches the current search and category filter.</p>
            </div>

            <div class="borrower-inventory-footer" id="borrower-inventory-footer">
                <p data-borrower-inventory-summary>
                    Showing {{ $items->count() }} available items
                </p>

                <div class="borrower-inventory-pagination-group">
                    <span class="borrower-inventory-page-size">
                        <label class="visually-hidden" for="borrower-inventory-page-size-bottom">Available items per page</label>
                        <select id="borrower-inventory-page-size-bottom" data-borrower-inventory-page-size>
                            <option value="7">7 items per page</option>
                            <option value="15">15 items per page</option>
                            <option value="30">30 items per page</option>
                            <option value="50">50 items per page</option>
                        </select>
                    </span>

                    <div
                        class="borrower-inventory-pagination"
                        id="borrower-inventory-pagination"
                        role="group"
                        aria-label="Available inventory pages"
                    ></div>
                </div>
            </div>
        </div>
    </div>

    @include('inventory.partials.borrower-interactions')

@else

    @php
        $spmuCategories = $items
            ->map(fn ($item) => $item->category?->category_name)
            ->filter()
            ->unique()
            ->sort()
            ->values();
    @endphp

    <div class="content-area spmu-inventory {{ $isInventoryAdmin ? 'is-inventory-head' : '' }}" data-spmu-inventory data-pagination-mode="{{ $isInventoryAdmin ? 'simple' : 'numbered' }}">
        @include('inventory.partials.operations-availability')

        <div
            class="spmu-inventory-card spmu-inventory-filters"
            aria-label="Search and filter operational inventory"
        >
            <label>
                Search
                <span class="search-input-shell">
                    <span class="search-input-icon" aria-hidden="true"><x-icon name="search" size="17" /></span>
                    <input
                        id="spmu-inventory-search"
                        type="search"
                        placeholder="Search item ID, item, category, description, or unit..."
                        autocomplete="off"
                    >
                </span>
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

        <div class="spmu-inventory-summary">
            <span data-spmu-inventory-summary role="status" aria-live="polite">
                Showing {{ $items->count() }} inventory items
            </span>

            <span class="spmu-inventory-page-size">
                <label class="visually-hidden" for="spmu-inventory-page-size-top">Inventory items per page</label>
                <select id="spmu-inventory-page-size-top" data-spmu-inventory-page-size>
                    <option value="15">15 items per page</option>
                    <option value="30">30 items per page</option>
                    <option value="50">50 items per page</option>
                    <option value="100">100 items per page</option>
                </select>
            </span>
        </div>

        <div class="spmu-inventory-table-wrap">
            <div class="spmu-inventory-table-scroll">
                <table class="spmu-inventory-table">
                    <thead>
                        <tr>
                            <th scope="col" class="is-nowrap">Item ID</th>
                            <th scope="col">Item</th>
                            <th scope="col" class="is-numeric">Total Stock</th>
                            <th scope="col" class="is-numeric">Available Stock</th>
                            <th scope="col" class="is-numeric">Allocated</th>
                            <th scope="col" class="is-numeric">On Custody</th>
                            <th scope="col">Laundry / Incident</th>
                            <th scope="col">Condition</th>
                            <th scope="col">Use</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>

                    <tbody id="spmu-inventory-table-body">
                        @forelse($items as $item)
                            @include('inventory.partials.operations-row')
                        @empty
                            <tr data-spmu-static-empty-row>
                                <td colspan="10" class="spmu-inventory-static-empty">
                                    No inventory items found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div id="spmu-inventory-no-results" class="spmu-inventory-no-results" hidden>
                <x-icon name="search" size="26" />
                <strong>No matching inventory item.</strong>
                <p>No inventory item matches the current search and category filter.</p>
            </div>

            <div class="spmu-inventory-footer" id="spmu-inventory-footer">
                @unless($isInventoryAdmin)
                    <p data-spmu-inventory-summary>
                        Showing {{ $items->count() }} inventory items
                    </p>
                @endunless

                <div class="spmu-inventory-pagination-group">
                    @unless($isInventoryAdmin)
                        <span class="spmu-inventory-page-size">
                            <label class="visually-hidden" for="spmu-inventory-page-size-bottom">Inventory items per page</label>
                            <select id="spmu-inventory-page-size-bottom" data-spmu-inventory-page-size>
                                <option value="15">15 items per page</option>
                                <option value="30">30 items per page</option>
                                <option value="50">50 items per page</option>
                                <option value="100">100 items per page</option>
                            </select>
                        </span>
                    @endunless

                    <div
                        class="spmu-inventory-pagination"
                        id="spmu-inventory-pagination"
                        role="group"
                        aria-label="Inventory pages"
                    ></div>
                </div>
            </div>
        </div>
    </div>

    @include('inventory.partials.operations-interactions')

@endif

@endsection
