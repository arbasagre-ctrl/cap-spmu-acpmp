@extends('layouts.app', ['title' => 'For Approval'])

@section('content')
@include('approvals.partials.queue-styles')

<div class="approval-queue" data-approval-queue>
    <section class="page-heading approval-queue-heading">
        <div>
            <p class="eyebrow">SPMU approval review</p>

            <h1>Requests for Approval</h1>

            <p>Review submitted borrowing requests ready for SPMU verification.</p>
        </div>

        <a
            class="button secondary ui-pressable approval-queue-records"
            href="{{ route('requests.index') }}"
        >
            <x-icon name="requests" size="16" />
            View Request Records
        </a>
    </section>

    @if($requests->isEmpty())
        @include('approvals.partials.queue-empty', [
            'emptyId' => null,
            'emptyHidden' => false,
            'emptyVariant' => null,
            'emptyTitle' => 'No requests waiting for approval.',
            'emptyMessage' => 'Newly submitted requests will appear here once they are ready for SPMU review.',
        ])
    @else
        <div class="approval-queue-filters">
            <label>
                Search
                <span class="search-input-shell">
                    <span class="search-input-icon" aria-hidden="true"><x-icon name="search" size="17" /></span>
                    <input
                        id="approval-queue-search"
                        type="search"
                        placeholder="Search request no., borrower, event, or item..."
                        autocomplete="off"
                    >
                </span>
            </label>

            <label>
                Sort
                <select id="approval-queue-sort">
                    <option value="oldest">Oldest submitted</option>
                    <option value="newest">Newest submitted</option>
                </select>
            </label>
        </div>

        <section class="card approval-queue-card" aria-labelledby="approval-queue-title">
            <h2 class="approval-queue-card-heading" id="approval-queue-title">Pending Approvals</h2>

            <div class="approval-queue-table-wrap">
                <table class="approval-queue-table">
                    <thead>
                        <tr>
                            <th scope="col">Request No.</th>
                            <th scope="col">Borrower</th>
                            <th scope="col">Event / Purpose</th>
                            <th scope="col">Submitted</th>
                            <th scope="col">Status</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>

                    <tbody id="approval-queue-body">
                        @foreach($requests as $request)
                            @include('approvals.partials.queue-row')
                        @endforeach
                    </tbody>
                </table>
            </div>

            @include('approvals.partials.queue-empty', [
                'emptyId' => 'approval-queue-no-results',
                'emptyHidden' => true,
                'emptyVariant' => 'no-results',
                'emptyTitle' => 'No matching requests.',
                'emptyMessage' => 'Try another request number, borrower, event, or item.',
            ])

            <div class="approval-queue-footer" id="approval-queue-footer">
                <p id="approval-queue-summary" role="status" aria-live="polite">
                    Showing {{ min(5, $requests->count()) }} of {{ $requests->count() }} requests
                </p>

                <div
                    class="approval-queue-pagination"
                    id="approval-queue-pagination"
                    role="group"
                    aria-label="Pending approvals pages"
                ></div>
            </div>
        </section>
    @endif
</div>

@include('approvals.partials.queue-interactions')
@endsection
