@extends('layouts.app', ['title' => 'Completed Laundry'])
@section('content')
@include('laundry.partials.completed-styles')

<div class="completed-laundry" data-completed-laundry>
    <section class="page-heading completed-laundry-heading">
        <div>
            <p class="eyebrow">Laundry Operations</p>
            <h1>Completed Laundry</h1>
            <p>Review completed laundry cases and their final disposition.</p>
        </div>
        @include('laundry.partials.completed-back-link')
    </section>

    @if($jobs->total() === 0)
        <section class="card completed-laundry-empty" aria-labelledby="completed-laundry-empty-title">
            <div class="completed-laundry-empty-content">
                @include('laundry.partials.completed-empty-illustration')
                <h2 id="completed-laundry-empty-title">No completed laundry cases yet.</h2>
                <p>Completed laundry cases will appear here after processing is finalized.</p>
                @include('laundry.partials.completed-back-link')
            </div>
        </section>
    @else
        <section class="card completed-laundry-filter-card" aria-label="Completed laundry filters">
            <label>
                <span>Search</span>
                <span class="search-input-shell">
                    <span class="search-input-icon" aria-hidden="true"><x-icon name="search" size="18" /></span>
                    <input type="search" placeholder="Search case, borrower, or items..." data-completed-search autocomplete="off">
                </span>
            </label>
            <label>
                <span>Outcome</span>
                <select data-completed-outcome>
                    <option value="">All outcomes</option>
                    <option value="available">Available</option>
                    <option value="accountability">Accountability required</option>
                </select>
            </label>
            <label>
                <span>Sort</span>
                <select data-completed-sort>
                    <option value="newest">Newest first</option>
                    <option value="oldest">Oldest first</option>
                </select>
            </label>
            @if($jobs->hasPages())
                <p class="completed-laundry-filter-scope">Search, outcome, and sort apply to the cases on this page.</p>
            @endif
        </section>

        <section class="card completed-laundry-card" aria-labelledby="completed-laundry-cases-title">
            <div class="completed-laundry-cases-heading">
                <h2 id="completed-laundry-cases-title">Completed Laundry Cases</h2>
                <p data-completed-count data-total="{{ $jobs->total() }}" data-first="{{ $jobs->firstItem() ?? 0 }}" data-last="{{ $jobs->lastItem() ?? 0 }}" data-paginated="{{ $jobs->hasPages() ? 'true' : 'false' }}" role="status" aria-live="polite">Showing {{ $jobs->firstItem() ?? 0 }} to {{ $jobs->lastItem() ?? 0 }} of {{ $jobs->total() }} completed cases</p>
            </div>

            <div class="completed-laundry-table-wrap">
                <table class="completed-laundry-table">
                    <thead><tr><th scope="col">Case ID</th><th scope="col">Borrower</th><th scope="col">Items</th><th scope="col">Completed Date</th><th scope="col">Outcome</th><th scope="col">Actions</th></tr></thead>
                    <tbody>
                        @foreach($jobs as $job)
                            @include('laundry.partials.completed-case-row')
                        @endforeach
                    </tbody>
                </table>
                <div class="completed-laundry-no-results" data-completed-empty @if($jobs->count()) hidden @endif>
                    <x-icon name="search" size="26" />
                    <strong>No completed cases match your filters.</strong>
                    <p>Try another search or outcome.</p>
                    <button class="button secondary small ui-pressable" type="button" data-completed-reset>Clear filters</button>
                </div>
            </div>

            @if($jobs->hasPages())
                <div class="completed-laundry-footer">
                    {{ $jobs->onEachSide(1)->links('laundry.partials.completed-pagination') }}
                </div>
            @endif
        </section>
    @endif

    <div class="completed-laundry-archive-note"><x-icon name="information" size="21" /><p>Completed cases are archived for record keeping and inventory management.</p></div>
</div>

@include('laundry.partials.completed-interactions')
@endsection
