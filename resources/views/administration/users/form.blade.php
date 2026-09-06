@extends('layouts.app', ['title' => $user->exists ? 'Edit User' : 'Create User'])
@section('content')
@php
    $isSelfProtected = auth()->check() && auth()->id() === $user->id;
@endphp

<section class="page-heading">
    <div>
        <p class="eyebrow">ICTU identity administration</p>
        <h1>{{ $user->exists ? 'Edit institutional account' : 'Register a CSPC account' }}</h1>
        <p>Only verified employees, faculty, and staff may be registered. The selected classification automatically controls borrowing eligibility and portal access.</p>
    </div>
</section>

<section class="content-area narrow">
    <form method="post" action="{{ $user->exists ? route('administration.users.update', $user) : route('administration.users.store') }}" class="card form-grid admin-form-grid">
        @csrf
        @if($user->exists)
            @method('PUT')
        @endif

        <fieldset>
            <legend>Account Identity</legend>
            <p class="field-note" style="margin: 0 0 14px;">{{ $user->exists ? 'Saved official account details are loaded automatically.' : 'Enter the employee\'s official CSPC account details.' }}</p>
            <div class="form-columns">
                <label>
                    Employee number
                    <input name="employee_no" value="{{ old('employee_no', $user->employee_no) }}" placeholder="Enter employee number" autocomplete="off" required>
                    @error('employee_no')
                        <small class="field-error">{{ $message }}</small>
                    @enderror
                </label>
                <label>
                    Full name
                    <input name="full_name" value="{{ old('full_name', $user->full_name) }}" placeholder="Enter full name" autocomplete="off" required>
                    @error('full_name')
                        <small class="field-error">{{ $message }}</small>
                    @enderror
                </label>
                <label>
                    Designation
                    <input name="designation" value="{{ old('designation', $user->designation) }}" placeholder="Enter official designation / position" autocomplete="off" required>
                    @error('designation')
                        <small class="field-error">{{ $message }}</small>
                    @enderror
                </label>
                <label>
                    Official CSPC email
                    <input
                        type="email"
                        name="email"
                        value="{{ old('email', $user->exists ? $user->email : '') }}"
                        placeholder="Enter official CSPC email"
                        autocomplete="off"
                        autocapitalize="none"
                        spellcheck="false"
                        data-lpignore="true"
                        data-1p-ignore="true"
                        @if(!$user->exists)
                            readonly
                            onfocus="this.removeAttribute('readonly')"
                            onpointerdown="this.removeAttribute('readonly')"
                        @endif
                        required
                    >
                    @error('email')
                        <small class="field-error">{{ $message }}</small>
                    @enderror
                </label>
            </div>
        </fieldset>

        <fieldset>
            <legend>Organizational Assignment</legend>

            @php
                $selectedUnitId = old('organizational_unit_id', $user->organizational_unit_id);
                $newUnitName = old('new_organizational_unit_name', '');
                $addingNewUnit = filled($newUnitName) || old('organizational_unit_id') === '__NEW__';
                $selectedAdditionalUnitIds = collect(
                    old('additional_organizational_unit_ids', $additionalUnitIds ?? [])
                )
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values();
            @endphp

            <div class="form-columns">
                <label>
                    Division
                    <select name="division_code" id="ictu-division" required>
                        <option value="">Select division</option>
                        @foreach($divisionOptions as $code => $label)
                            <option value="{{ $code }}" @selected(old('division_code', $selectedDivisionCode) === $code)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                    @error('division_code')
                        <small class="field-error">{{ $message }}</small>
                    @enderror
                </label>

                <label>
                    Office / Unit
                    <select name="organizational_unit_id" id="ictu-organizational-unit" required>
                        <option value="">Select Office / Unit</option>
                        @foreach($units as $unit)
                            @php
                                $unitDivision = $unit->divisionCode();
                            @endphp
                            @if(!$unitDivision)
                                @continue
                            @endif
                            <option
                                value="{{ $unit->id }}"
                                data-division="{{ $unitDivision }}"
                                @selected(!$addingNewUnit && (string) $selectedUnitId === (string) $unit->id)
                            >
                                {{ $unit->unit_name }}
                            </option>
                        @endforeach
                        <option value="__NEW__" data-new-unit @selected($addingNewUnit)>Other / Not listed</option>
                    </select>
                    @error('organizational_unit_id')
                        <small class="field-error">{{ $message }}</small>
                    @enderror
                </label>
            </div>

            <div class="admin-new-unit-field" id="ictu-new-unit-field" @if(!$addingNewUnit) hidden @endif>
                <label>
                    Add Office / Unit
                    <input
                        type="text"
                        name="new_organizational_unit_name"
                        id="ictu-new-unit-name"
                        value="{{ $newUnitName }}"
                        maxlength="255"
                        placeholder="Enter the official Office / Unit name"
                    >
                    <small class="field-note">The new Office / Unit will be saved under the selected Division.</small>
                    @error('new_organizational_unit_name')
                        <small class="field-error">{{ $message }}</small>
                    @enderror
                </label>
            </div>

            <details
                class="admin-additional-assignment"
                id="ictu-additional-assignment"
                @if(old('access_classification', $user->access_classification?->value ?? 'BORROWER_ONLY') !== 'BORROWER_ONLY') hidden @endif
            >
                <summary class="admin-additional-assignment-summary">
                    <strong>Additional Authorized Requesting Unit</strong>
                    <span class="field-note">Optional</span>
                </summary>

                <div class="admin-additional-assignment-content">
                    <div class="admin-additional-assignment-picker">
                    <select id="ictu-additional-unit-picker">
                        <option value="">Select another Office / Unit</option>
                        @foreach($divisionOptions as $additionalDivisionCode => $additionalDivisionLabel)
                            <optgroup label="{{ $additionalDivisionLabel }}">
                                @foreach($units as $additionalUnit)
                                    @if($additionalUnit->divisionCode() === $additionalDivisionCode)
                                        <option
                                            value="{{ $additionalUnit->id }}"
                                            data-unit-name="{{ $additionalUnit->unit_name }}"
                                            data-division-label="{{ $additionalDivisionLabel }}"
                                        >
                                            {{ $additionalUnit->unit_name }}
                                        </option>
                                    @endif
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <button class="button secondary" type="button" id="ictu-add-additional-unit">+ Add Unit</button>
                </div>

                <div class="admin-additional-unit-list" id="ictu-additional-unit-list">
                    @foreach($units as $additionalUnit)
                        @if($selectedAdditionalUnitIds->contains((int) $additionalUnit->id))
                            <div
                                class="admin-additional-unit-item"
                                data-additional-unit-item
                                data-unit-id="{{ $additionalUnit->id }}"
                            >
                                <div>
                                    <strong>{{ $additionalUnit->unit_name }}</strong>
                                    <small>{{ $additionalUnit->divisionLabel() }}</small>
                                </div>
                                <button type="button" class="button secondary" data-remove-additional-unit>Remove</button>
                                <input
                                    type="hidden"
                                    name="additional_organizational_unit_ids[]"
                                    value="{{ $additionalUnit->id }}"
                                >
                            </div>
                        @endif
                    @endforeach
                </div>

                @error('additional_organizational_unit_ids')
                    <small class="field-error">{{ $message }}</small>
                @enderror
                @error('additional_organizational_unit_ids.*')
                    <small class="field-error">{{ $message }}</small>
                @enderror
                </div>
            </details>

            <div class="form-columns">
                <label>
                    Personnel Type
                    <select name="employment_type" required>
                        <option value="">Select personnel type</option>
                        @foreach($employmentTypes as $type)
                            @php
                                $personnelTypeLabel = match ($type->value) {
                                    'FACULTY' => 'Faculty',
                                    'EMPLOYEE' => 'Employee',
                                    'STAFF' => 'Staff',
                                    default => $type->value,
                                };
                            @endphp
                            <option value="{{ $type->value }}" @selected(old('employment_type', $user->employment_type?->value) === $type->value)>
                                {{ $personnelTypeLabel }}
                            </option>
                        @endforeach
                    </select>
                    @error('employment_type')
                        <small class="field-error">{{ $message }}</small>
                    @enderror
                </label>

                <label>
                    Employment Status
                    <select name="employment_status" required>
                        <option value="">Select employment status</option>
                        <option value="FULL_TIME" @selected(old('employment_status', $user->employment_status) === 'FULL_TIME')>Full-time</option>
                        <option value="PART_TIME" @selected(old('employment_status', $user->employment_status) === 'PART_TIME')>Part-time</option>
                    </select>
                    @error('employment_status')
                        <small class="field-error">{{ $message }}</small>
                    @enderror
                </label>
            </div>
        </fieldset>

        <fieldset class="admin-classification-field"><legend>Classification / Role</legend>
            <div class="form-columns">
                <label>
                    Access classification
                    <select name="access_classification" required @disabled($isSelfProtected)>
                        @foreach($classifications as $classification)
                            <option value="{{ $classification->value }}" @selected(old('access_classification', $user->access_classification?->value ?? 'BORROWER_ONLY') === $classification->value)>
                                {{ $classification->label() }}
                            </option>
                        @endforeach
                    </select>

                    @if($isSelfProtected)
                        <input
                            type="hidden"
                            name="access_classification"
                            value="{{ $user->access_classification?->value }}"
                        >
                        <small class="field-note">
                            This field is protected for your own account.
                        </small>
                    @endif
                </label>
            </div>
        </fieldset>

        <fieldset class="admin-status-field"><legend>Account Status</legend>
            <div class="form-columns">
                <label>
                    Account status
                    <select name="account_status" required @disabled($isSelfProtected)>
                        @foreach($accountStatuses as $status)
                            @php
                                $accountStatusLabel = match ($status->value) {
                                    'ACTIVE' => 'Active',
                                    'INACTIVE' => 'Inactive',
                                    'SUSPENDED' => 'Suspended',
                                    default => $status->value,
                                };
                            @endphp
                            <option value="{{ $status->value }}" @selected(old('account_status', $user->account_status?->value ?? 'ACTIVE') === $status->value)>
                                {{ $accountStatusLabel }}
                            </option>
                        @endforeach
                    </select>

                    @if($isSelfProtected)
                        <input
                            type="hidden"
                            name="account_status"
                            value="{{ $user->account_status?->value }}"
                        >
                        <small class="field-note">
                            You cannot change your own active status from this screen.
                        </small>
                    @endif
                </label>
            </div>
        </fieldset>

        <fieldset class="admin-contact-field"><legend>Contact Information</legend>
            <div class="form-columns">
                <label>
                    Mobile number
                    <input name="mobile_no" value="{{ old('mobile_no', $user->mobile_no) }}">
                </label>
            </div>
        </fieldset>

        <fieldset>
            <legend>Administrative Actions</legend>
            <div class="form-columns">
                <label>
                    {{ $user->exists ? 'New password (leave blank to retain)' : 'Password' }}
                    <input type="password" name="password" @required(!$user->exists)>
                </label>
                <label>
                    Confirm password
                    <input type="password" name="password_confirmation" @required(!$user->exists)>
                </label>
            </div>
        </fieldset>

        <div class="actions admin-form-actions">
            <button class="button primary ui-pressable" type="submit">Save Changes</button>
            <a class="button secondary ui-pressable" href="{{ route('administration.users.index') }}">Cancel</a>
        </div>
    </form>
</section>

<style>
.admin-org-unit-field {
    min-width: 0;
}

.admin-org-unit-field > label {
    display: block;
    margin-bottom: 0.4rem;
}

.admin-org-unit-dropdown {
    position: relative;
    width: 100%;
    z-index: 30;
}

.admin-org-unit-trigger {
    width: 100%;
    min-height: 44px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 0.65rem 0.85rem;
    border: 1px solid var(--border-color, #cbd5e1);
    border-radius: 9px;
    background: var(--surface, #ffffff);
    color: inherit;
    font: inherit;
    text-align: left;
    cursor: pointer;
}

.admin-org-unit-trigger:hover {
    border-color: #8fb8ea;
}

.admin-org-unit-trigger:focus-visible,
.admin-org-unit-trigger[aria-expanded="true"] {
    outline: none;
    border-color: #2176d9;
    box-shadow: 0 0 0 3px rgba(33, 118, 217, 0.14);
}

.admin-org-unit-chevron {
    flex: 0 0 auto;
    font-size: 1rem;
    transition: transform 0.15s ease;
}

.admin-org-unit-trigger[aria-expanded="true"] .admin-org-unit-chevron {
    transform: rotate(180deg);
}

.admin-org-unit-menu {
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    right: 0;
    z-index: 1000;
    padding: 8px;
    border: 1px solid var(--border-color, #d7e0ea);
    border-radius: 10px;
    background: var(--surface, #ffffff);
    box-shadow: 0 14px 32px rgba(15, 42, 74, 0.16);
}

.admin-org-unit-search-wrap {
    padding-bottom: 7px;
}

.admin-org-unit-search {
    width: 100%;
    min-height: 40px;
    margin: 0;
}

.admin-org-unit-options {
    max-height: 260px;
    overflow-y: auto;
    overscroll-behavior: contain;
}

.admin-org-unit-option {
    display: block;
    width: 100%;
    padding: 9px 11px;
    border: 0;
    border-radius: 7px;
    background: transparent;
    color: inherit;
    font: inherit;
    text-align: left;
    cursor: pointer;
}

.admin-org-unit-option:hover,
.admin-org-unit-option:focus-visible {
    outline: none;
    background: rgba(33, 118, 217, 0.08);
}

.admin-org-unit-option[aria-selected="true"] {
    background: rgba(33, 118, 217, 0.12);
    font-weight: 700;
}

.admin-org-unit-empty {
    margin: 0;
    padding: 12px;
    opacity: 0.7;
    text-align: center;
}
</style>

{{-- ADMIN_USER_SINGLE_FIELD_LAYOUT --}}
<style>
.admin-form-grid .form-columns > :only-child {
    grid-column: 1 / -1;
}
</style>

{{-- ADMIN_USER_THREE_COLUMN_ROW_START --}}
<style>
/*
 * Desktop:
 * Access Classification | Account Status | Mobile Number
 * Mobile number intentionally gets the widest column.
 */
@media (min-width: 780px) {
    .admin-form-grid {
        grid-template-columns:
            minmax(0, 1fr)
            minmax(0, 1fr)
            minmax(0, 1.65fr);
        column-gap: 14px;
    }

    .admin-form-grid > fieldset {
        grid-column: 1 / -1;
    }

    .admin-form-grid > .admin-classification-field {
        grid-column: 1;
    }

    .admin-form-grid > .admin-status-field {
        grid-column: 2;
    }

    .admin-form-grid > .admin-contact-field {
        grid-column: 3;
    }

    .admin-form-grid > .admin-classification-field,
    .admin-form-grid > .admin-status-field,
    .admin-form-grid > .admin-contact-field {
        min-width: 0;
        margin: 0;
    }

    .admin-form-grid > .admin-classification-field .form-columns,
    .admin-form-grid > .admin-status-field .form-columns,
    .admin-form-grid > .admin-contact-field .form-columns {
        display: block;
    }

    .admin-form-grid > .admin-classification-field select,
    .admin-form-grid > .admin-status-field select,
    .admin-form-grid > .admin-contact-field input {
        width: 100%;
    }
}

/* Smaller screens stay readable and stack normally. */
@media (max-width: 779px) {
    .admin-form-grid > .admin-classification-field,
    .admin-form-grid > .admin-status-field,
    .admin-form-grid > .admin-contact-field {
        grid-column: 1 / -1;
    }
}
</style>
{{-- ADMIN_USER_THREE_COLUMN_ROW_END --}}

{{-- ADMIN_USER_ACTION_BUTTONS_LAYOUT --}}
<style>
.admin-form-grid .inline-actions,
.admin-form-grid .form-actions {
    display: flex;
    flex-direction: row;
    align-items: center;
    justify-content: flex-start;
    gap: 10px;
    flex-wrap: wrap;
}

.admin-form-grid .inline-actions .button,
.admin-form-grid .form-actions .button {
    width: auto;
    margin: 0;
}
</style>

{{-- ADMIN_USER_SAVE_BUTTON_HOVER --}}
<style>
.admin-form-grid button[type="submit"],
.admin-form-grid input[type="submit"] {
    transition:
        background-color .15s ease,
        border-color .15s ease,
        color .15s ease,
        box-shadow .15s ease;
}

.admin-form-grid button[type="submit"]:hover,
.admin-form-grid input[type="submit"]:hover {
    background: #1769E0 !important;
    border-color: #1769E0 !important;
    color: #ffffff !important;
    box-shadow: 0 4px 12px rgba(23, 105, 224, .20);
}

.admin-form-grid button[type="submit"]:focus-visible,
.admin-form-grid input[type="submit"]:focus-visible {
    outline: none;
    background: #1769E0 !important;
    border-color: #1769E0 !important;
    color: #ffffff !important;
    box-shadow: 0 0 0 3px rgba(23, 105, 224, .20);
}

.admin-form-grid button[type="submit"]:active,
.admin-form-grid input[type="submit"]:active {
    background: #1257BD !important;
    border-color: #1257BD !important;
    color: #ffffff !important;
}
</style>
<style>
.admin-new-unit-field {
    margin-top: 14px;
}
.admin-new-unit-field[hidden] {
    display: none !important;
}
</style>

<style>
.admin-additional-assignment {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--border-color, #d7e0ea);
}

.admin-additional-assignment-summary {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    list-style-position: outside;
    user-select: none;
}

.admin-additional-assignment-summary .field-note {
    font-weight: 400;
}

.admin-additional-assignment-content {
    margin-top: 14px;
}
.admin-additional-assignment[hidden] {
    display: none !important;
}
.admin-additional-assignment-heading {
    display: grid;
    gap: 4px;
    margin-bottom: 10px;
}
.admin-additional-assignment-heading > div {
    display: flex;
    align-items: baseline;
    gap: 8px;
    flex-wrap: wrap;
}
.admin-additional-assignment-picker {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 10px;
    align-items: end;
}
.admin-additional-assignment-picker .button {
    min-height: 48px;
    white-space: nowrap;
}
.admin-additional-unit-list {
    display: grid;
    gap: 8px;
    margin-top: 10px;
}
.admin-additional-unit-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 12px;
    border: 1px solid var(--border-color, #d7e0ea);
    border-radius: 10px;
    background: var(--surface-subtle, #f7f9fc);
}
.admin-additional-unit-item > div {
    min-width: 0;
    display: grid;
    gap: 2px;
}
.admin-additional-unit-item small {
    color: var(--text-muted, #60738a);
}
@media (max-width: 700px) {
    .admin-additional-assignment-picker {
        grid-template-columns: 1fr;
    }
    .admin-additional-assignment-picker .button {
        width: 100%;
    }
}
</style>


{{-- ADDITIONAL_AUTHORIZED_UNIT_CHEVRON_UI --}}
<style>
.admin-additional-assignment {
    margin-top: 18px !important;
    margin-bottom: 24px !important;
}

.admin-additional-assignment-summary {
    position: relative;
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 6px 38px 6px 0;
    cursor: pointer;
    list-style: none;
}

.admin-additional-assignment-summary::-webkit-details-marker {
    display: none;
}

.admin-additional-assignment-summary::marker {
    content: "";
}

.admin-additional-assignment-summary::after {
    content: "";
    position: absolute;
    right: 8px;
    top: 50%;
    width: 8px;
    height: 8px;
    border-right: 2px solid currentColor;
    border-bottom: 2px solid currentColor;
    transform: translateY(-65%) rotate(45deg);
    transition: transform .18s ease;
}

.admin-additional-assignment[open] > .admin-additional-assignment-summary::after {
    transform: translateY(-35%) rotate(225deg);
}

.admin-additional-assignment-content {
    margin-top: 16px;
}
</style>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const division = document.getElementById('ictu-division');
    const unit = document.getElementById('ictu-organizational-unit');
    const newUnitField = document.getElementById('ictu-new-unit-field');
    const newUnitName = document.getElementById('ictu-new-unit-name');
    const classification = document.querySelector('[name="access_classification"]');
    const additionalAssignment = document.getElementById('ictu-additional-assignment');
    const additionalPicker = document.getElementById('ictu-additional-unit-picker');
    const additionalList = document.getElementById('ictu-additional-unit-list');
    const addAdditionalButton = document.getElementById('ictu-add-additional-unit');

    if (!division || !unit) return;

    const syncUnits = () => {
        const selectedDivision = division.value;
        const currentValue = unit.value;

        Array.from(unit.options).forEach((option) => {
            if (!option.value || option.dataset.newUnit !== undefined) {
                option.hidden = false;
                option.disabled = false;
                return;
            }

            const matches = option.dataset.division === selectedDivision;
            option.hidden = !matches;
            option.disabled = !matches;
        });

        const selectedOption = unit.selectedOptions?.[0];
        if (selectedOption && selectedOption.disabled) {
            unit.value = '';
        } else if (currentValue && unit.value === '') {
            const matching = Array.from(unit.options).find(
                (option) => option.value === currentValue && !option.disabled
            );
            if (matching) unit.value = currentValue;
        }

        syncNewUnitField();
        syncAdditionalAssignments();
    };

    const syncNewUnitField = () => {
        const adding = unit.value === '__NEW__';
        if (newUnitField) newUnitField.hidden = !adding;
        if (newUnitName) newUnitName.required = adding;
    };

    const selectedAdditionalIds = () => Array.from(
        additionalList?.querySelectorAll('[data-additional-unit-item]') || []
    ).map((item) => String(item.dataset.unitId || ''));

    const removeAdditionalUnit = (item) => {
        item?.remove();
        syncAdditionalAssignments();
    };

    const syncAdditionalAssignments = () => {
        if (!additionalAssignment) return;

        const isBorrower = !classification || classification.value === 'BORROWER_ONLY';
        additionalAssignment.hidden = !isBorrower;

        if (!additionalPicker) return;

        const primaryId = /^\d+$/.test(unit.value) ? String(unit.value) : '';
        const selectedIds = selectedAdditionalIds();

        Array.from(additionalPicker.options).forEach((option) => {
            if (!option.value) return;
            option.disabled =
                option.value === primaryId
                || selectedIds.includes(String(option.value));
        });

        if (additionalPicker.selectedOptions?.[0]?.disabled) {
            additionalPicker.value = '';
        }

        Array.from(additionalList?.querySelectorAll('[data-additional-unit-item]') || [])
            .forEach((item) => {
                if (String(item.dataset.unitId || '') === primaryId) {
                    item.remove();
                }
            });
    };

    const addAdditionalUnit = () => {
        if (!additionalPicker || !additionalList || !additionalPicker.value) return;

        const selected = additionalPicker.selectedOptions?.[0];
        if (!selected || selected.disabled) return;

        const unitId = String(selected.value);
        const unitName = selected.dataset.unitName || selected.textContent?.trim() || '';
        const divisionLabel = selected.dataset.divisionLabel || selected.parentElement?.label || '';

        if (selectedAdditionalIds().includes(unitId)) return;

        const item = document.createElement('div');
        item.className = 'admin-additional-unit-item';
        item.dataset.additionalUnitItem = '';
        item.dataset.unitId = unitId;

        const text = document.createElement('div');
        const strong = document.createElement('strong');
        const small = document.createElement('small');
        strong.textContent = unitName;
        small.textContent = divisionLabel;
        text.append(strong, small);

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'button secondary';
        remove.dataset.removeAdditionalUnit = '';
        remove.textContent = 'Remove';
        remove.addEventListener('click', () => removeAdditionalUnit(item));

        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'additional_organizational_unit_ids[]';
        hidden.value = unitId;

        item.append(text, remove, hidden);
        additionalList.appendChild(item);

        additionalPicker.value = '';
        syncAdditionalAssignments();
    };

    additionalList?.querySelectorAll('[data-remove-additional-unit]')
        .forEach((button) => {
            button.addEventListener('click', () => {
                removeAdditionalUnit(button.closest('[data-additional-unit-item]'));
            });
        });

    division.addEventListener('change', syncUnits);
    unit.addEventListener('change', () => {
        syncNewUnitField();
        syncAdditionalAssignments();
    });
    classification?.addEventListener('change', syncAdditionalAssignments);
    addAdditionalButton?.addEventListener('click', addAdditionalUnit);
    syncUnits();
});
</script>

@endsection
