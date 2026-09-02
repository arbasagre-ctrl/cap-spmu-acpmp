@extends('layouts.app', ['title' => $mode === 'ACTION_OFFICER_VERIFICATION' ? 'For Verification' : 'For Approval'])

@section('content')
@include('approvals.partials.queue-styles')

<div class="approval-queue" data-approval-queue>
    <section class="page-heading approval-queue-heading">
        <div>
            <p class="eyebrow">{{ $mode === 'ACTION_OFFICER_VERIFICATION' ? 'SPMU Action Officer' : 'SPMU Head decision' }}</p>

            <h1>{{ $mode === 'ACTION_OFFICER_VERIFICATION' ? 'Requests for Verification' : 'Requests for Approval' }}</h1>

            <p>
                {{ $mode === 'ACTION_OFFICER_VERIFICATION'
                    ? 'Verify submitted documents and request details before routing the request to the SPMU Head. Verification is not approval.'
                    : 'Review only requests already VERIFIED by the Action Officer and record the final Head decision.' }}
            </p>
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
            'emptyTitle' => $mode === 'ACTION_OFFICER_VERIFICATION'
                ? 'No requests waiting for verification.'
                : 'No verified requests waiting for approval.',
            'emptyMessage' => $mode === 'ACTION_OFFICER_VERIFICATION'
                ? 'New borrower submissions will appear here first.'
                : 'Requests appear here only after Action Officer verification.',
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
            <h2 class="approval-queue-card-heading" id="approval-queue-title">
                {{ $mode === 'ACTION_OFFICER_VERIFICATION' ? 'Pending Verifications' : 'Pending Head Decisions' }}
            </h2>

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
