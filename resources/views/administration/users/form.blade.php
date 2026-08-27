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
            <div class="form-columns">
                <label>
                    Employee number
                    <input name="employee_no" value="{{ old('employee_no', $user->employee_no) }}" required>
                </label>
                <label>
                    Full name
                    <input name="full_name" value="{{ old('full_name', $user->full_name) }}" required>
                </label>
                <label>
                    Designation
                    <input name="designation" value="{{ old('designation', $user->designation) }}">
                </label>
                <label>
                    Official CSPC email
                    <input type="email" name="email" value="{{ old('email', $user->email) }}" required>
                </label>
            </div>
        </fieldset>

        <fieldset>
            <legend>Organizational Assignment</legend>
            <div class="form-columns">
                <label>
                    Office / Department
                    <select name="organizational_unit_id" required>
                        @foreach($units as $unit)
                            @continue(
                                $unit->unit_code === 'LAUNDRY'
                                || strcasecmp(trim($unit->unit_name), 'Laundry Service Area') === 0
                            )

                            <option
                                value="{{ $unit->id }}"
                                @selected(old('organizational_unit_id', $user->organizational_unit_id) == $unit->id)
                            >
                                {{ $unit->unit_name }}
                            </option>
                        @endforeach
                    </select>

                    @error('organizational_unit_id')
                        <small class="field-error">{{ $message }}</small>
                    @enderror
                </label>
<label>
                    Employment eligibility
                    <select name="employment_type" required>
                        @foreach($employmentTypes as $type)
                            <option value="{{ $type->value }}" @selected(old('employment_type', $user->employment_type?->value) === $type->value)>
                                {{ $type->value }}
                            </option>
                        @endforeach
                    </select>
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
                        <small class="field-note">This field is protected for your own account.</small>
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
                        <small class="field-note">You cannot change your own active status from this screen.</small>
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
@endsection
