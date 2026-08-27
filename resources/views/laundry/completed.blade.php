@extends('layouts.app', ['title' => 'Completed Laundry'])
@section('content')
<section class="page-heading">
    <div>
        <p class="eyebrow">SPMU Action Officer</p>
        <h1>Completed</h1>
        <p>Settled Laundry cases with SPMU final acceptance and a fully accomplished form archived in the system.</p>
    </div>
    <a class="button secondary ui-pressable" href="{{ route('laundry.index') }}">Back to Active</a>
</section>
<section class="content-area">
    <article class="card">
        <div class="document-list">
            @forelse($jobs as $job)
                <article>
                    <div>
                        <strong>{{ $job->custody->request->request_no }}</strong>
                        <span>{{ $job->custody->borrower->full_name }} · {{ $job->custody->custody_no }}</span>
                        <small>{{ $job->completed_at ? 'Completed '.$job->completed_at->format('d M Y, g:i A') : 'Laundry completed' }}</small>
                    </div>
                    <div class="inline-actions"><x-status-badge :status="$job->status" /><a class="button secondary small ui-pressable" href="{{ route('laundry.show', $job) }}">View</a></div>
                </article>
            @empty
                <div class="empty-state"><strong>No completed Laundry cases yet.</strong></div>
            @endforelse
        </div>
        @if($jobs->hasPages())<div class="top-gap">{{ $jobs->links() }}</div>@endif
    </article>
</section>
@endsection
