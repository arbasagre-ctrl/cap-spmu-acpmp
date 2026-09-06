@extends('layouts.app', ['title' => $borrowingRequest->exists ? 'Edit Request' : 'Create Request'])

@section('content')

@php
    $pickupAvailability = $pickupAvailability ?? null;
@endphp

@if($pickupAvailability && ! $pickupAvailability['available'])
    {{--
        Informational only: online request submission availability and physical
        pickup/release availability are separate, so this never blocks the form.
    --}}
    <section class="content-area">
        <div class="callout warning request-pickup-availability">
            <x-icon name="information" size="21" />
            <div>
                <strong>Borrowing requests are currently being accepted.</strong>
                <p>
                    However, physical pickup and release are unavailable at this time.
                    @if($pickupAvailability['next'])
                        The next available pickup/release schedule is {{ $pickupAvailability['next']->format('d M Y, g:i A') }}.
                    @endif
                </p>
            </div>
        </div>
    </section>
@endif


@php
    $editing = $borrowingRequest->exists;
    $hasCurrentESignature = auth()->user()->currentSignature()->whereHas('file')->exists();

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


    $divisionOptions = [
        'ADMINISTRATION' => 'Administrative',
        'ACADEMIC' => 'Academic',
        'RESEARCH_INNOVATION_COLLABORATION' => 'Research, Innovation and Collaboration',
    ];

    /*
     * Canonical Office/Academic/Research Unit list comes from the
     * controller (BorrowingRequestController::officeUnitsByDivision()) so
     * this form can never drift from what the backend actually accepts.
     */
    $officeUnitsByDivision = $officeUnitsByDivision ?? [];

    $selectedDivision = old(
        'division_code',
        $version->division_code ?? ($prefillDivisionCode ?? '')
    );
    $selectedOfficeUnit = old(
        'office_unit',
        $version->office_unit ?? ($prefillOfficeUnit ?? '')
    );

    $requestingUnitOptions = $requestingUnitOptions ?? [];
    $selectedRequestingUnitId = old(
        'requesting_organizational_unit_id',
        $prefillRequestingUnitId
            ?? $borrowingRequest->accountable_unit_id
            ?? ''
    );

    $selectedRequestingUnitOption = collect($requestingUnitOptions)
        ->firstWhere('id', (int) $selectedRequestingUnitId);

    if ($selectedRequestingUnitOption) {
        $selectedDivision = $selectedRequestingUnitOption['division_code'];
        $selectedOfficeUnit = $selectedRequestingUnitOption['name'];
    }

    $oldLocations = collect(old('locations', []));
    $requestUsesOffCampus = $oldLocations->contains('OFF_CAMPUS')
        || ($oldLocations->isEmpty() && $selectedItems->contains(
            fn ($requestItem) => $requestItem?->use_location === 'OFF_CAMPUS'
        ));

    /*
     * RESUME WHERE THE BORROWER STOPPED
     * ---------------------------------
     * Continuing a saved draft must reopen the stage the borrower actually
     * stopped on, not replay stage 1. A stage counts as done when the data
     * it collects is already on the version, so the resume point is derived
     * from what was saved instead of being stored separately and drifting
     * from it. Reading through old() means a redisplayed form after a failed
     * validation pass also comes back on the stage being worked on.
     */
    $stageOneValues = [
        old('purpose_event', $version->purpose_event),
        old('location', $version->location),
        $selectedRequestingUnitId,
        $selectedDivision,
        $selectedOfficeUnit,
        old('schedule_date', optional($version->schedule_date ?: $version->needed_from)->format('Y-m-d')),
        old('return_date', optional($version->return_date ?: $version->return_due_at)->format('Y-m-d')),
    ];

    $stageOneComplete = collect($stageOneValues)
        ->every(fn ($value) => trim((string) $value) !== '');

    $stageTwoComplete = old('item_ids') !== null
        ? $oldItemIds->isNotEmpty()
        : $selectedItems->isNotEmpty();

    $resumeStage = match (true) {
        ! $stageOneComplete => 1,
        ! $stageTwoComplete => 2,
        default => 3,
    };
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
        display: flex;
        align-items: center;
        justify-content: flex-start;
        gap: 10px;
        width: 100%;
        margin: 0;
        text-align: left;
    }

    .student-activity-panel .checkbox input[type="checkbox"] {
        flex: 0 0 auto;
        width: 18px;
        height: 18px;
        margin: 0;
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

    .inventory-search-shell {
        position: relative;
        margin-bottom: 16px;
    }

    .inventory-search-label {
        display: block;
        min-width: 0;
    }

    .catalog-shell {
        position: absolute;
        z-index: 30;
        top: calc(100% + 8px);
        left: 0;
        right: 0;
        max-height: 430px;
        overflow: auto;
        border: 1px solid var(--cr-line);
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 16px 38px rgba(16, 42, 67, .16);
    }

    .catalog-shell[hidden] {
        display: none !important;
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

    .request-premises-panel {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 18px;
        margin-bottom: 18px;
        padding: 14px 16px;
        border: 1px solid #d8e3ee;
        border-radius: 12px;
        background: #f8fafc;
    }

    .request-premises-copy {
        display: grid;
        gap: 3px;
        color: var(--cr-muted);
        font-size: 11px;
        line-height: 1.45;
    }

    .request-premises-copy strong {
        color: var(--cr-ink);
        font-size: 12px;
    }

    .request-premises-toggle {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        min-height: 38px;
        margin: 0;
        padding: 8px 11px;
        border: 1px solid #cbd8e5;
        border-radius: 10px;
        background: #fff;
        color: var(--cr-ink);
        font-weight: 800;
        white-space: nowrap;
        cursor: pointer;
    }

    .request-premises-toggle input[type="checkbox"] {
        width: 18px;
        height: 18px;
        margin: 0;
    }

    @media (max-width: 760px) {
        .request-premises-panel {
            flex-direction: column;
        }
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

    .item-code-badge {
        display: inline-flex;
        align-items: center;
        min-height: 22px;
        padding: 2px 7px;
        border: 1px solid #cfdae6;
        border-radius: 7px;
        background: #f7f9fc;
        color: #274560;
        font-size: 10px;
        font-weight: 800;
        letter-spacing: .025em;
        white-space: nowrap;
    }

    .review-summary {
        margin-bottom: 18px;
        padding: 17px;
        border: 1px solid var(--cr-line);
        border-radius: 14px;
        background: #fbfcfe;
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

    .request-stepper {
        display: grid;
        grid-template-columns: auto minmax(56px, 1fr) auto minmax(56px, 1fr) auto;
        align-items: center;
        gap: 14px;
        padding: 19px 22px;
        border-top: 1px solid var(--cr-line);
        border-bottom: 1px solid var(--cr-line);
        background: #f8fafc;
    }

    .request-step {
        appearance: none;
        display: inline-flex;
        align-items: center;
        gap: 9px;
        padding: 0;
        border: 0;
        background: transparent;
        color: #8a99aa;
        font: inherit;
        font-size: 13px;
        font-weight: 800;
        white-space: nowrap;
        cursor: pointer;
    }

    .request-step:disabled {
        cursor: default;
    }

    .request-step-number {
        display: grid;
        place-items: center;
        width: 32px;
        height: 32px;
        flex: 0 0 32px;
        border-radius: 50%;
        background: #e5edf4;
        color: #8595a6;
        font-size: 13px;
        font-weight: 800;
    }

    .request-step.is-active {
        color: #1769e0;
    }

    .request-step.is-active .request-step-number,
    .request-step.is-complete .request-step-number {
        background: #1769e0;
        color: #fff;
    }

    .request-step.is-complete {
        color: #496982;
    }

    .request-step-line {
        height: 1px;
        background: #d4dee8;
    }

    [data-stage-panel][hidden] {
        display: none !important;
    }

    .stage-actions {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 9px;
        padding: 12px;
        border: 1px solid var(--cr-line);
        border-radius: 14px;
        background: #fff;
    }

    .field-help {
        display: block;
        margin-top: 6px;
        color: var(--cr-muted);
        font-size: 11px;
        line-height: 1.4;
    }

    @media (max-width: 800px) {
        .catalog-item {
            align-items: start;
        }
    }

    @media (max-width: 700px) {
        .request-stepper {
            grid-template-columns: 1fr;
            gap: 10px;
        }

        .request-step-line {
            display: none;
        }

        .stage-actions {
            flex-direction: column-reverse;
            align-items: stretch;
        }

        .stage-actions .button {
            width: 100%;
            justify-content: center;
        }

        .create-request-ui .field-grid,
        .student-fields,
        .review-summary-grid {
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

        .catalog-shell {
            position: static;
            max-height: 360px;
            margin-top: 8px;
            box-shadow: none;
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


    /* Final Stage 3 confirmation */
    .final-confirmation {
        display: flex;
        align-items: flex-start;
        justify-content: flex-start;
        gap: 10px;
        margin-top: 20px;
        padding: 16px 18px;
        border: 1px solid var(--cr-line);
        border-radius: 12px;
        background: #f8fafc;
        text-align: left;
        font-weight: 700;
    }

    .final-confirmation input[type="checkbox"] {
        flex: 0 0 18px;
        width: 18px;
        height: 18px;
        margin: 2px 0 0;
    }

    .final-confirmation > span {
        display: grid;
        gap: 5px;
    }

    .final-confirmation > span > strong {
        color: var(--cr-ink);
        font-size: 12px;
    }

    .final-confirmation > span > small {
        color: var(--cr-muted);
        font-size: 11px;
        font-weight: 500;
        line-height: 1.55;
    }


    /* Create Request final action must always receive pointer input when enabled. */
    #request-submit-button:not(:disabled) {
        pointer-events: auto !important;
        cursor: pointer;
        position: relative;
        z-index: 2;
    }
</style>

@include('requests.partials.create-styles')
@include('requests.partials.create-review-styles')
@include('requests.partials.create-validation-styles')

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

        <nav class="request-stepper" aria-label="Create request progress">
            <button type="button" class="request-step is-active" data-stage-target="1" aria-current="step">
                <span class="request-step-number">1</span>
                <span>Request details</span>
            </button>

            <span class="request-step-line" aria-hidden="true"></span>

            <button type="button" class="request-step" data-stage-target="2" disabled>
                <span class="request-step-number">2</span>
                <span>Select items</span>
            </button>

            <span class="request-step-line" aria-hidden="true"></span>

            <button type="button" class="request-step" data-stage-target="3" disabled>
                <span class="request-step-number">3</span>
                <span>Documents &amp; Review</span>
            </button>
        </nav>


        @include('requests.partials.create-details')

        <section class="request-card request-picker-card" data-stage-panel="2" aria-labelledby="item-picker-heading">
            <div class="request-card-header">
                <div>
                    <p class="eyebrow">Select items</p>
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

                @include('requests.partials.create-premises')

                <div class="inventory-search-shell">
                    <label for="inventory-search" class="inventory-search-label">
                        Search inventory
                        <span class="search-input-shell">
                            <span class="search-input-icon" aria-hidden="true"><x-icon name="search" /></span>
                            <input
                                id="inventory-search"
                                type="search"
                                placeholder="Start typing an item name, category, description, or unit..."
                                autocomplete="off"
                                aria-autocomplete="list"
                                aria-controls="catalog-list"
                            >
                        </span>
                    </label>

                    <div class="catalog-shell" id="catalog-shell" hidden>
                        <div class="catalog-summary">
                            <span id="catalog-result-label">Recommended matches</span>
                        </div>

                    <div id="catalog-list" class="catalog-list">
                        @foreach($items as $item)
                            @php
                                $categoryName = $item->category?->category_name ?: 'Uncategorized';
                                $itemUiId = 'INV-'.str_pad((string) $item->id, 4, '0', STR_PAD_LEFT);

                                $searchText = strtolower(
                                    $item->unique_description.' '.
                                    ($item->specification ?? '').' '.
                                    $categoryName.' '.
                                    ($item->unit?->unit_name ?? '')
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
                                data-off-campus-allowed="{{ $item->off_campus_allowed ? '1' : '0' }}"
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
                        No matching eligible item found. Try another item name or keyword.
                    </div>

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
                    No items added yet. Use the search field above, then select <strong>+ Add</strong>.
                </div>

                <div class="table-wrap selected-items-table" @if(!$stageTwoComplete) hidden @endif>
                    <table id="selected-items-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Available</th>
                                <th>Quantity</th>
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach($items as $item)
                                @php
                                    $categoryName = $item->category?->category_name ?: 'Uncategorized';
                                    $itemUiId = 'INV-'.str_pad((string) $item->id, 4, '0', STR_PAD_LEFT);
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
                                    data-item-code="{{ $itemUiId }}"
                                    data-item-name="{{ $item->unique_description }}"
                                    data-item-category="{{ $categoryName }}"
                                    data-item-unit="{{ $item->unit?->unit_name ?? '—' }}"
                                    data-off-campus-allowed="{{ $item->off_campus_allowed ? '1' : '0' }}"
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
                                            <div>
                                                <span class="item-code-badge">{{ $itemUiId }}</span>
                                                <strong>{{ $item->unique_description }}</strong>
                                            </div>
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
                                            step="1"
                                            inputmode="numeric"
                                            name="quantities[{{ $item->id }}]"
                                            value="{{ $wasSelected ? max(1, (int) round((float) $requestedQuantity)) : 0 }}"
                                            data-selected-quantity="{{ $item->id }}"
                                            aria-label="Requested quantity for {{ $item->unique_description }}"
                                        >
                                    </td>
                                    @php
                                        $normalizedSelectedLocation = $item->off_campus_allowed
                                            && $selectedLocation === 'OFF_CAMPUS'
                                                ? 'OFF_CAMPUS'
                                                : 'ON_CAMPUS';
                                    @endphp

                                    <td data-label="Action">
                                        <input
                                            type="hidden"
                                            name="locations[{{ $item->id }}]"
                                            value="{{ $normalizedSelectedLocation }}"
                                            data-selected-location="{{ $item->id }}"
                                        >

                                        <button
                                            type="button"
                                            class="button secondary small ui-pressable request-remove-button"
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
                    id="off-campus-mode-note"
                    class="callout info picker-warning picker-guidance"
                    hidden
                >
                    <strong>Gate Pass required for off-campus borrowing.</strong>
                    <p>Search and select exactly one eligible Barricade item. It must be the only item in this request.</p>
                </div>

                <div
                    id="campus-mode-conflict"
                    class="callout danger picker-warning"
                    hidden
                >
                    <strong>Review the Off-campus selection.</strong>
                    <p>Off-campus borrowing allows one Barricade item only. Remove incompatible selected items or switch back to On-campus.</p>
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

        <div class="stage-actions request-picker-actions" data-stage-panel="2">
            <button type="button" class="button secondary ui-pressable" data-stage-back="1"><x-icon name="chevron-right" class="request-back-icon" size="16" />Back</button>
            <button type="button" class="button primary ui-pressable" data-stage-next="3" disabled>
                Confirm Items &amp; Continue
                <svg class="ui-icon request-continue-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h16m-6-6 6 6-6 6" /></svg>
            </button>
        </div>

        <section class="request-card review-card" data-stage-panel="3" aria-labelledby="review-summary-heading">
            <p class="request-section-label">Review Summary</p>
            <p class="request-section-copy" id="review-summary-heading">Please review the details of your request before submitting.</p>

            <hr class="request-section-rule">

            <div class="review-summary-grid">
                <div class="review-summary-field">
                    <span>Purpose of Borrowing</span>
                    <strong id="summary-purpose">&mdash;</strong>
                </div>
                <div class="review-summary-field">
                    <span>Event Location</span>
                    <strong id="summary-location">&mdash;</strong>
                </div>
                <div class="review-summary-field">
                    <span>Division</span>
                    <strong id="summary-division">&mdash;</strong>
                </div>
                <div class="review-summary-field">
                    <span>Office / Academic Unit / Research Unit</span>
                    <strong id="summary-office">&mdash;</strong>
                </div>
                <div class="review-summary-field">
                    <span>Items Needed From</span>
                    <strong id="summary-from">&mdash;</strong>
                </div>
                <div class="review-summary-field">
                    <span>Expected Return Date</span>
                    <strong id="summary-return">&mdash;</strong>
                </div>
                <div class="review-summary-field">
                    <span>Premises</span>
                    <strong id="summary-premises" class="review-summary-pill is-positive">On-campus</strong>
                </div>
                <div class="review-summary-field">
                    <span>Student Activity</span>
                    <strong id="summary-student-activity" class="review-summary-pill is-neutral">No</strong>
                </div>
            </div>

            <hr class="request-section-rule">

            <div class="review-items-header">
                <h3>Selected Items</h3>
                <span class="review-items-count" id="summary-items-count">0 items</span>
            </div>

            <div class="table-wrap review-items-table">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Item</th>
                            <th scope="col">Category</th>
                            <th scope="col">Unit</th>
                            <th scope="col">Quantity</th>
                        </tr>
                    </thead>
                    <tbody id="summary-items-body">
                        <tr>
                            <td colspan="4" class="review-items-empty">No selected items.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="request-card documents-card" data-stage-panel="3" aria-labelledby="documents-heading">
            <p class="request-section-label">Required Documents</p>
            <p class="request-section-copy" id="documents-heading">Upload the fully signed documents required for this request.</p>

            <div class="document-rows">
                <div class="document-row">
                    <div class="document-row-info">
                        <strong>
                            Borrowing Request Letter
                            <span class="document-required">Required</span>
                        </strong>

                        <p>Upload the signed borrowing request letter.</p>

                        @if($requestLetter)
                            <p class="document-current">
                                Current file:
                                <a href="{{ route('files.show', $requestLetter->file, false) }}" target="_blank" rel="noopener">
                                    View uploaded file
                                </a>
                            </p>
                        @endif

                        @error('approved_request_letter')
                            <small class="field-error">{{ $message }}</small>
                        @enderror
                    </div>

                    <label class="document-dropzone" data-dropzone>
                        <input
                            type="file"
                            name="approved_request_letter"
                            accept="application/pdf,image/png,image/jpeg,image/webp"
                        >
                        <span class="visually-hidden">Fully signed Borrowing Request Letter</span>
                        <span class="document-dropzone-icon" aria-hidden="true">
                            <svg class="ui-icon" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6.5 18a4.5 4.5 0 0 1-.6-8.96 6 6 0 0 1 11.7-1.36A4.25 4.25 0 0 1 18 18h-1.5" /><path d="M12 12v8M9 15l3-3 3 3" /></svg>
                            <strong>Upload file</strong>
                        </span>
                        <span class="document-dropzone-hint">or drag and drop</span>
                        <small class="document-dropzone-formats">PDF, JPG, PNG (Max 10MB)</small>
                        <span class="document-dropzone-file" data-dropzone-file hidden></span>
                    </label>
                </div>

                <div class="document-row" id="ptc-document-box">
                    <div class="document-row-info">
                        <strong>
                            Permission to Conduct Letter
                            <span class="document-required">Required</span>
                        </strong>

                        <p>Upload the signed Permission to Conduct Letter.</p>

                        @if($ptc)
                            <p class="document-current">
                                Current file:
                                <a href="{{ route('files.show', $ptc->file, false) }}" target="_blank" rel="noopener">
                                    View uploaded file
                                </a>
                            </p>
                        @endif

                        @error('permission_to_conduct_letter')
                            <small class="field-error">{{ $message }}</small>
                        @enderror
                    </div>

                    <label class="document-dropzone" data-dropzone>
                        <input
                            type="file"
                            name="permission_to_conduct_letter"
                            accept="application/pdf,image/png,image/jpeg,image/webp"
                        >
                        <span class="visually-hidden">Permission to Conduct Letter</span>
                        <span class="document-dropzone-icon" aria-hidden="true">
                            <svg class="ui-icon" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6.5 18a4.5 4.5 0 0 1-.6-8.96 6 6 0 0 1 11.7-1.36A4.25 4.25 0 0 1 18 18h-1.5" /><path d="M12 12v8M9 15l3-3 3 3" /></svg>
                            <strong>Upload file</strong>
                        </span>
                        <span class="document-dropzone-hint">or drag and drop</span>
                        <small class="document-dropzone-formats">PDF, JPG, PNG (Max 10MB)</small>
                        <span class="document-dropzone-file" data-dropzone-file hidden></span>
                    </label>
                </div>
            </div>
        </section>

        <section class="request-card confirmation-card" data-stage-panel="3" aria-labelledby="acknowledgement-heading">
            <p class="request-section-label" id="acknowledgement-heading">Acknowledgement</p>

            <div class="final-confirmation acknowledgement-confirmation">
                <input type="hidden" name="borrower_acknowledgement" value="0">
                <input
                    id="final-confirmation"
                    type="checkbox"
                    name="borrower_acknowledgement"
                    value="1"
                    @checked(old('borrower_acknowledgement'))
                    @disabled(!$hasCurrentESignature)
                    required
                >
                <input
                    id="e-signature-confirmation"
                    type="hidden"
                    name="confirm_e_signature"
                    value="{{ old('borrower_acknowledgement') ? '1' : '0' }}"
                >
                <div class="acknowledgement-copy">
                    <label for="final-confirmation">
                        <strong>
                            I hereby certify that the information, supporting documents, selected items, quantities, premises, and borrowing period provided in this request are true and correct. I authorize the use of my registered E-signature for this submission and acknowledge that I have read and agree to comply with the applicable SPMU
                            <a href="#request-terms-dialog" class="request-terms-link" data-open-request-terms>Terms and Conditions</a>.
                        </strong>
                    </label>
                </div>
            </div>

            @error('borrower_acknowledgement')
                <p class="field-error">{{ $message }}</p>
            @enderror

            <p class="field-error" id="final-confirmation-error" hidden>
                Read and accept the acknowledgement and Terms and Conditions before submitting to SPMU.
            </p>

            @error('confirm_e_signature')<p class="field-error">{{ $message }}</p>@enderror
            @error('signature')<p class="field-error">{{ $message }}</p>@enderror

            @unless($hasCurrentESignature)
                <div class="esignature-notice" role="status">
                    <x-icon name="warning" size="19" />
                    <div>
                        <strong>E-signature not registered</strong>
                        <p>Register your E-signature in <a href="{{ route('profile.show') }}">Account Settings</a> before submission.</p>
                    </div>
                </div>
            @endunless
        </section>

        <div class="sticky-actions request-review-actions" data-stage-panel="3">
            <button type="button" class="button secondary ui-pressable" data-stage-back="2">
                <x-icon name="chevron-right" class="request-back-icon" size="16" />
                Back
            </button>

            <div class="actions">
                <button
                    id="request-save-draft-button"
                    class="button secondary ui-pressable"
                    type="submit"
                    name="intent"
                    value="draft"
                >
                    {{ $editing ? 'Save Draft Changes' : 'Save Draft' }}
                </button>

                <button
                    id="request-submit-button"
                    class="button primary ui-pressable request-submit-button"
                    type="button">
                    <x-icon name="requests" size="17" />
                    E-sign &amp; Submit to SPMU
                </button>

                <button
                    id="request-submit-trigger"
                    type="submit"
                    name="intent"
                    value="submit"
                    hidden
                    tabindex="-1"
                    aria-hidden="true"
                ></button>
            </div>
        </div>

        <div class="request-dialog-backdrop" id="request-terms-dialog" data-request-terms-dialog role="dialog" aria-modal="true" aria-labelledby="request-terms-title" hidden>
            <div class="request-dialog request-terms-dialog" role="document">
                <div class="request-dialog-header">
                    <div>
                        <p class="request-section-label">SPMU Borrowing</p>
                        <h2 id="request-terms-title">Terms and Conditions</h2>
                    </div>
                    <button type="button" class="request-dialog-close" data-close-request-terms aria-label="Close Terms and Conditions">&times;</button>
                </div>
                <div class="request-dialog-body">
                    <p>By submitting this request, the borrower acknowledges the following conditions for this borrowing transaction:</p>
                    <ol class="request-terms-list">
                        <li>The information and supporting documents submitted must be complete, accurate, and authentic.</li>
                        <li>Submission does not guarantee approval or reservation. Inventory is allocated only after the authorized final approval and availability check.</li>
                        <li>Approved items must be used only for the approved purpose, premises, and borrowing period stated in the request.</li>
                        <li>The borrower is responsible for borrowed items from confirmed physical release until the applicable physical return is properly received and documented. For linen, physical custody ends when Laundry Personnel receive the returned linen and record the receipt on the Laundry Form; any later verified damage, loss, or other accountability finding remains subject to applicable SPMU rules.</li>
                        <li>Items must be physically returned on or before the effective Expected Return Date, subject to the applicable SPMU operational calendar. Damage, loss, missing quantity, or other adverse findings may result in accountability processing under applicable SPMU rules.</li>
                        <li>Linen and off-campus barricade transactions must follow the applicable Laundry Form and Gate Pass procedures.</li>
                        <li>The borrower's registered E-signature snapshot will be bound to this submitted request version and retained with the corresponding audit record.</li>
                    </ol>
                </div>
                <div class="request-dialog-actions">
                    <button type="button" class="button primary ui-pressable" data-close-request-terms>Close</button>
                </div>
            </div>
        </div>

        <div class="request-dialog-backdrop" id="request-submit-confirmation-dialog" data-request-submit-dialog role="dialog" aria-modal="true" aria-labelledby="request-submit-confirmation-title" hidden>
            <div class="request-dialog request-submit-dialog" role="document">
                <div class="request-dialog-header">
                    <div>
                        <p class="request-section-label">Confirmation</p>
                        <h2 id="request-submit-confirmation-title">Confirm E-signature and Submission</h2>
                    </div>
                </div>
                <div class="request-dialog-body">
                    <p>You are about to submit this request using your registered E-signature.</p>
                    <p>By continuing, you confirm that you have reviewed the request and agree to the SPMU Terms and Conditions.</p>
                </div>
                <div class="request-dialog-actions">
                    <button type="button" class="button secondary ui-pressable" data-cancel-request-submit>Cancel</button>
                    <button type="button" class="button primary ui-pressable" data-confirm-request-submit>Confirm E-sign &amp; Submit</button>
                </div>
            </div>
        </div>
    </div>
</form>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('request-form');
    const submitButton = document.getElementById('request-submit-button');
    const submitTrigger = document.getElementById('request-submit-trigger');
    const saveDraftButton = document.getElementById('request-save-draft-button');
    const finalConfirmation = document.getElementById('final-confirmation');
    const finalConfirmationError = document.getElementById('final-confirmation-error');
    const eSignatureConfirmation = document.getElementById('e-signature-confirmation');
    const termsDialog = document.querySelector('[data-request-terms-dialog]');
    const submitDialog = document.querySelector('[data-request-submit-dialog]');
    const confirmSubmitButton = document.querySelector('[data-confirm-request-submit]');
    const cancelSubmitButton = document.querySelector('[data-cancel-request-submit]');
    const signatureReady = {{ $hasCurrentESignature ? 'true' : 'false' }};
    let submissionConfirmed = false;

    const scheduleDate = document.getElementById('schedule_date');
    const returnDate = document.getElementById('return_date');
    const dateContext = document.getElementById('inventory-date-context');
    const availabilityMessage = document.getElementById('availability-message');

    const divisionSelect = document.getElementById('division_code');
    const divisionDisplay = document.getElementById('division-display');
    const officeInput = document.getElementById('office_unit');
    const requestingUnitSelect = document.getElementById('requesting_organizational_unit_id');
    const officeDatalist = document.getElementById('office-unit-options');

    const summaryPurpose = document.getElementById('summary-purpose');
    const summaryLocation = document.getElementById('summary-location');
    const summaryDivision = document.getElementById('summary-division');
    const summaryOffice = document.getElementById('summary-office');
    const summaryFrom = document.getElementById('summary-from');
    const summaryReturn = document.getElementById('summary-return');
    const summaryPremises = document.getElementById('summary-premises');
    const summaryStudentActivity = document.getElementById('summary-student-activity');
    const summaryItemsBody = document.getElementById('summary-items-body');

    const officeUnitsByDivision = @json($officeUnitsByDivision);
    const divisionLabels = @json($divisionOptions);

    const studentToggle = document.getElementById('student-activity-toggle');
    const ptcDocumentBox = document.getElementById('ptc-document-box');
    const summaryItemsCount = document.getElementById('summary-items-count');

    const searchInput = document.getElementById('inventory-search');
    const catalogItems = Array.from(
        document.querySelectorAll('[data-catalog-item]')
    );

    const catalogShell = document.getElementById('catalog-shell');
    const resultLabel = document.getElementById('catalog-result-label');
    const catalogEmpty = document.getElementById('catalog-empty');

    const selectedEmpty = document.getElementById('selected-empty');
    const selectedCount = document.getElementById('selected-item-count');
    const availabilityConflict = document.getElementById('availability-conflict');
    const requestOffCampusToggle = document.getElementById('request-off-campus-toggle');
    const requestOnCampusToggle = document.getElementById('request-on-campus-toggle');
    const premisesHelp = document.getElementById('request-premises-help');
    const offCampusModeNote = document.getElementById('off-campus-mode-note');
    const campusModeConflict = document.getElementById('campus-mode-conflict');
    const stageOneNextButton = document.querySelector('[data-stage-next="2"]');
    const stageTwoNextButton = document.querySelector('[data-stage-next="3"]');
    const requestLetterInput = form?.querySelector('[name="approved_request_letter"]');
    const ptcInput = form?.querySelector('[name="permission_to_conduct_letter"]');
    const requestLetterAlreadyUploaded = {{ $requestLetter ? 'true' : 'false' }};
    const ptcAlreadyUploaded = {{ $ptc ? 'true' : 'false' }};
    const selectedItemsTableWrap = document.getElementById('selected-items-table')?.closest('.selected-items-table');

    const stagePanels = Array.from(
        document.querySelectorAll('[data-stage-panel]')
    );
    const stageButtons = Array.from(
        document.querySelectorAll('[data-stage-target]')
    );

    /*
     * Stage the borrower stopped on, derived from the saved draft in the
     * Blade template above. Stages up to it stay unlocked in the stepper
     * because showStage() raises furthestStage to the stage it opens.
     */
    const resumeStage = {{ $resumeStage }};

    let activeStage = 1;
    let furthestStage = 1;
    let availability = {};
    let availabilityLoaded = false;
    let availabilityTimer = null;

    function setSummaryText(element, value, fallback = '—') {
        if (!element) {
            return;
        }

        const normalized = String(value ?? '').trim();
        element.textContent = normalized || fallback;
    }

    function updateReviewSummary() {
        const purposeField = form?.querySelector('[name="purpose_event"]');
        const locationField = form?.querySelector('[name="location"]');

        setSummaryText(summaryPurpose, purposeField?.value);
        setSummaryText(summaryLocation, locationField?.value);
        setSummaryText(
            summaryDivision,
            divisionLabels[divisionSelect?.value || ''] || divisionDisplay?.value
        );
        setSummaryText(summaryOffice, officeInput?.value);
        setSummaryText(summaryFrom, formatDateLabel(scheduleDate?.value));
        setSummaryText(summaryReturn, formatDateLabel(returnDate?.value));
        const isOffCampus = Boolean(requestOffCampusToggle?.checked);
        setSummaryText(summaryPremises, isOffCampus ? 'Off-campus' : 'On-campus');
        summaryPremises?.classList.toggle('is-positive', !isOffCampus);
        summaryPremises?.classList.toggle('is-elevated', isOffCampus);

        const isStudentActivity = Boolean(studentToggle?.checked);
        setSummaryText(
            summaryStudentActivity,
            isStudentActivity ? 'Yes' : 'No',
            'No'
        );
        summaryStudentActivity?.classList.toggle('is-positive', isStudentActivity);
        summaryStudentActivity?.classList.toggle('is-neutral', !isStudentActivity);

        if (summaryItemsBody) {
            const rows = getSelectedRows();
            summaryItemsBody.innerHTML = '';

            if (rows.length === 0) {
                const emptyRow = document.createElement('tr');
                const emptyCell = document.createElement('td');
                emptyCell.colSpan = 4;
                emptyCell.className = 'review-items-empty';
                emptyCell.textContent = 'No selected items.';
                emptyRow.appendChild(emptyCell);
                summaryItemsBody.appendChild(emptyRow);
            } else {
                rows.forEach((row) => {
                    const itemId = row.dataset.selectedItem;
                    const quantity = document.querySelector(
                        `[data-selected-quantity="${itemId}"]`
                    )?.value || '0';
                    const tr = document.createElement('tr');

                    // The item code rides along inside the Item cell.
                    const itemCell = document.createElement('td');
                    itemCell.className = 'review-item-cell';

                    const badge = document.createElement('span');
                    badge.className = 'item-code-badge';
                    badge.textContent = row.dataset.itemCode
                        || `INV-${String(itemId).padStart(4, '0')}`;
                    itemCell.appendChild(badge);

                    const name = document.createElement('span');
                    name.className = 'review-item-name';
                    name.textContent = row.dataset.itemName || 'Item';
                    itemCell.appendChild(name);

                    tr.appendChild(itemCell);

                    [
                        row.dataset.itemCategory || '—',
                        row.dataset.itemUnit || '—',
                        String(Math.max(0, Math.trunc(Number(quantity) || 0))),
                    ].forEach((value) => {
                        const td = document.createElement('td');
                        td.textContent = value;
                        tr.appendChild(td);
                    });

                    summaryItemsBody.appendChild(tr);
                });
            }

            if (summaryItemsCount) {
                summaryItemsCount.textContent = rows.length === 1
                    ? '1 item'
                    : `${rows.length} items`;
            }
        }
    }

    document.querySelectorAll('[data-dropzone]').forEach((zone) => {
        const input = zone.querySelector('input[type="file"]');
        const readout = zone.querySelector('[data-dropzone-file]');

        if (!input || !readout) {
            return;
        }

        const showSelection = () => {
            const name = input.files?.[0]?.name || '';
            readout.textContent = name;
            readout.hidden = name === '';
            zone.classList.toggle('has-file', name !== '');
        };

        input.addEventListener('change', () => {
            showSelection();
            syncForwardActionStates();
        });

        ['dragenter', 'dragover'].forEach((event) => {
            zone.addEventListener(event, (dragEvent) => {
                dragEvent.preventDefault();
                zone.classList.add('is-dragging');
            });
        });

        ['dragleave', 'dragend', 'drop'].forEach((event) => {
            zone.addEventListener(event, () => zone.classList.remove('is-dragging'));
        });

        zone.addEventListener('drop', (dropEvent) => {
            dropEvent.preventDefault();

            const dropped = dropEvent.dataTransfer?.files?.[0];

            if (!dropped) {
                return;
            }

            const transfer = new DataTransfer();
            transfer.items.add(dropped);
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });

        showSelection();
    });

    function showStage(stage, scroll = true) {
        activeStage = Math.max(1, Math.min(3, Number(stage) || 1));
        furthestStage = Math.max(furthestStage, activeStage);

        stagePanels.forEach((panel) => {
            panel.hidden = Number(panel.dataset.stagePanel) !== activeStage;
        });

        stageButtons.forEach((button) => {
            const target = Number(button.dataset.stageTarget);
            button.disabled = target > furthestStage;
            button.classList.toggle('is-active', target === activeStage);
            button.classList.toggle('is-complete', target < activeStage);

            if (target === activeStage) {
                button.setAttribute('aria-current', 'step');
            } else {
                button.removeAttribute('aria-current');
            }
        });

        if (activeStage === 3) {
            updateReviewSummary();
        }

        if (scroll) {
            document.querySelector('.request-stepper')?.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    }

    function validateStageOne() {
        const fields = Array.from(
            document.querySelectorAll(
                '[data-stage-panel="1"] input[required], ' +
                '[data-stage-panel="1"] select[required], ' +
                '[data-stage-panel="1"] textarea[required]'
            )
        ).filter((field) => !field.disabled);

        for (const field of fields) {
            if (!field.checkValidity()) {
                field.reportValidity();
                field.focus();
                return false;
            }
        }

        if (scheduleDate?.value && returnDate?.value) {
            const from = new Date(`${scheduleDate.value}T00:00:00`);
            const to = new Date(`${returnDate.value}T00:00:00`);

            if (to <= from) {
                returnDate.setCustomValidity('Expected Return Date must be after Items Needed From.');
                returnDate.reportValidity();
                returnDate.setCustomValidity('');
                return false;
            }
        }

        return true;
    }

    function validateStageTwo() {
        normalizeAllQuantities();
        syncSelectedItems();

        const count = getSelectedRows().length;

        if (count === 0) {
            document.getElementById('item-picker-heading')?.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
            return false;
        }

        if (campusModeConflict && !campusModeConflict.hidden) {
            campusModeConflict.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return false;
        }

        if (availabilityLoaded && availabilityConflict && !availabilityConflict.hidden) {
            availabilityConflict.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return false;
        }

        return true;
    }

    function isStageOneReady() {
        const fields = Array.from(
            document.querySelectorAll(
                '[data-stage-panel="1"] input[required], ' +
                '[data-stage-panel="1"] select[required], ' +
                '[data-stage-panel="1"] textarea[required]'
            )
        ).filter((field) => !field.disabled);

        const allRequiredFieldsReady = fields.every((field) => {
            const value = String(field.value ?? '').trim();
            return value !== '' && field.checkValidity();
        });

        if (!allRequiredFieldsReady) {
            return false;
        }

        if (scheduleDate?.value && returnDate?.value) {
            const from = new Date(`${scheduleDate.value}T00:00:00`);
            const to = new Date(`${returnDate.value}T00:00:00`);
            if (Number.isNaN(from.getTime()) || Number.isNaN(to.getTime()) || to <= from) {
                return false;
            }
        }

        return true;
    }

    function isStageTwoReady() {
        const rows = getSelectedRows();
        if (rows.length === 0 || !availabilityLoaded) {
            return false;
        }

        if (isOffCampusMode()) {
            if (rows.length !== 1 || rows[0]?.dataset.offCampusAllowed !== '1') {
                return false;
            }
        }

        return rows.every((row) => {
            const itemId = row.dataset.selectedItem;
            const quantity = document.querySelector(`[data-selected-quantity="${itemId}"]`);
            const requested = Math.trunc(Number(quantity?.value || 0));
            const available = Math.max(0, Math.floor(Number(availability[itemId]?.available) || 0));
            return requested > 0 && available > 0 && requested <= available;
        });
    }

    function areRequiredDocumentsReady() {
        const requestLetterReady = Boolean(
            requestLetterAlreadyUploaded || requestLetterInput?.files?.length
        );
        const ptcRequired = Boolean(studentToggle?.checked);
        const ptcReady = Boolean(
            !ptcRequired || ptcAlreadyUploaded || ptcInput?.files?.length
        );

        return requestLetterReady && ptcReady;
    }

    function syncForwardActionStates() {
        if (stageOneNextButton) {
            stageOneNextButton.disabled = !isStageOneReady();
        }

        if (stageTwoNextButton) {
            stageTwoNextButton.disabled = !isStageTwoReady();
        }

        /*
         * FINAL E-SIGN / SUBMIT READINESS
         *
         * E-sign & Submit to SPMU stays DISABLED until all required
         * submission requirements are complete.
         *
         * Borrowing Request Letter = always required.
         * PTC = required only for Student Activity.
         * Save Draft is NOT affected by this rule.
         */

        const requestLetterReady = Boolean(
            requestLetterAlreadyUploaded ||
            requestLetterInput?.files?.length
        );

        const ptcRequired = Boolean(studentToggle?.checked);

        const ptcReady = Boolean(
            !ptcRequired ||
            ptcAlreadyUploaded ||
            ptcInput?.files?.length
        );

        const detailsReady = isStageOneReady();
        const itemsReady = isStageTwoReady();
        const acknowledgementReady = Boolean(finalConfirmation?.checked);

        const canSubmit =
            detailsReady &&
            itemsReady &&
            requestLetterReady &&
            ptcReady &&
            signatureReady &&
            acknowledgementReady;

        if (submitButton) {
            submitButton.disabled = !canSubmit;

            /*
             * Helpful explanation for why submission is unavailable.
             */
            if (!detailsReady) {
                submitButton.title =
                    'Complete all required Request Details first.';
            } else if (!itemsReady) {
                submitButton.title =
                    'Complete the item selection and valid quantities first.';
            } else if (!requestLetterReady) {
                submitButton.title =
                    'Upload the fully signed Borrowing Request Letter before submitting.';
            } else if (!ptcReady) {
                submitButton.title =
                    'Upload the Permission to Conduct Letter for this Student Activity before submitting.';
            } else if (!signatureReady) {
                submitButton.title =
                    'Register your E-signature in Account Settings before submitting.';
            } else if (!acknowledgementReady) {
                submitButton.title =
                    'Check the Acknowledgement before submitting.';
            } else {
                submitButton.title = '';
            }
        }
    }

    function syncRequestingUnitProfile() {
        if (!requestingUnitSelect || !divisionSelect || !officeInput) {
            return;
        }

        const option = requestingUnitSelect.tagName === 'SELECT'
            ? requestingUnitSelect.selectedOptions?.[0]
            : null;

        if (option) {
            const divisionCode = option.dataset.divisionCode || '';
            const divisionLabel = option.dataset.divisionLabel || divisionLabels[divisionCode] || '';
            const unitName = option.dataset.unitName || option.textContent?.trim() || '';

            divisionSelect.value = divisionCode;
            officeInput.value = unitName;

            if (divisionDisplay) {
                divisionDisplay.value = divisionLabel;
            }
        }

        updateReviewSummary();
        syncForwardActionStates();
    }

    function syncOfficeOptions() {
        /*
         * Division / office are no longer borrower-editable. Kept as a small
         * compatibility hook so older event calls do not break this page.
         */
        syncRequestingUnitProfile();
    }

    function formatDateLabel(value) {
        if (!value) {
            return '';
        }

        const date = new Date(`${value}T00:00:00`);
        if (Number.isNaN(date.getTime())) {
            return value;
        }

        return new Intl.DateTimeFormat('en-PH', {
            day: 'numeric',
            month: 'short',
            year: 'numeric'
        }).format(date);
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

        if (ptcDocumentBox) {
            ptcDocumentBox.hidden = !active;
        }
    }

    function getFilteredCatalog() {
        const query = (searchInput?.value || '').trim().toLowerCase();
        const offCampusMode = Boolean(requestOffCampusToggle?.checked);

        if (!query) {
            return [];
        }

        return catalogItems
            .filter((item) => (item.dataset.search || '').includes(query))
            .filter((item) => !offCampusMode || item.dataset.offCampusAllowed === '1')
            .slice(0, 8);
    }

    function renderCatalog() {
        const query = (searchInput?.value || '').trim();
        const filtered = getFilteredCatalog();

        catalogItems.forEach((item) => { item.hidden = true; });

        if (!query) {
            if (catalogShell) catalogShell.hidden = true;
            if (catalogEmpty) catalogEmpty.hidden = true;
            if (resultLabel) resultLabel.textContent = 'Recommended matches';
            syncCatalogButtons();
            return;
        }

        filtered.forEach((item) => { item.hidden = false; });

        if (catalogShell) catalogShell.hidden = false;
        if (catalogEmpty) catalogEmpty.hidden = filtered.length !== 0;

        if (resultLabel) {
            resultLabel.textContent = filtered.length
                ? `Recommended matches for “${query}”`
                : `No match for “${query}”`;
        }

        syncCatalogButtons();
    }

    function isSelected(itemId) {
        return Boolean(
            document.querySelector(`[data-selected-checkbox="${itemId}"]`)?.checked
        );
    }

    function getSelectedRows() {
        return Array.from(document.querySelectorAll('[data-selected-item]'))
            .filter((row) => {
                const itemId = row.dataset.selectedItem;
                return isSelected(itemId);
            });
    }

    function isOffCampusMode() {
        return Boolean(requestOffCampusToggle?.checked);
    }

    function syncRequestPremises() {
        const offCampusMode = isOffCampusMode();

        if (requestOnCampusToggle) requestOnCampusToggle.checked = !offCampusMode;

        document.querySelectorAll('[data-selected-location]').forEach((location) => {
            location.value = 'ON_CAMPUS';
        });

        if (offCampusMode) {
            getSelectedRows().forEach((row) => {
                const itemId = row.dataset.selectedItem;
                const location = document.querySelector(`[data-selected-location="${itemId}"]`);
                if (location) {
                    location.value = 'OFF_CAMPUS';
                }
            });
        }

        if (premisesHelp) {
            premisesHelp.textContent = offCampusMode
                ? 'Off-campus selected. A Gate Pass is required. Search and select Barricade only; it must be the only item in this request.'
                : 'Off-campus is available only for eligible items and automatically requires a Gate Pass after final approval.';
        }

        if (searchInput) {
            searchInput.placeholder = offCampusMode
                ? 'Search Barricade for off-campus borrowing...'
                : 'Start typing an item name, category, description, or unit...';
        }
    }

    function syncCatalogButtons() {
        const offCampusMode = isOffCampusMode();
        const selectedCountNow = getSelectedRows().length;

        document.querySelectorAll('[data-add-item]').forEach((button) => {
            const itemId = button.dataset.addItem;
            const added = isSelected(itemId);
            const available = Math.floor(Number(availability[itemId]?.available ?? 0));
            const catalogItem = document.querySelector(`[data-catalog-item][data-item-id="${itemId}"]`);
            const offCampusAllowed = catalogItem?.dataset.offCampusAllowed === '1';

            if (added) {
                button.textContent = 'Added';
                button.classList.add('is-added');
                button.disabled = true;
                button.title = '';
                return;
            }

            button.textContent = '+ Add';
            button.classList.remove('is-added');

            const modeBlocked = offCampusMode && (!offCampusAllowed || selectedCountNow >= 1);
            button.disabled =
                !availabilityLoaded
                || !scheduleDate?.value
                || !returnDate?.value
                || available <= 0
                || modeBlocked;

            button.title = modeBlocked
                ? 'Off-campus borrowing allows one Barricade item only.'
                : '';
        });
    }

    function updateCatalogAvailability() {
        document.querySelectorAll('[data-catalog-availability]').forEach((node) => {
            const itemId = node.dataset.catalogAvailability;
            const balance = availability[itemId];

            node.classList.remove('is-available', 'is-unavailable');

            if (!availabilityLoaded || !balance) {
                node.textContent = 'Select dates';
                return;
            }

            const available = Math.max(0, Math.floor(Number(balance.available) || 0));
            node.textContent = available > 0
                ? `${available} available`
                : 'Unavailable for selected dates';
            node.classList.add(available > 0 ? 'is-available' : 'is-unavailable');
        });

        syncCatalogButtons();
    }

    function normalizeQuantity(input) {
        if (!input) {
            return;
        }

        const raw = String(input.value ?? '').trim();
        if (raw === '') {
            return;
        }

        let value = Number(raw);
        if (!Number.isFinite(value)) {
            input.value = '0';
            return;
        }

        value = Math.max(0, Math.trunc(value));
        const max = Math.floor(Number(input.max));

        if (Number.isFinite(max) && max >= 0) {
            value = Math.min(value, max);
        }

        input.value = String(value);
    }

    function normalizeAllQuantities() {
        document.querySelectorAll('[data-selected-quantity]').forEach(normalizeQuantity);
    }

    function syncSelectedItems() {
        if (eSignatureConfirmation) {
            eSignatureConfirmation.value = finalConfirmation?.checked ? '1' : '0';
        }

        let count = 0;
        let conflict = false;

        document.querySelectorAll('[data-selected-item]').forEach((row) => {
            const itemId = row.dataset.selectedItem;
            const checkbox = document.querySelector(`[data-selected-checkbox="${itemId}"]`);
            const quantity = document.querySelector(`[data-selected-quantity="${itemId}"]`);
            const location = document.querySelector(`[data-selected-location="${itemId}"]`);
            const availabilityValue = document.querySelector(`[data-selected-availability="${itemId}"]`);
            const availabilityNote = document.querySelector(`[data-selected-availability-note="${itemId}"]`);
            const selected = Boolean(checkbox?.checked);

            row.hidden = !selected;
            if (!selected) {
                return;
            }

            count++;

            const balance = availability[itemId];
            if (!availabilityLoaded || !balance) {
                if (availabilityValue) availabilityValue.textContent = '-';
                if (availabilityNote) {
                    availabilityNote.textContent = 'Select dates';
                    availabilityNote.classList.remove('is-error');
                }
                return;
            }

            const available = Math.max(0, Math.floor(Number(balance.available) || 0));
            const requested = Math.trunc(Number(quantity?.value || 0));

            if (availabilityValue) {
                availabilityValue.textContent = String(available);
            }

            const invalid = available <= 0 || requested <= 0 || requested > available;

            if (availabilityNote) {
                availabilityNote.classList.toggle('is-error', invalid);
                if (available <= 0) {
                    availabilityNote.textContent = 'Unavailable for these dates';
                } else if (requested > available) {
                    availabilityNote.textContent = `Requested ${requested}; only ${available} available`;
                } else if (requested <= 0) {
                    availabilityNote.textContent = 'Enter a whole-number quantity greater than 0';
                } else {
                    availabilityNote.textContent = 'Available for selected dates';
                }
            }

            if (quantity && available > 0) {
                quantity.max = String(available);
            }

            if (invalid) {
                conflict = true;
            }
        });

        syncRequestPremises();

        const offCampusMode = isOffCampusMode();
        const selectedRows = getSelectedRows();
        const offCampusConflict = offCampusMode && (
            selectedRows.length > 1
            || selectedRows.some((row) => row.dataset.offCampusAllowed !== '1')
        );

        if (selectedEmpty) selectedEmpty.hidden = count !== 0;
        if (selectedItemsTableWrap) selectedItemsTableWrap.hidden = count === 0;
        if (selectedCount) selectedCount.textContent = String(count);
        if (offCampusModeNote) offCampusModeNote.hidden = !offCampusMode || offCampusConflict;
        if (campusModeConflict) campusModeConflict.hidden = !offCampusConflict;
        if (availabilityConflict) {
            availabilityConflict.hidden = !availabilityLoaded || !conflict;
        }

        syncForwardActionStates();
        syncCatalogButtons();

        if (activeStage === 3) {
            updateReviewSummary();
        }
    }

    function addItem(itemId) {
        const offCampusMode = isOffCampusMode();
        const catalogItem = document.querySelector(`[data-catalog-item][data-item-id="${itemId}"]`);
        const offCampusAllowed = catalogItem?.dataset.offCampusAllowed === '1';

        if (offCampusMode && (!offCampusAllowed || getSelectedRows().length >= 1)) {
            return;
        }

        const checkbox = document.querySelector(`[data-selected-checkbox="${itemId}"]`);
        const quantity = document.querySelector(`[data-selected-quantity="${itemId}"]`);

        if (!checkbox) {
            return;
        }

        const available = Math.floor(Number(availability[itemId]?.available ?? 0));
        if (!availabilityLoaded || available <= 0) {
            return;
        }

        checkbox.checked = true;

        const location = document.querySelector(`[data-selected-location="${itemId}"]`);
        if (location) {
            location.value = offCampusMode ? 'OFF_CAMPUS' : 'ON_CAMPUS';
        }

        if (quantity && Number(quantity.value || 0) <= 0) {
            quantity.value = '1';
        }

        syncSelectedItems();

        if (searchInput) {
            searchInput.value = '';
            renderCatalog();
            searchInput.focus();
        }
    }

    function removeItem(itemId) {
        const checkbox = document.querySelector(`[data-selected-checkbox="${itemId}"]`);
        const quantity = document.querySelector(`[data-selected-quantity="${itemId}"]`);
        const location = document.querySelector(`[data-selected-location="${itemId}"]`);

        if (checkbox) checkbox.checked = false;
        if (quantity) quantity.value = '0';
        if (location) location.value = 'ON_CAMPUS';

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
                    'Select Items Needed From and Expected Return Date to load availability.';
            }
            updateCatalogAvailability();
            syncSelectedItems();
            return;
        }

        clearTimeout(availabilityTimer);
        availabilityTimer = setTimeout(async () => {
            if (availabilityMessage) {
                availabilityMessage.textContent = 'Checking availability for the selected dates...';
            }

            try {
                const response = await fetch(
                    `{{ route('inventory.availability') }}?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`,
                    { headers: { Accept: 'application/json' } }
                );

                if (!response.ok) {
                    throw new Error('Availability request failed.');
                }

                availability = await response.json();
                availabilityLoaded = true;

                if (availabilityMessage) {
                    availabilityMessage.textContent =
                        'Availability is based on the complete selected borrowing period.';
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

    document.querySelectorAll('[data-stage-next]').forEach((button) => {
        button.addEventListener('click', () => {
            const next = Number(button.dataset.stageNext);

            if (activeStage === 1 && !validateStageOne()) {
                return;
            }

            if (activeStage === 2 && !validateStageTwo()) {
                return;
            }

            showStage(next);
        });
    });

    document.querySelectorAll('[data-stage-back]').forEach((button) => {
        button.addEventListener('click', () => {
            showStage(Number(button.dataset.stageBack));
        });
    });

    stageButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const target = Number(button.dataset.stageTarget);
            if (target <= furthestStage) {
                showStage(target);
            }
        });
    });

    requestingUnitSelect?.addEventListener('change', syncRequestingUnitProfile);
    studentToggle?.addEventListener('change', () => {
        syncStudentFields();
        syncForwardActionStates();
    });

    form?.addEventListener('input', () => {
        syncForwardActionStates();
        if (activeStage === 3) {
            updateReviewSummary();
        }
    });

    form?.addEventListener('change', () => {
        syncForwardActionStates();
        if (activeStage === 3) {
            updateReviewSummary();
        }
    });
    scheduleDate?.addEventListener('change', refreshAvailability);
    returnDate?.addEventListener('change', refreshAvailability);

    searchInput?.addEventListener('input', renderCatalog);

    searchInput?.addEventListener('focus', () => {
        if ((searchInput.value || '').trim()) {
            renderCatalog();
        }
    });

    document.querySelectorAll('[data-add-item]').forEach((button) => {
        button.addEventListener('click', () => addItem(button.dataset.addItem));
    });

    document.querySelectorAll('[data-remove-item]').forEach((button) => {
        button.addEventListener('click', () => removeItem(button.dataset.removeItem));
    });

    document.querySelectorAll('[data-selected-quantity]').forEach((input) => {
        input.addEventListener('keydown', (event) => {
            if (['.', ',', 'e', 'E', '+', '-'].includes(event.key)) {
                event.preventDefault();
            }
        });

        input.addEventListener('input', syncSelectedItems);
        input.addEventListener('change', () => {
            normalizeQuantity(input);
            syncSelectedItems();
        });
        input.addEventListener('blur', () => {
            normalizeQuantity(input);
            syncSelectedItems();
        });
    });

    function handlePremisesChange() {
        if (requestOffCampusToggle.checked) {
            const selectedRows = getSelectedRows();
            const needsReset = selectedRows.length > 1
                || selectedRows.some((row) => row.dataset.offCampusAllowed !== '1');

            if (needsReset) {
                const proceed = window.confirm(
                    'Switch to Off-campus borrowing? Current selected items will be removed because only Barricade may be borrowed off-campus.'
                );

                if (!proceed) {
                    requestOffCampusToggle.checked = false;
                    syncRequestPremises();
                    syncSelectedItems();
                    renderCatalog();
                    return;
                }

                getSelectedRows().forEach((row) => {
                    const itemId = row.dataset.selectedItem;
                    const checkbox = document.querySelector(`[data-selected-checkbox="${itemId}"]`);
                    const quantity = document.querySelector(`[data-selected-quantity="${itemId}"]`);
                    const location = document.querySelector(`[data-selected-location="${itemId}"]`);

                    if (checkbox) checkbox.checked = false;
                    if (quantity) quantity.value = '0';
                    if (location) location.value = 'ON_CAMPUS';
                });
            }
        }

        syncRequestPremises();
        syncSelectedItems();
        renderCatalog();
    }

    requestOffCampusToggle?.addEventListener('change', handlePremisesChange);
    requestOnCampusToggle?.addEventListener('change', handlePremisesChange);
    /*
     * PRE-CONFIRMATION CLICK
     * ----------------------
     * The visible E-sign button is intentionally type="button".
     * We run the existing submit validation path with the hidden submit trigger,
     * but temporarily opt this preflight submit out of the global duplicate-submit
     * lock. This lets the local handler validate the request and open the
     * confirmation modal WITHOUT changing the visible button to Processing...
     * and WITHOUT locking the form before the borrower confirms.
     */
    submitButton?.addEventListener('click', () => {
        if (!form || !submitTrigger || submitButton.disabled) return;

        submissionConfirmed = false;

        form.dataset.allowRepeatedSubmit = 'true';
        try {
            form.requestSubmit(submitTrigger);
        } finally {
            delete form.dataset.allowRepeatedSubmit;
        }
    });

    form?.addEventListener('submit', (event) => {
        if (activeStage !== 3) {
            event.preventDefault();
            return;
        }

        normalizeAllQuantities();
        syncSelectedItems();

        const intent = event.submitter?.value || 'draft';
        const blockedButton = intent === 'submit' ? submitButton : saveDraftButton;

        if (blockedButton?.disabled) {
            event.preventDefault();
            (campusModeConflict && !campusModeConflict.hidden
                ? campusModeConflict
                : availabilityConflict
            )?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        if (intent !== 'submit') {
            return;
        }

        const requestLetterInput = form.querySelector('[name="approved_request_letter"]');
        const ptcInput = form.querySelector('[name="permission_to_conduct_letter"]');
        const requestLetterReady = Boolean(
            requestLetterInput?.files?.length || {{ $requestLetter ? 'true' : 'false' }}
        );
        const ptcRequired = Boolean(studentToggle?.checked);
        const ptcReady = Boolean(
            !ptcRequired || ptcInput?.files?.length || {{ $ptc ? 'true' : 'false' }}
        );

        if (!requestLetterReady) {
            event.preventDefault();
            requestLetterInput?.setCustomValidity(
                'Upload the fully signed Borrowing Request Letter before submitting.'
            );
            requestLetterInput?.reportValidity();
            requestLetterInput?.setCustomValidity('');
            requestLetterInput?.focus();
            return;
        }

        if (!ptcReady) {
            event.preventDefault();
            ptcInput?.setCustomValidity(
                'Upload the Permission to Conduct Letter for this student activity before submitting.'
            );
            ptcInput?.reportValidity();
            ptcInput?.setCustomValidity('');
            ptcInput?.focus();
            return;
        }

        if (!signatureReady) {
            event.preventDefault();
            const signatureNotice = form.querySelector('.esignature-notice');
            signatureNotice?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            signatureNotice?.querySelector('a')?.focus();
            return;
        }

        if (!finalConfirmation?.checked) {
            event.preventDefault();
            if (finalConfirmationError) {
                finalConfirmationError.hidden = false;
            }
            finalConfirmation?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            finalConfirmation?.focus();
            return;
        }

        if (finalConfirmationError) {
            finalConfirmationError.hidden = true;
        }

        if (!submissionConfirmed) {
            event.preventDefault();
            if (eSignatureConfirmation) eSignatureConfirmation.value = '1';
            if (submitDialog) {
                if (confirmSubmitButton) confirmSubmitButton.disabled = false;
                submitDialog.hidden = false;
                document.body.classList.add('request-dialog-open');
                confirmSubmitButton?.focus();
            }
        }
    });

    function closeDialog(dialog) {
        if (!dialog) return;
        dialog.hidden = true;
        if (termsDialog?.hidden !== false && submitDialog?.hidden !== false) {
            document.body.classList.remove('request-dialog-open');
        }
    }

    document.querySelectorAll('[data-open-request-terms]').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            if (termsDialog) {
                termsDialog.hidden = false;
                document.body.classList.add('request-dialog-open');
                termsDialog.querySelector('[data-close-request-terms]')?.focus();
            }
        });
    });

    document.querySelectorAll('[data-close-request-terms]').forEach((button) => {
        button.addEventListener('click', () => closeDialog(termsDialog));
    });
    termsDialog?.addEventListener('click', (event) => {
        if (event.target === termsDialog) closeDialog(termsDialog);
    });
    cancelSubmitButton?.addEventListener('click', () => {
        submissionConfirmed = false;
        closeDialog(submitDialog);
        submitButton?.focus();
    });
    confirmSubmitButton?.addEventListener('click', () => {
        if (!form || !finalConfirmation?.checked) return;

        submissionConfirmed = true;
        if (eSignatureConfirmation) eSignatureConfirmation.value = '1';
        if (confirmSubmitButton) confirmSubmitButton.disabled = true;

        /*
         * Re-enter the same submit path using the visible submitter so the
         * submit intent stays "submit" and all existing backend validation,
         * E-sign snapshot handling, and duplicate-submission protection remain intact.
         *
         * submitButton must stay enabled until AFTER requestSubmit() dispatches
         * the form's submit event: that listener reads submitButton.disabled to
         * decide whether the "submit" intent is blocked, so disabling it first
         * caused this confirmed re-submission to be cancelled every time.
         */
        if (typeof form.requestSubmit === 'function' && submitTrigger) {
            form.requestSubmit(submitTrigger);
        } else {
            // Older-browser fallback. Backend validation remains authoritative.
            const fallbackIntent = document.createElement('input');
            fallbackIntent.type = 'hidden';
            fallbackIntent.name = 'intent';
            fallbackIntent.value = 'submit';
            form.appendChild(fallbackIntent);
            form.submit();
        }

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Processing...';
        }
    });
    submitDialog?.addEventListener('click', (event) => {
        if (event.target === submitDialog) {
            submissionConfirmed = false;
            closeDialog(submitDialog);
            submitButton?.focus();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        if (termsDialog && !termsDialog.hidden) {
            closeDialog(termsDialog);
            return;
        }
        if (submitDialog && !submitDialog.hidden) {
            submissionConfirmed = false;
            closeDialog(submitDialog);
            submitButton?.focus();
        }
    });

    finalConfirmation?.addEventListener('change', () => {
        if (finalConfirmationError) {
            finalConfirmationError.hidden = Boolean(finalConfirmation.checked);
        }
        if (eSignatureConfirmation) {
            eSignatureConfirmation.value = finalConfirmation.checked ? '1' : '0';
        }
        submissionConfirmed = false;
        syncSelectedItems();
    });

    syncRequestingUnitProfile();
    syncStudentFields();
    syncRequestPremises();
    syncDateContext();
    renderCatalog();
    syncSelectedItems();
    refreshAvailability();
    showStage(resumeStage, false);
    syncForwardActionStates();
});
</script>

@endsection

<!-- SPMU-QUANTITY-WHOLE-NUMBER-FIX:START -->

<style>
    /*
     * Quantity fields:
     * - whole numbers only
     * - remove browser number spinner arrows
     */

    input[type="number"][name^="quantities"]::-webkit-inner-spin-button,
    input[type="number"][name^="quantities"]::-webkit-outer-spin-button {
        -webkit-appearance: none !important;
        margin: 0 !important;
    }

    input[type="number"][name^="quantities"] {
        -moz-appearance: textfield !important;
        appearance: textfield !important;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const quantitySelector =
        'input[type="number"][name^="quantities"]';

    const isQuantityInput = (element) =>
        element instanceof HTMLInputElement &&
        element.matches(quantitySelector);

    /*
     * Make every current/future quantity input integer-only.
     *
     * Do NOT set min="1" here: unselected rows keep their template default
     * value of 0 while hidden, and forcing min="1" makes that 0 natively
     * invalid on a field the browser cannot focus (it's inside a hidden
     * row) — the browser then silently blocks the whole form submission
     * (native constraint validation fails before the JS submit handler
     * ever runs) any time not every catalog item is selected. Whole-number
     * / minimum-1 enforcement for an actually selected quantity is already
     * handled by the input/paste/change handlers below plus backend
     * validation, so no native min constraint is needed here.
     */
    const configureQuantityInput = (input) => {
        if (!isQuantityInput(input)) {
            return;
        }

        input.setAttribute('step', '1');
        input.setAttribute('inputmode', 'numeric');
    };

    document
        .querySelectorAll(quantitySelector)
        .forEach(configureQuantityInput);

    /*
     * Create Request can dynamically add selected-item rows,
     * so configure quantity fields added later as well.
     */
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (!(node instanceof HTMLElement)) {
                    return;
                }

                if (isQuantityInput(node)) {
                    configureQuantityInput(node);
                }

                node
                    .querySelectorAll?.(quantitySelector)
                    .forEach(configureQuantityInput);
            });
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

    /*
     * Prevent mouse wheel / touchpad scrolling from changing
     * the focused number input.
     */
    document.addEventListener(
        'wheel',
        (event) => {
            const input = event.target;

            if (!isQuantityInput(input)) {
                return;
            }

            event.preventDefault();
            input.blur();
        },
        {
            passive: false,
            capture: true
        }
    );

    /*
     * Prevent decimal, scientific notation, plus and minus.
     */
    document.addEventListener(
        'keydown',
        (event) => {
            const input = event.target;

            if (!isQuantityInput(input)) {
                return;
            }

            if (
                ['.', ',', 'e', 'E', '+', '-'].includes(event.key)
            ) {
                event.preventDefault();
            }
        },
        true
    );

    /*
     * Handle pasted values.
     *
     * Examples:
     *  5     -> 5
     *  3.7   -> 3
     *  -4    -> rejected / minimum 1
     *  abc   -> rejected
     */
    document.addEventListener(
        'paste',
        (event) => {
            const input = event.target;

            if (!isQuantityInput(input)) {
                return;
            }

            const pasted =
                event.clipboardData?.getData('text')?.trim() ?? '';

            if (pasted === '') {
                return;
            }

            const parsed = Number.parseInt(pasted, 10);

            event.preventDefault();

            if (!Number.isFinite(parsed) || parsed < 1) {
                input.value = '';
            } else {
                input.value = String(parsed);
            }

            input.dispatchEvent(
                new Event('input', {
                    bubbles: true
                })
            );

            input.dispatchEvent(
                new Event('change', {
                    bubbles: true
                })
            );
        },
        true
    );

    /*
     * Final safety normalization.
     */
    document.addEventListener(
        'change',
        (event) => {
            const input = event.target;

            if (!isQuantityInput(input)) {
                return;
            }

            if (input.value === '') {
                return;
            }

            const value = Number(input.value);

            if (!Number.isFinite(value) || value < 1) {
                input.value = '';
                return;
            }

            input.value = String(Math.trunc(value));
        },
        true
    );
});
</script>

<!-- SPMU-QUANTITY-WHOLE-NUMBER-FIX:END -->

<!-- SPMU-QUANTITY-SCROLL-LOCK:START -->

<style>
    /*
     * Hide the browser's own number-spinner controls.
     * Keep the custom minus / plus buttons unchanged.
     */
    input[type="number"][name^="quantities"]::-webkit-inner-spin-button,
    input[type="number"][name^="quantities"]::-webkit-outer-spin-button {
        -webkit-appearance: none !important;
        appearance: none !important;
        margin: 0 !important;
    }

    input[type="number"][name^="quantities"] {
        -moz-appearance: textfield !important;
    }
</style>

<script>
(() => {
    /*
     * Targets only the center quantity field.
     *
     * Custom - and + buttons are NOT affected.
     */
    const isQuantityField = (element) => {
        return element instanceof HTMLInputElement
            && element.matches(
                'input[name^="quantities"], ' +
                'input[data-quantity-input], ' +
                '.quantity-input'
            );
    };

    /*
     * BLOCK mouse-wheel / touchpad scrolling from modifying
     * the number while the center quantity field is focused.
     */
    document.addEventListener(
        'wheel',
        (event) => {
            if (!isQuantityField(event.target)) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
        },
        {
            capture: true,
            passive: false
        }
    );

    /*
     * BLOCK keyboard number stepping.
     *
     * ArrowUp / ArrowDown normally increment/decrement
     * <input type="number"> values.
     */
    document.addEventListener(
        'keydown',
        (event) => {
            if (!isQuantityField(event.target)) {
                return;
            }

            if (
                event.key === 'ArrowUp' ||
                event.key === 'ArrowDown'
            ) {
                event.preventDefault();
                return;
            }

            /*
             * Whole-number quantity only.
             */
            if (
                event.key === '.' ||
                event.key === ',' ||
                event.key === 'e' ||
                event.key === 'E' ||
                event.key === '+' ||
                event.key === '-'
            ) {
                event.preventDefault();
            }
        },
        true
    );

    /*
     * Normalize manually entered / pasted values.
     */
    document.addEventListener(
        'input',
        (event) => {
            const input = event.target;

            if (!isQuantityField(input)) {
                return;
            }

            if (input.value === '') {
                return;
            }

            /*
             * Strip anything except digits.
             */
            const clean = input.value.replace(/[^0-9]/g, '');

            if (input.value !== clean) {
                input.value = clean;
            }
        },
        true
    );
})();
</script>

<!-- SPMU-QUANTITY-SCROLL-LOCK:END -->
