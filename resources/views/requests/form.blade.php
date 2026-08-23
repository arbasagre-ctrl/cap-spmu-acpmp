@extends('layouts.app', ['title' => $borrowingRequest->exists ? 'Edit Request' : 'Create Request'])

@section('content')

@php
    $editing = $borrowingRequest->exists;

    $selectedItems = $version->exists
        ? $version->items->keyBy('inventory_item_id')
        : collect();

    $oldItemIds = collect(old('item_ids', []))
        ->map(fn ($id) => (int) $id);

    $isReturned = $borrowingRequest->exists
        && $borrowingRequest->status === App\Enums\RequestStatus::ReturnedForRevision;

    $returnRemarks = $isReturned
        ? $version->approvalSteps
            ->where('decision', 'RETURNED')
            ->pluck('remarks')
            ->filter()
        : collect();

    $supporting = $version->exists
        ? $version->supportingDocuments->where('is_current', true)
        : collect();

    $requestLetter = $supporting->firstWhere(
        'document_type',
        App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER
    );

    $ptc = $supporting->firstWhere(
        'document_type',
        App\Models\RequestSupportingDocument::TYPE_PERMISSION_TO_CONDUCT
    );

    $categories = $items
        ->map(fn ($item) => $item->category?->category_name)
        ->filter()
        ->unique()
        ->sort()
        ->values();
@endphp

<style>
    .create-request-ui {
        --cr-navy: #102a43;
        --cr-blue: #1769e0;
        --cr-ink: #18324a;
        --cr-muted: #68798a;
        --cr-line: #d9e2ec;
        --cr-soft: #f6f8fb;
        --cr-soft-blue: #f4f8ff;
        --cr-gold: #c99a2e;
        --cr-danger: #a62f2f;
        --cr-success: #237a45;
        display: grid;
        gap: 16px;
        width: 100%;
    }

    .create-request-heading {
        align-items: flex-start;
        margin-bottom: 18px;
    }

    .create-request-heading h1 {
        margin-bottom: 6px;
    }

    .create-request-heading .heading-copy {
        max-width: 820px;
        color: var(--text-muted);
        line-height: 1.55;
    }

    .create-request-ui .request-card {
        overflow: hidden;
        border: 1px solid var(--cr-line);
        border-radius: 14px;
        background: var(--surface, #fff);
        box-shadow: 0 6px 18px rgba(16, 42, 67, .04);
    }

    .create-request-ui .request-card-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        padding: 17px 20px 14px;
        border-bottom: 1px solid var(--cr-line);
        background: linear-gradient(180deg, #fff 0%, #fbfcfe 100%);
    }

    .create-request-ui .request-card-header .eyebrow {
        margin-bottom: 4px;
    }

    .create-request-ui .request-card-header h2 {
        margin: 0;
        color: var(--cr-ink);
        font-size: 1rem;
    }

    .create-request-ui .request-card-header .meta {
        margin: 5px 0 0;
        color: var(--cr-muted);
        font-size: 12px;
        line-height: 1.45;
    }

    .create-request-ui .request-card-body {
        padding: 20px;
    }

    .create-request-ui .field-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 15px;
    }

    .create-request-ui .full-span {
        grid-column: 1 / -1;
    }

    .create-request-ui label > input,
    .create-request-ui label > select,
    .create-request-ui label > textarea {
        margin-top: 7px;
    }

    .create-request-ui textarea {
        min-height: 112px;
        resize: vertical;
    }

    .student-activity-panel {
        padding: 15px;
        border: 1px solid var(--cr-line);
        border-radius: 12px;
        background: var(--cr-soft);
    }

    .student-activity-panel .checkbox {
        margin: 0;
    }

    .student-fields {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
        margin-top: 15px;
        padding-top: 15px;
        border-top: 1px solid var(--cr-line);
    }

    .student-fields[hidden] {
        display: none !important;
    }

    .inventory-date-context {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        min-height: 30px;
        padding: 5px 10px;
        border: 1px solid #dce7f8;
        border-radius: 999px;
        background: var(--cr-soft-blue);
        color: #31547b;
        font-size: 11px;
        font-weight: 700;
        white-space: nowrap;
    }

    .item-picker-toolbar {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(190px, .34fr);
        gap: 12px;
        margin-bottom: 16px;
    }

    .item-picker-toolbar label {
        min-width: 0;
    }

    .catalog-shell {
        overflow: hidden;
        border: 1px solid var(--cr-line);
        border-radius: 12px;
        background: #fff;
    }

    .catalog-summary {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 10px 14px;
        border-bottom: 1px solid var(--cr-line);
        background: #fbfcfe;
        color: var(--cr-muted);
        font-size: 12px;
    }

    .catalog-list {
        display: grid;
    }

    .catalog-item {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        gap: 16px;
        min-height: 76px;
        padding: 13px 14px;
        border-bottom: 1px solid var(--cr-line);
    }

    .catalog-item:last-child {
        border-bottom: 0;
    }

    .catalog-item[hidden] {
        display: none !important;
    }

    .catalog-item-main {
        min-width: 0;
    }

    .catalog-item-title {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    .catalog-item-title strong {
        color: var(--cr-ink);
        font-size: 14px;
    }

    .catalog-item-meta {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
        margin-top: 4px;
        color: var(--cr-muted);
        font-size: 11px;
    }

    .catalog-item-description {
        margin: 5px 0 0;
        color: var(--cr-muted);
        font-size: 12px;
        line-height: 1.4;
        overflow-wrap: anywhere;
    }

    .catalog-item-side {
        display: grid;
        justify-items: end;
        gap: 7px;
        min-width: 138px;
    }

    .catalog-availability {
        color: var(--cr-muted);
        font-size: 11px;
        font-weight: 700;
        text-align: right;
    }

    .catalog-availability.is-available {
        color: var(--cr-success);
    }

    .catalog-availability.is-unavailable {
        color: var(--cr-danger);
    }

    .catalog-add-button {
        min-width: 92px;
        justify-content: center;
    }

    .catalog-add-button.is-added {
        border-color: #cfe0d6;
        background: #f0f8f3;
        color: var(--cr-success);
    }

    .catalog-pagination {
        display: grid;
        grid-template-columns: auto minmax(120px, 1fr) auto;
        align-items: center;
        gap: 10px;
        padding: 11px 14px;
        border-top: 1px solid var(--cr-line);
        background: #fbfcfe;
    }

    .catalog-page-label {
        color: var(--cr-muted);
        font-size: 12px;
        font-weight: 700;
        text-align: center;
    }

    .catalog-empty {
        padding: 28px 18px;
        text-align: center;
        color: var(--cr-muted);
    }

    .selected-items-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-top: 20px;
        margin-bottom: 10px;
    }

    .selected-items-header h3 {
        margin: 0;
        color: var(--cr-ink);
        font-size: 14px;
    }

    .selected-count {
        display: inline-flex;
        align-items: center;
        min-height: 29px;
        padding: 5px 9px;
        border: 1px solid var(--cr-line);
        border-radius: 999px;
        color: var(--cr-muted);
        font-size: 11px;
        font-weight: 800;
    }

    .selected-items-table table {
        min-width: 860px;
    }

    .selected-items-table thead th {
        background: var(--cr-navy);
        color: #fff;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .02em;
        text-transform: uppercase;
    }

    .selected-item-row[hidden] {
        display: none !important;
    }

    .selected-item-name {
        display: grid;
        gap: 3px;
    }

    .selected-item-name strong {
        color: var(--cr-ink);
        font-size: 13px;
    }

    .selected-item-name small {
        color: var(--cr-muted);
        font-size: 11px;
    }

    .selected-availability {
        display: grid;
        gap: 2px;
    }

    .selected-availability strong {
        color: var(--cr-ink);
    }

    .selected-availability small {
        color: var(--cr-muted);
        font-size: 11px;
    }

    .selected-availability small.is-error {
        color: var(--cr-danger);
        font-weight: 700;
    }

    .selected-quantity {
        min-width: 88px;
        max-width: 108px;
    }

    .selected-location {
        min-width: 150px;
    }

    .selected-empty {
        padding: 22px 18px;
        border: 1px dashed #cdd7e2;
        border-radius: 12px;
        background: #fbfcfe;
        color: var(--cr-muted);
        text-align: center;
        font-size: 12px;
    }

    .selected-empty[hidden] {
        display: none !important;
    }

    .picker-warning {
        margin-top: 12px;
    }

    .documents-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 15px;
    }

    .document-box {
        padding: 15px;
        border: 1px solid var(--cr-line);
        border-radius: 12px;
        background: var(--cr-soft);
    }

    .document-current {
        margin: 0 0 10px;
        color: var(--cr-muted);
        font-size: 12px;
    }

    .sticky-actions {
        position: sticky;
        bottom: 12px;
        z-index: 10;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        padding: 12px;
        border: 1px solid var(--cr-line);
        border-radius: 14px;
        background: rgba(255, 255, 255, .97);
        box-shadow: 0 10px 28px rgba(16, 42, 67, .10);
        backdrop-filter: blur(8px);
    }

    .sticky-actions .meta {
        margin: 0;
        color: var(--cr-muted);
        font-size: 11px;
        line-height: 1.4;
    }

    .sticky-actions .actions {
        display: flex;
        gap: 9px;
        flex: 0 0 auto;
    }

    #request-submit-button:disabled {
        cursor: not-allowed;
        opacity: .55;
    }

    @media (max-width: 800px) {
        .item-picker-toolbar,
        .documents-grid {
            grid-template-columns: 1fr;
        }

        .catalog-item {
            align-items: start;
        }
    }

    @media (max-width: 700px) {
        .create-request-ui .field-grid,
        .student-fields {
            grid-template-columns: 1fr;
        }

        .create-request-ui .full-span {
            grid-column: auto;
        }

        .create-request-ui .request-card-header,
        .create-request-ui .request-card-body {
            padding-left: 15px;
            padding-right: 15px;
        }

        .catalog-item {
            grid-template-columns: 1fr;
        }

        .catalog-item-side {
            grid-template-columns: 1fr auto;
            align-items: center;
            justify-items: stretch;
            min-width: 0;
        }

        .catalog-availability {
            text-align: left;
        }

        .catalog-pagination {
            grid-template-columns: 1fr 1fr;
        }

        .catalog-page-label {
            grid-column: 1 / -1;
            grid-row: 1;
        }

        .sticky-actions {
            position: static;
            align-items: stretch;
            flex-direction: column;
        }

        .sticky-actions .actions {
            flex-direction: column;
        }

        .sticky-actions .button {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<section class="page-heading create-request-heading">
    <div>
        <p class="eyebrow">Borrowing request</p>

        <h1>
            {{ $isReturned
                ? 'Revise request'
                : ($editing ? 'Edit request' : 'Create request')
            }}
        </h1>

        <p class="heading-copy">
            Choose the event details, borrowing dates, and items you need.
            Saving or submitting the request does not reserve inventory.
            Inventory is reserved only after SPMU approval.
        </p>
    </div>
</section>

@if($isReturned)
<section class="content-area">
    <div class="action-panel action-warning" role="status">
        <div>
            <p class="eyebrow">Action required</p>
            <h2>Returned for Revision</h2>
            <p>Update the request using the review remarks below.</p>
        </div>

        @if($returnRemarks->isNotEmpty())
            <ul>
                @foreach($returnRemarks as $remark)
                    <li>{{ $remark }}</li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
@endif

<section class="content-area request-form-shell">
<form
    method="post"
    enctype="multipart/form-data"
    action="{{ $editing
        ? route('requests.update', $borrowingRequest)
        : route('requests.store')
    }}"
    id="request-form"
>
    @csrf

    @if($editing)
        @method('PUT')
    @endif

    <div class="create-request-ui">

        <section class="request-card" aria-labelledby="request-details-heading">
            <div class="request-card-header">
                <div>
                    <p class="eyebrow">1. Request details</p>
                    <h2 id="request-details-heading">Event information</h2>
                </div>
            </div>

            <div class="request-card-body">
                <div class="field-grid">
                    <label class="full-span">
                        Event Details
                        <textarea
                            name="event_details"
                            maxlength="2000"
                            required
                            placeholder="Enter the event, activity, purpose, or other borrowing details."
                        >{{ old('event_details', $version->event_details ?: $version->purpose_event) }}</textarea>

                        @error('event_details')
                            <small class="field-error">{{ $message }}</small>
                        @enderror
                    </label>

                    <label class="full-span">
                        Location
                        <input
                            name="location"
                            value="{{ old('location', $version->location) }}"
                            maxlength="255"
                            required
                            placeholder="Enter where the event or activity will be held."
                        >

                        @error('location')
                            <small class="field-error">{{ $message }}</small>
                        @enderror
                    </label>
                </div>
            </div>
        </section>

        <section class="request-card" aria-labelledby="borrowing-period-heading">
            <div class="request-card-header">
                <div>
                    <p class="eyebrow">2. Borrowing period</p>
                    <h2 id="borrowing-period-heading">Schedule and return dates</h2>
                    <p class="meta">
                        These dates are connected to the inventory picker below.
                        Availability automatically refreshes when either date changes.
                    </p>
                </div>

                <span class="inventory-date-context" id="inventory-date-context">
                    Select dates
                </span>
            </div>

            <div class="request-card-body">
                <div class="field-grid">
                    <label>
                        Schedule Date
                        <input
                            id="schedule_date"
                            type="date"
                            name="schedule_date"
                            value="{{ old(
                                'schedule_date',
                                optional($version->schedule_date ?: $version->needed_from)->format('Y-m-d')
                            ) }}"
                            required
                        >

                        @error('schedule_date')
                            <small class="field-error">{{ $message }}</small>
                        @enderror
                    </label>

                    <label>
                        Expected Return Date
                        <input
                            id="return_date"
                            type="date"
                            name="return_date"
                            value="{{ old(
                                'return_date',
                                optional($version->return_date ?: $version->return_due_at)->format('Y-m-d')
                            ) }}"
                            required
                        >

                        @error('return_date')
                            <small class="field-error">{{ $message }}</small>
                        @enderror
                    </label>
                </div>
            </div>
        </section>

        <section class="request-card" aria-labelledby="student-activity-heading">
            <div class="request-card-header">
                <div>
                    <p class="eyebrow">3. Student activity</p>
                    <h2 id="student-activity-heading">Activity representation</h2>
                    <p class="meta">
                        Turn this on only when the request represents a student activity.
                    </p>
                </div>
            </div>

            <div class="request-card-body">
                <div class="student-activity-panel">
                    <label class="checkbox">
                        <input
                            type="hidden"
                            name="represents_student_activity"
                            value="0"
                        >

                        <input
                            id="student-activity-toggle"
                            type="checkbox"
                            name="represents_student_activity"
                            value="1"
                            @checked(old(
                                'represents_student_activity',
                                $version->represents_student_activity
                            ))
                        >

                        This request represents a student activity
                    </label>

                    <div
                        id="student-activity-fields"
                        class="student-fields"
                    >
                        <label>
                            Organization / Division / Unit
                            <input
                                name="student_organization"
                                value="{{ old(
                                    'student_organization',
                                    $version->student_organization
                                ) }}"
                                maxlength="255"
                            >

                            @error('student_organization')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>

                        <label>
                            Program / Department / Office
                            <input
                                name="represented_program_department"
                                value="{{ old(
                                    'represented_program_department',
                                    $version->represented_program_department
                                ) }}"
                                maxlength="255"
                            >

                            @error('represented_program_department')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>
                    </div>
                </div>
            </div>
        </section>

        <section class="request-card" aria-labelledby="item-picker-heading">
            <div class="request-card-header">
                <div>
                    <p class="eyebrow">4. Add items</p>
                    <h2 id="item-picker-heading">Search and add inventory items</h2>
                    <p class="meta" id="availability-message">
                        Select Schedule Date and Expected Return Date to load availability.
                    </p>
                </div>
            </div>

            <div class="request-card-body">
                @error('item_ids')
                    <p class="field-error">{{ $message }}</p>
                @enderror

                @error('items')
                    <p class="field-error">{{ $message }}</p>
                @enderror

                @error('quantities')
                    <p class="field-error">{{ $message }}</p>
                @enderror

                @error('locations')
                    <p class="field-error">{{ $message }}</p>
                @enderror

                <div class="item-picker-toolbar">
                    <label for="inventory-search">
                        Search item / category
                        <input
                            id="inventory-search"
                            type="search"
                            placeholder="Start typing an item, category, description, or unit..."
                            autocomplete="off"
                        >
                    </label>

                    <label for="inventory-category">
                        Category
                        <select id="inventory-category">
                            <option value="">All Categories</option>

                            @foreach($categories as $category)
                                <option value="{{ strtolower($category) }}">
                                    {{ $category }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <div class="catalog-shell">
                    <div class="catalog-summary">
                        <span id="catalog-result-label">
                            Browse available inventory
                        </span>

                        <span>5 items per page</span>
                    </div>

                    <div id="catalog-list" class="catalog-list">
                        @foreach($items as $item)
                            @php
                                $categoryName = $item->category?->category_name ?: 'Uncategorized';

                                $searchText = strtolower(
                                    $item->unique_description.' '.
                                    ($item->specification ?? '').' '.
                                    $categoryName.' '.
                                    ($item->unit?->unit_name ?? '').' '.
                                    $item->id
                                );

                                $requestItem = $selectedItems->get($item->id);

                                $requestedQuantity = old(
                                    'quantities.'.$item->id,
                                    $requestItem?->requested_quantity ?? 0
                                );

                                $wasSelected = $oldItemIds->isNotEmpty()
                                    ? $oldItemIds->contains((int) $item->id)
                                    : ((float) $requestedQuantity > 0);

                                $selectedLocation = old(
                                    'locations.'.$item->id,
                                    $requestItem?->use_location ?? 'ON_CAMPUS'
                                );
                            @endphp

                            <article
                                class="catalog-item"
                                data-catalog-item
                                data-item-id="{{ $item->id }}"
                                data-search="{{ $searchText }}"
                                data-category="{{ strtolower($categoryName) }}"
                            >
                                <div class="catalog-item-main">
                                    <div class="catalog-item-title">
                                        <strong>{{ $item->unique_description }}</strong>

                                        @if($item->laundry_required)
                                            <span class="badge">Laundry required</span>
                                        @endif
                                    </div>

                                    <div class="catalog-item-meta">
                                        <span>{{ $categoryName }}</span>
                                        <span>&middot;</span>
                                        <span>{{ $item->unit?->unit_name }}</span>
                                    </div>

                                    @if($item->specification)
                                        <p class="catalog-item-description">
                                            {{ $item->specification }}
                                        </p>
                                    @endif
                                </div>

                                <div class="catalog-item-side">
                                    <span
                                        class="catalog-availability"
                                        data-catalog-availability="{{ $item->id }}"
                                    >
                                        Select dates
                                    </span>

                                    <button
                                        type="button"
                                        class="button secondary small ui-pressable catalog-add-button"
                                        data-add-item="{{ $item->id }}"
                                        @disabled(!$wasSelected)
                                    >
                                        {{ $wasSelected ? 'Added' : '+ Add' }}
                                    </button>
                                </div>
                            </article>
                        @endforeach
                    </div>

                    <div
                        id="catalog-empty"
                        class="catalog-empty"
                        hidden
                    >
                        No item matches the current search and category filter.
                    </div>

                    <div class="catalog-pagination">
                        <button
                            type="button"
                            class="button secondary small ui-pressable"
                            id="catalog-previous"
                        >
                            &larr; Previous
                        </button>

                        <span
                            class="catalog-page-label"
                            id="catalog-page-label"
                        >
                            Page 1
                        </span>

                        <button
                            type="button"
                            class="button secondary small ui-pressable"
                            id="catalog-next"
                        >
                            Next &rarr;
                        </button>
                    </div>
                </div>

                <div class="selected-items-header">
                    <div>
                        <h3>Selected Items</h3>
                    </div>

                    <span class="selected-count">
                        <span id="selected-item-count">0</span>&nbsp;added
                    </span>
                </div>

                <div
                    id="selected-empty"
                    class="selected-empty"
                >
                    No items added yet. Search or browse above, then select <strong>+ Add</strong>.
                </div>

                <div class="table-wrap selected-items-table">
                    <table id="selected-items-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Available</th>
                                <th>Quantity</th>
                                <th>Use Location</th>
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach($items as $item)
                                @php
                                    $categoryName = $item->category?->category_name ?: 'Uncategorized';
                                    $requestItem = $selectedItems->get($item->id);

                                    $requestedQuantity = old(
                                        'quantities.'.$item->id,
                                        $requestItem?->requested_quantity ?? 0
                                    );

                                    $wasSelected = $oldItemIds->isNotEmpty()
                                        ? $oldItemIds->contains((int) $item->id)
                                        : ((float) $requestedQuantity > 0);

                                    $selectedLocation = old(
                                        'locations.'.$item->id,
                                        $requestItem?->use_location ?? 'ON_CAMPUS'
                                    );
                                @endphp

                                <tr
                                    class="selected-item-row"
                                    data-selected-item="{{ $item->id }}"
                                    @if(!$wasSelected) hidden @endif
                                >
                                    <td data-label="Item">
                                        <input
                                            type="checkbox"
                                            name="item_ids[]"
                                            value="{{ $item->id }}"
                                            data-selected-checkbox="{{ $item->id }}"
                                            @checked($wasSelected)
                                            hidden
                                        >

                                        <div class="selected-item-name">
                                            <strong>{{ $item->unique_description }}</strong>
                                            <small>
                                                {{ $categoryName }}
                                                &middot;
                                                {{ $item->unit?->unit_name }}
                                            </small>
                                        </div>
                                    </td>

                                    <td data-label="Available">
                                        <div class="selected-availability">
                                            <strong
                                                data-selected-availability="{{ $item->id }}"
                                            >
                                                -
                                            </strong>

                                            <small
                                                data-selected-availability-note="{{ $item->id }}"
                                            >
                                                Select dates
                                            </small>
                                        </div>
                                    </td>

                                    <td data-label="Quantity">
                                        <input
                                            class="selected-quantity"
                                            type="number"
                                            min="0"
                                            step="0.001"
                                            name="quantities[{{ $item->id }}]"
                                            value="{{ $requestedQuantity }}"
                                            data-selected-quantity="{{ $item->id }}"
                                            aria-label="Requested quantity for {{ $item->unique_description }}"
                                        >
                                    </td>

                                    <td data-label="Use Location">
                                        <select
                                            class="selected-location"
                                            name="locations[{{ $item->id }}]"
                                            data-selected-location="{{ $item->id }}"
                                            aria-label="Use location for {{ $item->unique_description }}"
                                        >
                                            <option
                                                value="ON_CAMPUS"
                                                @selected($selectedLocation === 'ON_CAMPUS')
                                            >
                                                On Campus
                                            </option>

                                            @if($item->off_campus_allowed)
                                                <option
                                                    value="OFF_CAMPUS"
                                                    @selected($selectedLocation === 'OFF_CAMPUS')
                                                >
                                                    Off Campus
                                                </option>
                                            @endif
                                        </select>
                                    </td>

                                    <td data-label="Action">
                                        <button
                                            type="button"
                                            class="button danger small ui-pressable"
                                            data-remove-item="{{ $item->id }}"
                                        >
                                            Remove
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div
                    id="gate-pass-notice"
                    class="callout warning picker-warning"
                    hidden
                >
                    <strong>Gate Pass required for off-campus use.</strong>
                    <p>
                        SPMU prepares the Gate Pass after approval and before physical release
                        of the applicable item.
                    </p>
                </div>

                <div
                    id="availability-conflict"
                    class="callout danger picker-warning"
                    hidden
                >
                    <strong>Review the selected item quantities.</strong>
                    <p>
                        One or more requested quantities exceed the availability for the
                        currently selected dates. Reduce the quantity or remove the item
                        before saving.
                    </p>
                </div>
            </div>
        </section>

        <section class="request-card" aria-labelledby="documents-heading">
            <div class="request-card-header">
                <div>
                    <p class="eyebrow">5. Required documents</p>
                    <h2 id="documents-heading">Supporting scans</h2>
                    <p class="meta">
                        Documents remain optional while saving a draft.
                        Required documents are checked before submission to SPMU.
                    </p>
                </div>
            </div>

            <div class="request-card-body">
                <div class="documents-grid">
                    <div class="document-box">
                        @if($requestLetter)
                            <p class="document-current">
                                Current Borrowing Request Letter:
                                <a
                                    href="{{ route('files.show', $requestLetter->file, false) }}"
                                    target="_blank"
                                    rel="noopener"
                                >
                                    View uploaded file
                                </a>
                            </p>
                        @endif

                        <label>
                            Fully Signed Borrowing Request Letter
                            <input
                                type="file"
                                name="approved_request_letter"
                                accept="application/pdf,image/png,image/jpeg,image/webp"
                            >
                            <small>
                                Upload the clear scanned copy with all required wet signatures.
                            </small>
                        </label>

                        @error('approved_request_letter')
                            <small class="field-error">{{ $message }}</small>
                        @enderror
                    </div>

                    <div class="document-box">
                        @if($ptc)
                            <p class="document-current">
                                Current Permission to Conduct Letter:
                                <a
                                    href="{{ route('files.show', $ptc->file, false) }}"
                                    target="_blank"
                                    rel="noopener"
                                >
                                    View uploaded file
                                </a>
                            </p>
                        @endif

                        <label>
                            Permission to Conduct Letter
                            <input
                                type="file"
                                name="permission_to_conduct_letter"
                                accept="application/pdf,image/png,image/jpeg,image/webp"
                            >
                            <small>
                                Required before submission when this represents a student activity.
                            </small>
                        </label>

                        @error('permission_to_conduct_letter')
                            <small class="field-error">{{ $message }}</small>
                        @enderror
                    </div>
                </div>
            </div>
        </section>

        <div class="sticky-actions">
            <p class="meta">
                Search works across the full eligible inventory.
                Previous / Next is only for browsing five items at a time.
            </p>

            <div class="actions">
                <a
                    class="button secondary ui-pressable"
                    href="{{ route('requests.index') }}"
                >
                    Cancel
                </a>

                <button
                    id="request-submit-button"
                    class="button primary ui-pressable"
                    type="submit"
                >
                    {{ $editing ? 'Save Draft Changes' : 'Save Draft Request' }}
                </button>
            </div>
        </div>
    </div>
</form>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const pageSize = 5;

    const form = document.getElementById('request-form');
    const submitButton = document.getElementById('request-submit-button');

    const scheduleDate = document.getElementById('schedule_date');
    const returnDate = document.getElementById('return_date');
    const dateContext = document.getElementById('inventory-date-context');
    const availabilityMessage = document.getElementById('availability-message');

    const studentToggle = document.getElementById('student-activity-toggle');
    const studentFields = document.getElementById('student-activity-fields');

    const searchInput = document.getElementById('inventory-search');
    const categorySelect = document.getElementById('inventory-category');

    const catalogItems = Array.from(
        document.querySelectorAll('[data-catalog-item]')
    );

    const previousButton = document.getElementById('catalog-previous');
    const nextButton = document.getElementById('catalog-next');
    const pageLabel = document.getElementById('catalog-page-label');
    const resultLabel = document.getElementById('catalog-result-label');
    const catalogEmpty = document.getElementById('catalog-empty');

    const selectedEmpty = document.getElementById('selected-empty');
    const selectedCount = document.getElementById('selected-item-count');
    const gatePassNotice = document.getElementById('gate-pass-notice');
    const availabilityConflict = document.getElementById('availability-conflict');

    let currentPage = 1;
    let availability = {};
    let availabilityLoaded = false;
    let availabilityTimer = null;

    function formatDateLabel(value) {
        if (!value) {
            return '';
        }

        const date = new Date(`${value}T00:00:00`);

        if (Number.isNaN(date.getTime())) {
            return value;
        }

        return new Intl.DateTimeFormat(
            'en-PH',
            {
                day: 'numeric',
                month: 'short',
                year: 'numeric'
            }
        ).format(date);
    }

    function syncDateContext() {
        const from = scheduleDate?.value || '';
        const to = returnDate?.value || '';

        if (!dateContext) {
            return;
        }

        dateContext.textContent =
            from && to
                ? `${formatDateLabel(from)} - ${formatDateLabel(to)}`
                : 'Select dates';
    }

    function syncStudentFields() {
        const active = Boolean(studentToggle?.checked);

        if (studentFields) {
            studentFields.hidden = !active;
        }

        const programField =
            studentFields?.querySelector(
                '[name="represented_program_department"]'
            );

        if (programField) {
            programField.required = active;
        }

        studentFields
            ?.querySelectorAll('input')
            .forEach((input) => {
                input.disabled = !active;
            });
    }

    function getFilteredCatalog() {
        const query =
            (searchInput?.value || '')
                .trim()
                .toLowerCase();

        const category =
            (categorySelect?.value || '')
                .trim()
                .toLowerCase();

        return catalogItems.filter((item) => {
            const matchesSearch =
                !query
                || (item.dataset.search || '').includes(query);

            const matchesCategory =
                !category
                || (item.dataset.category || '') === category;

            return matchesSearch && matchesCategory;
        });
    }

    function renderCatalog() {
        const filtered = getFilteredCatalog();
        const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));

        if (currentPage > totalPages) {
            currentPage = totalPages;
        }

        if (currentPage < 1) {
            currentPage = 1;
        }

        catalogItems.forEach((item) => {
            item.hidden = true;
        });

        const start = (currentPage - 1) * pageSize;
        const end = Math.min(start + pageSize, filtered.length);

        filtered
            .slice(start, end)
            .forEach((item) => {
                item.hidden = false;
            });

        if (catalogEmpty) {
            catalogEmpty.hidden = filtered.length !== 0;
        }

        if (pageLabel) {
            pageLabel.textContent =
                filtered.length
                    ? `Page ${currentPage} of ${totalPages}`
                    : 'No results';
        }

        if (resultLabel) {
            const query = (searchInput?.value || '').trim();

            if (filtered.length === 0) {
                resultLabel.textContent = 'No matching items';
            } else if (query) {
                resultLabel.textContent =
                    `Showing ${start + 1}-${end} of ${filtered.length} matches for "${query}"`;
            } else {
                resultLabel.textContent =
                    `Showing ${start + 1}-${end} of ${filtered.length} eligible items`;
            }
        }

        if (previousButton) {
            previousButton.disabled =
                filtered.length === 0 || currentPage <= 1;
        }

        if (nextButton) {
            nextButton.disabled =
                filtered.length === 0 || currentPage >= totalPages;
        }

        syncCatalogButtons();
    }

    function isSelected(itemId) {
        return Boolean(
            document.querySelector(
                `[data-selected-checkbox="${itemId}"]`
            )?.checked
        );
    }

    function syncCatalogButtons() {
        document
            .querySelectorAll('[data-add-item]')
            .forEach((button) => {
                const itemId = button.dataset.addItem;
                const added = isSelected(itemId);
                const available =
                    Number(availability[itemId]?.available ?? 0);

                let disabled = false;

                if (added) {
                    button.textContent = 'Added';
                    button.classList.add('is-added');
                    disabled = true;
                } else {
                    button.textContent = '+ Add';
                    button.classList.remove('is-added');

                    disabled =
                        !availabilityLoaded
                        || !scheduleDate?.value
                        || !returnDate?.value
                        || available <= 0;
                }

                button.disabled = disabled;
            });
    }

    function updateCatalogAvailability() {
        document
            .querySelectorAll('[data-catalog-availability]')
            .forEach((node) => {
                const itemId = node.dataset.catalogAvailability;
                const balance = availability[itemId];

                node.classList.remove(
                    'is-available',
                    'is-unavailable'
                );

                if (!availabilityLoaded || !balance) {
                    node.textContent = 'Select dates';
                    return;
                }

                const available =
                    Math.max(
                        0,
                        Number(balance.available) || 0
                    );

                node.textContent =
                    available > 0
                        ? `${available} available`
                        : 'Unavailable for selected dates';

                node.classList.add(
                    available > 0
                        ? 'is-available'
                        : 'is-unavailable'
                );
            });

        syncCatalogButtons();
    }

    function syncSelectedItems() {
        let count = 0;
        let conflict = false;
        let offCampus = false;

        document
            .querySelectorAll('[data-selected-item]')
            .forEach((row) => {
                const itemId = row.dataset.selectedItem;
                const checkbox =
                    document.querySelector(
                        `[data-selected-checkbox="${itemId}"]`
                    );

                const quantity =
                    document.querySelector(
                        `[data-selected-quantity="${itemId}"]`
                    );

                const location =
                    document.querySelector(
                        `[data-selected-location="${itemId}"]`
                    );

                const availabilityValue =
                    document.querySelector(
                        `[data-selected-availability="${itemId}"]`
                    );

                const availabilityNote =
                    document.querySelector(
                        `[data-selected-availability-note="${itemId}"]`
                    );

                const selected = Boolean(checkbox?.checked);

                row.hidden = !selected;

                if (!selected) {
                    return;
                }

                count++;

                if (
                    location?.value === 'OFF_CAMPUS'
                ) {
                    offCampus = true;
                }

                const balance = availability[itemId];

                if (!availabilityLoaded || !balance) {
                    if (availabilityValue) {
                        availabilityValue.textContent = '-';
                    }

                    if (availabilityNote) {
                        availabilityNote.textContent = 'Select dates';
                        availabilityNote.classList.remove('is-error');
                    }

                    return;
                }

                const available =
                    Math.max(
                        0,
                        Number(balance.available) || 0
                    );

                const requested =
                    Number(quantity?.value || 0);

                if (availabilityValue) {
                    availabilityValue.textContent = available;
                }

                const invalid =
                    available <= 0
                    || requested <= 0
                    || requested > available;

                if (availabilityNote) {
                    availabilityNote.classList.toggle(
                        'is-error',
                        invalid
                    );

                    if (available <= 0) {
                        availabilityNote.textContent =
                            'Unavailable for these dates';
                    } else if (requested > available) {
                        availabilityNote.textContent =
                            `Requested ${requested}; only ${available} available`;
                    } else if (requested <= 0) {
                        availabilityNote.textContent =
                            'Enter a quantity greater than 0';
                    } else {
                        availabilityNote.textContent =
                            'Available for selected dates';
                    }
                }

                if (quantity && available > 0) {
                    quantity.max = available;
                }

                if (invalid) {
                    conflict = true;
                }
            });

        if (selectedEmpty) {
            selectedEmpty.hidden = count !== 0;
        }

        if (selectedCount) {
            selectedCount.textContent = String(count);
        }

        if (gatePassNotice) {
            gatePassNotice.hidden = !offCampus;
        }

        if (availabilityConflict) {
            availabilityConflict.hidden =
                !availabilityLoaded || !conflict;
        }

        if (submitButton) {
            submitButton.disabled =
                count === 0
                || (
                    availabilityLoaded
                    && conflict
                );
        }

        syncCatalogButtons();
    }

    function addItem(itemId) {
        const checkbox =
            document.querySelector(
                `[data-selected-checkbox="${itemId}"]`
            );

        const quantity =
            document.querySelector(
                `[data-selected-quantity="${itemId}"]`
            );

        if (!checkbox) {
            return;
        }

        const available =
            Number(availability[itemId]?.available ?? 0);

        if (
            !availabilityLoaded
            || available <= 0
        ) {
            return;
        }

        checkbox.checked = true;

        if (
            quantity
            && Number(quantity.value || 0) <= 0
        ) {
            quantity.value = 1;
        }

        syncSelectedItems();

        const row =
            document.querySelector(
                `[data-selected-item="${itemId}"]`
            );

        row?.scrollIntoView({
            behavior: 'smooth',
            block: 'nearest'
        });
    }

    function removeItem(itemId) {
        const checkbox =
            document.querySelector(
                `[data-selected-checkbox="${itemId}"]`
            );

        const quantity =
            document.querySelector(
                `[data-selected-quantity="${itemId}"]`
            );

        if (checkbox) {
            checkbox.checked = false;
        }

        if (quantity) {
            quantity.value = 0;
        }

        syncSelectedItems();
    }

    async function refreshAvailability() {
        const from = scheduleDate?.value || '';
        const to = returnDate?.value || '';

        syncDateContext();

        if (!from || !to) {
            availability = {};
            availabilityLoaded = false;

            if (availabilityMessage) {
                availabilityMessage.textContent =
                    'Select Schedule Date and Expected Return Date to load availability.';
            }

            updateCatalogAvailability();
            syncSelectedItems();
            return;
        }

        clearTimeout(availabilityTimer);

        availabilityTimer = setTimeout(async () => {
            if (availabilityMessage) {
                availabilityMessage.textContent =
                    'Checking availability for the selected dates...';
            }

            try {
                const response = await fetch(
                    `{{ route('inventory.availability') }}?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`,
                    {
                        headers: {
                            Accept: 'application/json'
                        }
                    }
                );

                if (!response.ok) {
                    throw new Error('Availability request failed.');
                }

                availability = await response.json();
                availabilityLoaded = true;

                if (availabilityMessage) {
                    availabilityMessage.textContent =
                        'Availability below is based on the complete selected borrowing period.';
                }

                updateCatalogAvailability();
                syncSelectedItems();
            } catch (error) {
                availability = {};
                availabilityLoaded = false;

                if (availabilityMessage) {
                    availabilityMessage.textContent =
                        'Availability could not be loaded. Check the borrowing dates and try again.';
                }

                updateCatalogAvailability();
                syncSelectedItems();
            }
        }, 250);
    }

    searchInput?.addEventListener('input', () => {
        currentPage = 1;
        renderCatalog();
    });

    categorySelect?.addEventListener('change', () => {
        currentPage = 1;
        renderCatalog();
    });

    previousButton?.addEventListener('click', () => {
        currentPage = Math.max(1, currentPage - 1);
        renderCatalog();
    });

    nextButton?.addEventListener('click', () => {
        currentPage++;
        renderCatalog();
    });

    document
        .querySelectorAll('[data-add-item]')
        .forEach((button) => {
            button.addEventListener('click', () => {
                addItem(button.dataset.addItem);
            });
        });

    document
        .querySelectorAll('[data-remove-item]')
        .forEach((button) => {
            button.addEventListener('click', () => {
                removeItem(button.dataset.removeItem);
            });
        });

    document
        .querySelectorAll('[data-selected-quantity]')
        .forEach((input) => {
            input.addEventListener('input', syncSelectedItems);
            input.addEventListener('change', syncSelectedItems);
        });

    document
        .querySelectorAll('[data-selected-location]')
        .forEach((select) => {
            select.addEventListener('change', syncSelectedItems);
        });

    studentToggle?.addEventListener(
        'change',
        syncStudentFields
    );

    scheduleDate?.addEventListener(
        'change',
        refreshAvailability
    );

    returnDate?.addEventListener(
        'change',
        refreshAvailability
    );

    form?.addEventListener('submit', (event) => {
        syncSelectedItems();

        if (submitButton?.disabled) {
            event.preventDefault();

            availabilityConflict?.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
        }
    });

    syncStudentFields();
    syncDateContext();
    renderCatalog();
    syncSelectedItems();
    refreshAvailability();
});
</script>

@endsection
