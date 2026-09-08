<section class="request-card request-details-card" data-stage-panel="1" aria-labelledby="request-details-heading">
    <div class="request-card-header">
        <div>
            <p class="eyebrow">Request details</p>
            <h2 id="request-details-heading">Borrowing information</h2>
        </div>
        <span class="visually-hidden" id="inventory-date-context">Select dates</span>
    </div>

    <div class="request-card-body request-information-fields">
        <div class="field-grid">
            <label>
                Purpose of Borrowing
                <input
                    name="purpose_event"
                    value="{{ old('purpose_event', $version->purpose_event) }}"
                    maxlength="255"
                    required
                    placeholder="Enter the purpose of borrowing."
                >
                @error('purpose_event')
                    <small class="field-error">{{ $message }}</small>
                @enderror
            </label>

            <label>
                Event Location
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

            @php
                $requestingUnitOptions = $requestingUnitOptions ?? [];
                $hasMultipleRequestingUnits = count($requestingUnitOptions) > 1;
                $effectiveRequestingUnitId = (int) old(
                    'requesting_organizational_unit_id',
                    $prefillRequestingUnitId ?? ($borrowingRequest->accountable_unit_id ?? 0)
                );
                $selectedRequestingOption = collect($requestingUnitOptions)
                    ->firstWhere('id', $effectiveRequestingUnitId)
                    ?? ($requestingUnitOptions[0] ?? null);
                $effectiveDivisionCode = $selectedRequestingOption['division_code'] ?? '';
                $effectiveOfficeUnit = $selectedRequestingOption['name'] ?? '';
            @endphp

            <label>
                Division
                <input
                    id="division-display"
                    value="{{ $selectedRequestingOption['division_label'] ?? '' }}"
                    readonly
                    tabindex="-1"
                    aria-readonly="true"
                    placeholder="No division assigned"
                >
                <input id="division_code" type="hidden" name="division_code" value="{{ $effectiveDivisionCode }}">
            </label>

            <label>
                Requesting Office / Unit
                @if($hasMultipleRequestingUnits)
                    <select
                        id="requesting_organizational_unit_id"
                        name="requesting_organizational_unit_id"
                        required
                        aria-describedby="requesting-unit-help"
                    >
                        @foreach($requestingUnitOptions as $option)
                            <option
                                value="{{ $option['id'] }}"
                                data-division-code="{{ $option['division_code'] }}"
                                data-division-label="{{ $option['division_label'] }}"
                                data-unit-name="{{ $option['name'] }}"
                                @selected((int) ($selectedRequestingOption['id'] ?? 0) === (int) $option['id'])
                            >
                                {{ $option['name'] }}{{ $option['is_primary'] ? ' — Primary' : '' }}
                            </option>
                        @endforeach
                    </select>
                    <small class="field-help" id="requesting-unit-help">
                        Select the Office / Unit you are officially representing for this request.
                    </small>
                @elseif(count($requestingUnitOptions) === 1)
                    <input
                        id="requesting-unit-display"
                        value="{{ $requestingUnitOptions[0]['name'] }}"
                        readonly
                        tabindex="-1"
                        aria-readonly="true"
                    >
                    <input
                        id="requesting_organizational_unit_id"
                        type="hidden"
                        name="requesting_organizational_unit_id"
                        value="{{ $requestingUnitOptions[0]['id'] }}"
                        required
                    >
                @else
                    <input
                        id="requesting-unit-display"
                        value=""
                        readonly
                        tabindex="-1"
                        aria-readonly="true"
                        placeholder="No authorized Office / Unit"
                    >
                    <input
                        id="requesting_organizational_unit_id"
                        type="hidden"
                        name="requesting_organizational_unit_id"
                        value=""
                        required
                    >
                    <small class="field-error">No borrowing Office / Unit is authorized for this account. Contact ICTU.</small>
                @endif

                <input id="office_unit" type="hidden" name="office_unit" value="{{ $effectiveOfficeUnit }}">
                @error('requesting_organizational_unit_id')
                    <small class="field-error">{{ $message }}</small>
                @enderror
            </label>
        </div>
    </div>

    <div class="request-schedule-fields" aria-labelledby="borrowing-schedule-heading">
        <div class="request-schedule-heading">
            <h3 class="eyebrow" id="borrowing-schedule-heading">Borrowing schedule</h3>
            <p class="request-schedule-note">
                Borrowers are encouraged to submit requests in advance to allow sufficient time for verification, approval, preparation, and release.
            </p>
        </div>

        <div class="field-grid request-schedule-grid">
            <label>
                Items Needed From
                <input
                    id="schedule_date"
                    type="date"
                    name="schedule_date"
                    value="{{ old('schedule_date', optional($version->schedule_date ?: $version->needed_from)->format('Y-m-d')) }}"
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
                    value="{{ old('return_date', optional($version->return_date ?: $version->return_due_at)->format('Y-m-d')) }}"
                    required
                >
                @error('return_date')
                    <small class="field-error">{{ $message }}</small>
                @enderror
            </label>
        </div>

        <small class="field-help request-schedule-help">
            Please indicate the date by which you need to already have the requested item(s) in your custody for the intended activity. SPMU will arrange the appropriate pickup and release schedule after approval.
        </small>
    </div>
<div class="student-activity-panel">
        <input type="hidden" name="represents_student_activity" value="0">
        <label class="checkbox" for="student-activity-toggle">
            <input
                id="student-activity-toggle"
                type="checkbox"
                name="represents_student_activity"
                value="1"
                aria-describedby="student-activity-help"
                @checked(old('represents_student_activity', $version->represents_student_activity))
            >
            <span>
                <strong>This request represents a student activity</strong>
                <small id="student-activity-help">Permission to Conduct may be required during Documents &amp; Review.</small>
            </span>
        </label>
    </div>

    <div class="stage-actions request-details-actions" data-stage-panel="1">
        <a class="button secondary ui-pressable" href="{{ route('requests.index') }}">Cancel</a>
        <button type="button" class="button primary ui-pressable" data-stage-next="2" disabled>
            Continue to Select Items
            <svg class="ui-icon request-continue-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h16m-6-6 6 6-6 6" /></svg>
        </button>
    </div>
</section>


<script data-authorized-unit-sync>
document.addEventListener('DOMContentLoaded', () => {
    const unitSelect = document.getElementById('requesting_organizational_unit_id');
    if (!(unitSelect instanceof HTMLSelectElement)) return;

    const divisionDisplay = document.getElementById('division-display');
    const divisionCode = document.getElementById('division_code');
    const officeUnit = document.getElementById('office_unit');

    const syncAuthorizedUnit = () => {
        const option = unitSelect.selectedOptions?.[0];
        if (!option) return;

        const code = option.dataset.divisionCode || '';
        const label = option.dataset.divisionLabel || '';
        const name = option.dataset.unitName || option.textContent?.replace(/\s+—\s+Primary\s*$/, '').trim() || '';

        if (divisionDisplay) divisionDisplay.value = label;
        if (divisionCode) divisionCode.value = code;
        if (officeUnit) officeUnit.value = name;
    };

    unitSelect.addEventListener('change', syncAuthorizedUnit);
    syncAuthorizedUnit();
});
</script>
