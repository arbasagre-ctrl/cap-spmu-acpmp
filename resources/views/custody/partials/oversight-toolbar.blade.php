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
        Status
        <select id="custody-oversight-status">
            @foreach($oversightTabs as $key => $label)
                <option value="{{ $key }}">{{ $label }} ({{ $oversightCounts[$key] ?? 0 }})</option>
            @endforeach
        </select>
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

    <p
        id="custody-oversight-date-error"
        class="custody-oversight-date-error"
        role="alert"
        hidden
    >
        Date From cannot be later than Date To. Adjust either date to continue.
    </p>
</div>
