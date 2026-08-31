<nav class="custody-oversight-tabs" aria-label="Release and return status filters">
    @foreach($oversightTabs as $key => $label)
        <button
            class="custody-oversight-tab {{ $key === 'all' ? 'is-active' : '' }}"
            type="button"
            data-custody-tab="{{ $key }}"
            aria-pressed="{{ $key === 'all' ? 'true' : 'false' }}"
        >
            <span class="custody-oversight-tab-icon" aria-hidden="true">
                <x-icon :name="$oversightTabIcons[$key] ?? 'dashboard'" size="19" />
            </span>

            <span class="custody-oversight-tab-label">{{ $label }}</span>

            <span class="custody-oversight-tab-count">{{ $oversightCounts[$key] ?? 0 }}</span>
        </button>
    @endforeach
</nav>

<div class="custody-oversight-filters">
    <label class="custody-oversight-search">
        Search
        <span class="search-input-shell">
            <span class="search-input-icon" aria-hidden="true"><x-icon name="search" size="17" /></span>
            <input
                id="custody-oversight-search"
                type="search"
                placeholder="Search borrower, request no., custody no., or event..."
                autocomplete="off"
            >
        </span>
    </label>

    <label>
        Date from
        <input id="custody-oversight-from" type="date">
    </label>

    <label>
        Date to
        <input id="custody-oversight-to" type="date">
    </label>

    <label>
        Sort
        <select id="custody-oversight-sort">
            <option value="return-soonest">Return Date — Soonest</option>
            <option value="pickup-soonest">Pickup Date — Soonest</option>
            <option value="newest">Newest Transaction</option>
            <option value="oldest">Oldest Transaction</option>
        </select>
    </label>

    <button
        id="custody-oversight-clear"
        class="button secondary ui-pressable custody-oversight-clear"
        type="button"
    >
        Clear
    </button>

    <p
        id="custody-oversight-date-error"
        class="custody-oversight-date-error"
        role="alert"
        hidden
    >
        Date From cannot be later than Date To. Adjust the dates or use Clear.
    </p>
</div>
