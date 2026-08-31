@extends('layouts.app', ['title' => 'Gate Pass'])
@section('content')
@php
    $gatePasses->loadMissing('custody.borrower.organizationalUnit');
    $gatePassStatusLabels = [
        'PENDING' => 'For Release',
        'READY_FOR_PRINTING' => 'Awaiting Accomplished Scan',
        'VERIFIED' => 'Completed',
    ];
    $gatePassStatusTones = ['PENDING' => 'warning', 'READY_FOR_PRINTING' => 'info', 'VERIFIED' => 'success'];
    $statuses = collect(array_keys($gatePassStatusLabels))->merge($gatePasses->pluck('status')->filter())->unique();
@endphp

@include('gate-passes.partials.index-styles')

<div class="gate-pass-browser" data-gate-pass-browser data-page-size="10">
    <section class="page-heading gate-pass-heading">
        <div>
            <p class="eyebrow">SPMU Action Officer</p>
            <h1>Gate Pass</h1>
            <p>Manage generated Gate Passes and accomplished scans for off-campus borrowing.</p>
        </div>
    </section>

    @if($gatePasses->isEmpty())
        <section class="card gate-pass-empty-card" aria-labelledby="gate-pass-empty-title">
            @include('gate-passes.partials.empty-illustration')
            <h2 id="gate-pass-empty-title">No Gate Pass records yet.</h2>
            <p>Off-campus borrowing records will appear here<br>once an approved request requires a Gate Pass.</p>
        </section>
    @else
        <section class="card gate-pass-toolbar" aria-label="Gate Pass filters">
            <label for="gate-pass-search">Search
                <span class="search-input-shell">
                    <span class="search-input-icon" aria-hidden="true"><x-icon name="search" size="18" /></span>
                    <input id="gate-pass-search" type="search" placeholder="Request, borrower, custody, destination..." data-gate-pass-search autocomplete="off">
                </span>
            </label>
            <label for="gate-pass-status">Status
                <select id="gate-pass-status" data-gate-pass-status>
                    <option value="">All Statuses</option>
                    @foreach($statuses as $status)
                        <option value="{{ $status }}">{{ $gatePassStatusLabels[$status] ?? str($status)->replace('_', ' ')->title() }}</option>
                    @endforeach
                </select>
            </label>
            <label for="gate-pass-sort">Sort
                <select id="gate-pass-sort" data-gate-pass-sort>
                    <option value="newest">Newest first</option>
                    <option value="oldest">Oldest first</option>
                </select>
            </label>
        </section>

        <section class="card gate-pass-records-card" aria-labelledby="gate-pass-records-title">
            <h2 id="gate-pass-records-title">Gate Pass Records</h2>
            <div class="gate-pass-table-wrap">
                <table class="gate-pass-table" aria-labelledby="gate-pass-records-title">
                    <thead>
                        <tr>
                            <th scope="col">Request No.</th>
                            <th scope="col">Borrower</th>
                            <th scope="col">Destination</th>
                            <th scope="col">Release Date</th>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody data-gate-pass-list>
                        @foreach($gatePasses as $gatePass)
                            @include('gate-passes.partials.record-row')
                        @endforeach
                        <tr class="gate-pass-filter-empty" data-gate-pass-empty hidden>
                            <td colspan="6">
                                <strong>No Gate Pass records match your filters.</strong>
                                <p>Try another search or status.</p>
                                <button class="button secondary small link-button" type="button" data-gate-pass-reset>Clear filters</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="gate-pass-footer">
                <p data-gate-pass-count role="status" aria-live="polite">Showing 1 to {{ $gatePasses->count() }} of {{ $gatePasses->count() }} records</p>
                <nav class="gate-pass-pagination" aria-label="Gate Pass records pagination" data-gate-pass-pagination hidden>
                    <button class="icon-button gate-pass-page" type="button" data-gate-pass-page="previous" aria-label="Previous page" disabled><x-icon name="chevron-right" class="gate-pass-previous-icon" size="16" /></button>
                    <div class="gate-pass-page-numbers" data-gate-pass-page-numbers></div>
                    <button class="icon-button gate-pass-page" type="button" data-gate-pass-page="next" aria-label="Next page" disabled><x-icon name="chevron-right" size="16" /></button>
                </nav>
            </div>
        </section>
    @endif
</div>

@include('gate-passes.partials.index-interactions')
@endsection
