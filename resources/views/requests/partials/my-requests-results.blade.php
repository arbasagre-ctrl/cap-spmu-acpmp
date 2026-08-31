<section id="my-requests-results" class="mr-results-panel" aria-label="Borrowing request results">
    <div class="mr-listing-bar">
        <p class="mr-result-count" data-request-count data-total="{{ $requests->total() }}" role="status" aria-live="polite">
            {{ $requests->total() }} {{ $requests->total() === 1 ? 'request found' : 'requests found' }}
        </p>

        <label class="mr-sort">
            <x-icon name="sort" size="19" />
            <span>Sort by:</span>
            <select id="request-sort" aria-label="Sort requests">
                <option value="newest">Newest first</option>
                <option value="oldest">Oldest first</option>
            </select>
            <x-icon name="chevron-down" class="mr-sort-chevron" size="17" />
        </label>
    </div>

    <div class="mr-list" id="borrower-request-list" aria-label="My borrowing requests">
        @foreach($requests as $request)
            @include('requests.partials.my-requests-row', ['request' => $request])
        @endforeach
    </div>

    <section
        id="request-filter-empty"
        class="mr-empty mr-filter-empty"
        hidden
    >
        @include('requests.partials.my-requests-empty-filtered')
    </section>

    <div class="mr-footer">
        <p role="status" aria-live="polite">
            Showing {{ $requests->firstItem() ?? 0 }} to {{ $requests->lastItem() ?? 0 }}
            of {{ $requests->total() }} {{ $requests->total() === 1 ? 'request' : 'requests' }}
        </p>

        {{ $requests->onEachSide(1)->links('requests.partials.my-requests-pagination') }}
    </div>
</section>
