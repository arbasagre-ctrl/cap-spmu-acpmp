@extends('layouts.app', ['title' => 'User Administration'])
@section('content')
@php
    $classificationOptions = $users
        ->map(fn ($user) => $user->access_classification?->label())
        ->filter()
        ->unique()
        ->sort()
        ->values();

    $statusOptions = $users
        ->map(fn ($user) => $user->account_status?->value ?? 'ACTIVE')
        ->filter()
        ->unique()
        ->sort()
        ->values();
@endphp

<section class="page-heading ictu-users-heading">
    <div>
        <p class="eyebrow">ICTU identity administration</p>
        <h1>Institutional user accounts</h1>
        <p>Manage official CSPC identities, organizational assignments, access, and account status.</p>
    </div>

    @if(Route::has('administration.users.create'))
        <a class="button primary ui-pressable" href="{{ route('administration.users.create') }}">
            Register account
        </a>
    @endif
</section>

<section class="content-area ictu-users-area">
    <div class="card ictu-users-toolbar" aria-label="User account filters">
        <div class="ictu-users-search">
            <label for="ictu-user-search">Search</label>
            <input
                id="ictu-user-search"
                type="search"
                placeholder="Search name, employee no., email, office..."
                autocomplete="off"
            >
        </div>

        <div>
            <label for="ictu-user-classification-filter">Access</label>
            <select id="ictu-user-classification-filter">
                <option value="ALL">All access</option>
                @foreach($classificationOptions as $classificationOption)
                    <option value="{{ $classificationOption }}">{{ $classificationOption }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="ictu-user-status-filter">Status</label>
            <select id="ictu-user-status-filter">
                <option value="ALL">All statuses</option>
                @foreach($statusOptions as $statusOption)
                    <option value="{{ $statusOption }}">
                        {{ ucwords(strtolower(str_replace('_', ' ', $statusOption))) }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="ictu-user-restriction-filter">Borrowing</label>
            <select id="ictu-user-restriction-filter">
                <option value="ALL">All</option>
                <option value="CLEAR">Not restricted</option>
                <option value="RESTRICTED">Restricted</option>
            </select>
        </div>

        <button class="button secondary ictu-users-reset" type="button" id="ictu-user-reset">
            Reset
        </button>
    </div>

    <div class="ictu-users-summary">
        <span id="ictu-user-result-count">
            {{ $users->count() }} account{{ $users->count() === 1 ? '' : 's' }}
        </span>
    </div>

    <div class="table-wrap ictu-users-table-wrap">
        <table class="admin-user-table ictu-users-table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Employee No.</th>
                    <th>Office / Unit</th>
                    <th>Access</th>
                    <th>Status</th>
                    <th class="ictu-users-action-heading">Action</th>
                </tr>
            </thead>

            <tbody>
                @forelse($users as $user)
                    @php
                        $classificationLabel = $user->access_classification?->label() ?? 'Not assigned';
                        $accountStatus = $user->account_status?->value ?? 'ACTIVE';
                        $isRestricted = $user->activeRestrictions()->exists();

                        $personnelType = match ($user->employment_type?->value) {
                            'FACULTY' => 'Faculty',
                            'EMPLOYEE' => 'Employee',
                            'STAFF' => 'Staff',
                            default => null,
                        };

                        $employmentStatus = match ($user->employment_status ?? null) {
                            'FULL_TIME' => 'Full-time',
                            'PART_TIME' => 'Part-time',
                            default => null,
                        };

                        $searchText = strtolower(implode(' ', array_filter([
                            $user->full_name,
                            $user->designation,
                            $user->employee_no,
                            $user->email,
                            $user->organizationalUnit?->unit_name,
                            $user->organizationalUnit?->divisionLabel(),
                            $classificationLabel,
                            $personnelType,
                            $employmentStatus,
                        ])));
                    @endphp

                    <tr
                        data-ictu-user-row
                        data-user-search="{{ $searchText }}"
                        data-user-classification="{{ $classificationLabel }}"
                        data-user-status="{{ $accountStatus }}"
                        data-user-restriction="{{ $isRestricted ? 'RESTRICTED' : 'CLEAR' }}"
                    >
                        <td class="ictu-user-identity-cell">
                            <strong>{{ $user->full_name }}</strong>
                            <span>{{ $user->designation ?: 'No designation recorded' }}</span>
                            <a href="mailto:{{ $user->email }}">{{ $user->email }}</a>
                        </td>

                        <td class="ictu-user-number-cell">
                            {{ $user->employee_no ?: 'Not recorded' }}
                        </td>

                        <td class="ictu-user-unit-cell">
                            <strong>{{ $user->organizationalUnit?->unit_name ?: 'No assignment' }}</strong>
                            @if($user->organizationalUnit?->divisionLabel())
                                <span>{{ $user->organizationalUnit->divisionLabel() }}</span>
                            @endif
                        </td>

                        <td class="ictu-user-access-cell">
                            <strong>{{ $classificationLabel }}</strong>

                            @if($personnelType || $employmentStatus)
                                <span>
                                    {{ collect([$personnelType, $employmentStatus])->filter()->join(' • ') }}
                                </span>
                            @endif
                        </td>

                        <td class="ictu-user-status-cell">
                            <x-status-badge :status="$user->account_status ?? 'ACTIVE'" />

                            @if($isRestricted)
                                <x-status-badge status="BORROWING_RESTRICTED" />
                            @else
                                <span class="status-badge status-neutral">Not restricted</span>
                            @endif
                        </td>

                        <td class="actions-cell ictu-user-action-cell">
                            <a
                                class="button secondary ictu-user-manage-button"
                                href="{{ route('administration.users.edit', $user) }}"
                            >
                                Manage
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">No institutional accounts have been registered.</div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($users->isNotEmpty())
        <div class="card empty-state ictu-users-no-results" id="ictu-user-no-results" hidden>
            No accounts match the selected filters.
        </div>
    @endif
</section>

<style>
.ictu-users-heading {
    align-items: flex-end;
}

.ictu-users-area {
    display: grid;
    gap: 14px;
}

.ictu-users-toolbar {
    display: grid;
    grid-template-columns: minmax(320px, 1.6fr) repeat(3, minmax(150px, .7fr)) auto;
    gap: 12px;
    align-items: end;
    padding: 16px;
}

.ictu-users-toolbar label {
    display: block;
    margin-bottom: 6px;
    color: var(--text-muted, #5f738b);
    font-size: .78rem;
    font-weight: 800;
}

.ictu-users-toolbar input,
.ictu-users-toolbar select {
    width: 100%;
    min-height: 44px;
}

.ictu-users-reset {
    min-height: 44px;
    white-space: nowrap;
}

.ictu-users-summary {
    color: var(--text-muted, #60738a);
    font-size: .86rem;
}

.ictu-users-table-wrap {
    overflow-x: auto;
}

.ictu-users-table {
    width: 100%;
    min-width: 1020px;
    table-layout: fixed;
}

.ictu-users-table th,
.ictu-users-table td {
    vertical-align: middle;
}

.ictu-users-table th:nth-child(1) { width: 25%; }
.ictu-users-table th:nth-child(2) { width: 14%; }
.ictu-users-table th:nth-child(3) { width: 23%; }
.ictu-users-table th:nth-child(4) { width: 15%; }
.ictu-users-table th:nth-child(5) { width: 15%; }
.ictu-users-table th:nth-child(6) { width: 8%; }

.ictu-user-identity-cell,
.ictu-user-unit-cell,
.ictu-user-access-cell {
    min-width: 0;
}

.ictu-user-identity-cell > strong,
.ictu-user-unit-cell > strong,
.ictu-user-access-cell > strong {
    display: block;
    line-height: 1.35;
}

.ictu-user-identity-cell > span,
.ictu-user-unit-cell > span,
.ictu-user-access-cell > span {
    display: block;
    margin-top: 3px;
    color: var(--text-muted, #60738a);
    font-size: .8rem;
    line-height: 1.35;
}

.ictu-user-identity-cell > a {
    display: block;
    width: fit-content;
    max-width: 100%;
    margin-top: 4px;
    color: var(--text-muted, #60738a);
    font-size: .8rem;
    text-decoration: none;
    overflow-wrap: anywhere;
}

.ictu-user-identity-cell > a:hover {
    color: var(--interactive, #1769e0);
    text-decoration: underline;
}

.ictu-user-number-cell {
    white-space: nowrap;
}

.ictu-user-status-cell {
    display: flex;
    align-items: flex-start;
    gap: 6px;
    flex-wrap: wrap;
}

.ictu-users-action-heading,
.ictu-user-action-cell {
    text-align: right;
}

.ictu-user-manage-button {
    min-height: 38px;
    padding: 8px 13px;
    white-space: nowrap;
}

.ictu-users-no-results {
    text-align: center;
}

@media (max-width: 1180px) {
    .ictu-users-toolbar {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .ictu-users-search {
        grid-column: 1 / -1;
    }

    .ictu-users-reset {
        width: 100%;
    }
}

@media (max-width: 700px) {
    .ictu-users-heading {
        align-items: stretch;
    }

    .ictu-users-heading .button {
        width: 100%;
    }

    .ictu-users-toolbar {
        grid-template-columns: 1fr;
    }

    .ictu-users-search {
        grid-column: auto;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const search = document.getElementById('ictu-user-search');
    const classification = document.getElementById('ictu-user-classification-filter');
    const status = document.getElementById('ictu-user-status-filter');
    const restriction = document.getElementById('ictu-user-restriction-filter');
    const reset = document.getElementById('ictu-user-reset');
    const resultCount = document.getElementById('ictu-user-result-count');
    const noResults = document.getElementById('ictu-user-no-results');

    const rows = Array.from(document.querySelectorAll('[data-ictu-user-row]'));

    const applyFilters = () => {
        const query = (search?.value || '').trim().toLowerCase();
        const selectedClassification = classification?.value || 'ALL';
        const selectedStatus = status?.value || 'ALL';
        const selectedRestriction = restriction?.value || 'ALL';

        let visible = 0;

        rows.forEach((row) => {
            const matchesSearch =
                !query || (row.dataset.userSearch || '').includes(query);

            const matchesClassification =
                selectedClassification === 'ALL'
                || row.dataset.userClassification === selectedClassification;

            const matchesStatus =
                selectedStatus === 'ALL'
                || row.dataset.userStatus === selectedStatus;

            const matchesRestriction =
                selectedRestriction === 'ALL'
                || row.dataset.userRestriction === selectedRestriction;

            const show =
                matchesSearch
                && matchesClassification
                && matchesStatus
                && matchesRestriction;

            row.hidden = !show;

            if (show) visible += 1;
        });

        if (resultCount) {
            resultCount.textContent =
                `${visible} account${visible === 1 ? '' : 's'}`;
        }

        if (noResults) {
            noResults.hidden = visible !== 0;
        }
    };

    [search, classification, status, restriction].forEach((control) => {
        control?.addEventListener(
            control === search ? 'input' : 'change',
            applyFilters
        );
    });

    reset?.addEventListener('click', () => {
        if (search) search.value = '';
        if (classification) classification.value = 'ALL';
        if (status) status.value = 'ALL';
        if (restriction) restriction.value = 'ALL';

        applyFilters();
        search?.focus();
    });

    applyFilters();
});
</script>
@endsection
