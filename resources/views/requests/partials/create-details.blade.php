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

            <label>
                Division
                <select id="division_code" name="division_code" required>
                    <option value="">Select division</option>
                    @foreach($divisionOptions as $code => $label)
                        <option value="{{ $code }}" @selected($selectedDivision === $code)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
                @error('division_code')
                    <small class="field-error">{{ $message }}</small>
                @enderror
            </label>

            <label>
                Office / Academic Unit / Research Unit
                <input
                    id="office_unit"
                    name="office_unit"
                    list="office-unit-options"
                    value="{{ $selectedOfficeUnit }}"
                    maxlength="255"
                    required
                    autocomplete="off"
                    placeholder="Select or search the unit"
                >
                <datalist id="office-unit-options"></datalist>
                @error('office_unit')
                    <small class="field-error">{{ $message }}</small>
                @enderror
            </label>
        </div>
    </div>
    <div class="request-schedule-fields" aria-labelledby="borrowing-schedule-heading">
        <h3 class="eyebrow" id="borrowing-schedule-heading">Borrowing schedule</h3>
        <div class="field-grid">
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
        <button type="button" class="button primary ui-pressable" data-stage-next="2">
            Continue to Select Items
            <svg class="ui-icon request-continue-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h16m-6-6 6 6-6 6" /></svg>
        </button>
    </div>
</section>
