<section class="mr-toolbar" aria-label="Borrowing request controls">
    <label class="mr-field">
        <span>Search requests</span>
        <span class="mr-field-shell">
            <span class="search-input-icon" aria-hidden="true">
                <x-icon name="search" size="17" />
            </span>
            <input
                id="request-search"
                type="search"
                placeholder="Request no. or purpose"
                autocomplete="off"
            >
        </span>
    </label>

    <label class="mr-field mr-field-status">
        <span>Status</span>
        <select id="request-status-filter">
            <option value="all">All requests</option>
            <option value="action">Action required</option>
            <option value="review">Under review</option>
            <option value="approved">Approved / release</option>
            <option value="custody">Released / on custody</option>
            <option value="completed">Completed</option>
            <option value="closed">Rejected / cancelled / inactive</option>
        </select>
    </label>
</section>
