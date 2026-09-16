<section class="universal-record-toolbar mr-toolbar" aria-label="Borrowing request controls">
    <label class="universal-record-search">
        <span>Search</span>
        <span class="search-input-shell">
            <span class="search-input-icon" aria-hidden="true">
                <x-icon name="search" size="17" />
            </span>
            <input
                id="request-search"
                type="search"
                placeholder="Search request no. or purpose..."
                autocomplete="off"
            >
        </span>
    </label>

    <label>
        <span>Status</span>
        <select id="request-status-filter">
            <option value="all">All statuses</option>
            <option value="action">Action required</option>
            <option value="review">Under review</option>
            <option value="approved">Approved / release</option>
            <option value="custody">Released / on custody</option>
            <option value="attention">Needs attention / accountability</option>
            <option value="completed">Completed</option>
            <option value="closed">Rejected / cancelled / inactive</option>
        </select>
    </label>

    <label>
        <span>Sort</span>
        <select id="request-sort" aria-label="Sort requests">
            <option value="newest">Newest first</option>
            <option value="oldest">Oldest first</option>
        </select>
    </label>
</section>
