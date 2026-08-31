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
        <section class="card completed-laundry-card" aria-labelledby="completed-laundry-cases-title">
            <div class="completed-laundry-toolbar">
                <h2 id="completed-laundry-cases-title">
                    <span class="completed-laundry-list-icon" aria-hidden="true"><svg class="ui-icon" width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><path d="M9 6h11M9 12h11M9 18h11" /><path d="M4 5h1v2H4zM4 11h1v2H4zM4 17h1v2H4z" /></svg></span>
                    Completed Laundry Cases
                </h2>
                <div class="completed-laundry-filters">
                    <label class="search-input-shell completed-laundry-search">
                        <span class="visually-hidden">Search completed laundry cases</span>
                        <input type="search" placeholder="Search case / borrower / items..." data-completed-search autocomplete="off">
                        <span class="search-input-icon" aria-hidden="true"><x-icon name="search" size="17" /></span>
                    </label>
                    <label class="completed-laundry-outcome-filter">
                        <span class="visually-hidden">Filter by final outcome</span>
                        <svg class="ui-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 4h18l-7 8v8l-4-2v-6L3 4Z" /></svg>
                        <select data-completed-outcome>
                            <option value="">All Outcomes</option>
                            <option value="available">Cleaned / Available</option>
                            <option value="maintenance">Routed to Maintenance</option>
                            <option value="not-needed">No Laundry Required</option>
                            <option value="unrecorded">Outcome Not Recorded</option>
                        </select>
                    </label>
                </div>
            </div>
            @if($jobs->hasPages())
                <p class="completed-laundry-filter-scope">Search and outcome filters apply to this page.</p>
            @endif

            <div class="completed-laundry-table-wrap">
                <table class="completed-laundry-table">
                    <thead><tr><th scope="col">Case ID</th><th scope="col">Borrower</th><th scope="col">Items</th><th scope="col">Completed Date</th><th scope="col">Outcome</th><th scope="col">Action</th></tr></thead>
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

            <div class="completed-laundry-footer">
                <p data-completed-count data-total="{{ $jobs->total() }}" data-first="{{ $jobs->firstItem() ?? 0 }}" data-last="{{ $jobs->lastItem() ?? 0 }}" data-paginated="{{ $jobs->hasPages() ? 'true' : 'false' }}" role="status" aria-live="polite">Showing {{ $jobs->firstItem() ?? 0 }} to {{ $jobs->lastItem() ?? 0 }} of {{ $jobs->total() }} completed cases</p>
                {{ $jobs->onEachSide(1)->links('laundry.partials.completed-pagination') }}
            </div>
        </section>
    @endif

    <div class="completed-laundry-archive-note"><x-icon name="information" size="21" /><p>Completed cases are archived for record keeping and inventory management.</p></div>
</div>

@include('laundry.partials.completed-interactions')
@endsection
