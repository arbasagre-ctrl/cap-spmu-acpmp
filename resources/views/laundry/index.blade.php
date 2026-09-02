@extends('layouts.app', ['title' => 'Laundry Operations'])
@section('content')
@php
    // Read-only display relations, batch-loaded for the existing paginated results.
    $jobs->getCollection()->loadMissing([
        'custody.request.currentVersion',
        'custody.borrower.organizationalUnit',
        'custody.returns',
    ]);
    $hasLaundryCases = $jobs->total() > 0;
    $laundryStatusLabels = [
        'FOR_LAUNDRY' => 'Accomplished Form Pending',
        'TURNED_OVER_TO_LAUNDRY' => 'Laundry Processing',
    ];
    $laundryStatuses = collect(array_keys($laundryStatusLabels))
        ->merge($jobs->pluck('status'))->unique();
@endphp

@include('laundry.partials.operations-styles')

<div class="laundry-operations" data-laundry-browser>
    <section class="page-heading laundry-operations-heading">
        <div>
            <p class="eyebrow">SPMU Action Officer</p>
            <h1>Laundry Operations</h1>
            <p>Monitor accomplished Laundry Forms and complete laundry processing for serviceable linen.</p>
        </div>
        <a class="button secondary ui-pressable laundry-completed-link" href="{{ route('laundry.completed') }}">Completed</a>
    </section>

    @include('laundry.partials.flow-guide')

    @if($hasLaundryCases)
        <section class="card laundry-toolbar" aria-label="Laundry case filters">
            <label for="laundry-search">Search
                <span class="search-input-shell">
                    <span class="search-input-icon" aria-hidden="true"><x-icon name="search" size="18" /></span>
                    <input id="laundry-search" type="search" placeholder="Search borrower, request no., custody no., or event..." data-laundry-search autocomplete="off">
                </span>
            </label>
            <label for="laundry-status">Status
                <select id="laundry-status" data-laundry-status>
                    <option value="">All statuses</option>
                    @foreach($laundryStatuses as $status)
                        <option value="{{ $status }}">{{ $laundryStatusLabels[$status] ?? str($status)->replace('_', ' ')->title() }}</option>
                    @endforeach
                </select>
            </label>
            <label for="laundry-sort">Sort
                <select id="laundry-sort" data-laundry-sort>
                    <option value="newest">Newest</option>
                    <option value="oldest">Oldest</option>
                </select>
            </label>
            @if($jobs->hasPages())
                <p class="laundry-filter-scope">Search, status, and sort apply to the cases on this page.</p>
            @endif
        </section>

        <section class="card laundry-cases-card" aria-labelledby="laundry-cases-title">
            <div class="laundry-cases-heading">
                <h2 id="laundry-cases-title">Laundry Cases</h2>
                <p data-laundry-count data-total="{{ $jobs->total() }}" data-paginated="{{ $jobs->hasPages() ? 'true' : 'false' }}" role="status" aria-live="polite">Showing {{ $jobs->count() }} of {{ $jobs->total() }} cases</p>
            </div>
            <div class="laundry-table-wrap">
                <table class="laundry-cases-table">
                    <thead>
                        <tr><th scope="col">Request No.</th><th scope="col">Borrower</th><th scope="col">Event / Purpose</th><th scope="col">Custody No.</th><th scope="col">Status</th><th scope="col">Returned On</th><th scope="col">Actions</th></tr>
                    </thead>
                    <tbody data-laundry-list>
                        @foreach($jobs as $job)
                            @php
                                $caseCustody = $job->custody;
                                $caseBorrower = $caseCustody?->borrower;
                                $caseRequest = $caseCustody?->request;
                                $casePurpose = $caseRequest?->currentVersion?->purpose_event;
                                $caseReturnedAt = $caseCustody?->returns->sortByDesc('received_at')->first()?->received_at;
                                // Derived: a verified accomplished form means Laundry
                                // has already received and signed for the linen.
                                $statusText = $job->displayStatusLabel();
                                $statusDescription = $job->displayStatusDescription();
                                $caseSearch = implode(' ', [
                                    $job->id,
                                    $caseRequest?->request_no,
                                    $caseCustody?->custody_no,
                                    $caseBorrower?->full_name,
                                    $caseBorrower?->organizationalUnit?->unit_name,
                                    $casePurpose,
                                    $job->lines->map(fn ($line) => $line->custodyLine?->requestItem?->description_snapshot)->implode(' '),
                                ]);
                            @endphp
                            <tr data-laundry-record data-search="{{ $caseSearch }}" data-status="{{ $job->status }}" data-date="{{ $job->updated_at?->timestamp ?? 0 }}">
                                <td><a class="laundry-request-link" href="{{ route('laundry.show', $job) }}">{{ $caseRequest?->request_no ?: 'Laundry case #'.$job->id }}</a></td>
                                <td><strong>{{ $caseBorrower?->full_name ?: '—' }}</strong><small>{{ $caseBorrower?->organizationalUnit?->unit_name ?: '—' }}</small></td>
                                <td>{{ $casePurpose ?: '—' }}</td>
                                <td>{{ $caseCustody?->custody_no ?: '—' }}</td>
                                <td><x-status-badge :status="$job->status" :label="$statusText" :title="$statusDescription" /></td>
                                <td class="laundry-returned-date">{{ $caseReturnedAt?->format('M d, Y') ?: '—' }}</td>
                                <td><a class="button secondary small ui-pressable laundry-view-link" href="{{ route('laundry.show', $job) }}">View details</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="laundry-filter-empty" data-laundry-filter-empty @if($jobs->count()) hidden @endif>
                <x-icon name="search" size="27" />
                <strong>No laundry cases match your filters.</strong>
                <p>Try another search or status.</p>
                <button class="button secondary small ui-pressable" type="button" data-laundry-reset>Clear filters</button>
            </div>
            @if($jobs->hasPages())
                <div class="laundry-pagination">{{ $jobs->links() }}</div>
            @endif
        </section>
    @else
        <section class="card laundry-empty-card" aria-label="No active laundry cases">
            @include('laundry.partials.empty-illustration')
            <h2>No laundry cases need action.</h2>
            <p>New linen cases will appear here after physical release.</p>
        </section>
    @endif
</div>

@include('laundry.partials.operations-interactions')
@endsection
