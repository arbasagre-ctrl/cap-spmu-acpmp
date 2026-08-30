@extends('layouts.app', ['title' => 'Laundry Operations'])
@section('content')
<section class="page-heading">
    <div>
        <p class="eyebrow">SPMU Action Officer</p>
        <h1>Laundry Operations</h1>
        <p>Track physical linen turnover separately from the later internal washing schedule.</p>
    </div>
    <a class="button secondary ui-pressable" href="{{ route('laundry.completed') }}">Completed Laundry</a>
</section>

<section class="content-area">
    <article class="card">
        <div class="callout info">
            <strong>Simplified linen flow</strong>
            <p>
                1) SPMU records the borrower return condition once in Return. 2) The borrower brings the linen and same printed Laundry Form to the Laundry Area. 3) Laundry Personnel wet-signs <strong>Received by</strong>. 4) The borrower is cleared from the linen handoff, while internal Laundry processing continues until clean/serviceable linen is marked Available.
            </p>
        </div>

        <div class="document-list top-gap">
            @forelse($jobs as $job)
                @php
                    $statusText = match($job->status) {
                        'FOR_LAUNDRY' => 'Awaiting return inspection / physical Laundry receipt',
                        'TURNED_OVER_TO_LAUNDRY' => 'Borrower turnover complete · internal laundry pending',
                        default => str($job->status)->replace('_', ' ')->title(),
                    };
                @endphp
                <article>
                    <div>
                        <strong>{{ $job->custody->request->request_no }}</strong>
                        <span>{{ $job->custody->borrower->full_name }} · {{ $job->custody->custody_no }}</span>
                        <small>{{ $statusText }}</small>
                    </div>
                    <div class="inline-actions">
                        <x-status-badge :status="$job->status" />
                        <a class="button primary small ui-pressable" href="{{ route('laundry.show', $job) }}">Open</a>
                    </div>
                </article>
            @empty
                <div class="empty-state"><strong>No Laundry cases need action.</strong><p>New linen cases appear here after physical release.</p></div>
            @endforelse
        </div>

        @if($jobs->hasPages())<div class="top-gap">{{ $jobs->links() }}</div>@endif
    </article>
</section>
@endsection
