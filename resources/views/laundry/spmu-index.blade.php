@extends('layouts.app', ['title' => 'Laundry Final Acceptance'])
@section('content')
<section class="page-heading">
    <div>
        <p class="eyebrow">SPMU Action Officer</p>
        <h1>Laundry Final Acceptance</h1>
        <p>Receive cleaned linen from the Laundry Worker, review the recorded laundry details, complete the final physical acceptance, and archive the fully accomplished Laundry Form.</p>
    </div>
</section>
<section class="content-area">
    <article class="card">
        <div class="document-list">
            @forelse($jobs as $job)
                @php
                    $statusText = match($job->status) {
                        'READY_FOR_SPMU_RETURN' => 'Cleaned linen and the physical Laundry Form are ready for SPMU final inspection.',
                        'AWAITING_FINAL_FORM_UPLOAD' => 'Final physical acceptance is complete; archive the fully accomplished Laundry Form.',
                        'FORM_REPLACEMENT_REQUIRED' => 'A clear replacement scan of the fully accomplished Laundry Form is required.',
                        default => str($job->status)->replace('_',' ')->title(),
                    };
                @endphp
                <article>
                    <div>
                        <strong>{{ $job->custody->request->request_no }}</strong>
                        <span>{{ $job->custody->borrower->full_name }} · {{ $job->custody->custody_no }}</span>
                        <small>{{ $statusText }}</small>
                    </div>
                    <div class="inline-actions"><x-status-badge :status="$job->status" /><a class="button primary small ui-pressable" href="{{ route('laundry.spmu.show', $job) }}">View details</a></div>
                </article>
            @empty
                <div class="empty-state"><strong>No Laundry final-acceptance cases need action.</strong></div>
            @endforelse
        </div>
        @if($jobs->hasPages())<div class="top-gap">{{ $jobs->links() }}</div>@endif
    </article>
</section>
@endsection
