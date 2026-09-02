@extends('layouts.app', ['title' => 'Laundry Operations'])
@section('content')
@php
    $allLinenReturned = $job->lines->isNotEmpty()
        && $job->lines->every(function ($line) {
            $custodyLine = $line->custodyLine;
            return $custodyLine
                && (float) $custodyLine->returned_quantity >= (float) $custodyLine->actual_released_quantity;
        });

    $totalIssued = (int) round($job->lines->sum(fn ($line) => (float) $line->issued_quantity));
    $formArchived = $job->hasVerifiedAccomplishedForm();
    $legacyReadyForInternalLaundry = $job->status === 'FOR_LAUNDRY'
        && $formArchived
        && $allLinenReturned;
    $internalLaundryQuantity = function ($line) use ($legacyReadyForInternalLaundry): int {
        $stored = (int) round((float) ($line->received_quantity ?? 0));

        if ($stored > 0 || ! $legacyReadyForInternalLaundry) {
            return $stored;
        }

        return (int) round((float) $line->custodyLine->returnLines
            ->where('disposition_state', 'LAUNDRY')
            ->sum('quantity_received'));
    };
    $totalInternalLaundry = $job->lines->sum(fn ($line) => $internalLaundryQuantity($line));
    $returnEncoded = $allLinenReturned
        || in_array($job->status, ['TURNED_OVER_TO_LAUNDRY', 'LAUNDRY_COMPLETED'], true);
@endphp

<div class="laundry-detail">
<section class="page-heading laundry-detail-heading">
    <div>
        <p class="eyebrow">SPMU Action Officer · Laundry</p>
        <h1>{{ $job->custody->custody_no }}</h1>
        <p>{{ $job->custody->borrower->full_name }} · Request {{ $job->custody->request->request_no }}</p>
    </div>
</section>

<section class="content-area laundry-detail-tracker">
    <x-laundry-progress-tracker :job="$job" :inspection-complete="$allLinenReturned" />
</section>

<section class="content-area">
    <div class="content-grid two laundry-operation-grid">
        <article class="card laundry-form-card">
            <div class="card-header laundry-detail-card-title">
                <x-icon name="requests" size="27" />
                <h2>Laundry Form</h2>
            </div>

            <p class="laundry-form-description">The same printed form travels with the borrower from linen release through return.</p>

            <div class="inline-actions laundry-form-actions">
                @if($job->latestEvidence?->file)
                    <a class="button secondary small ui-pressable" href="{{ route('files.show', $job->latestEvidence->file, false) }}" target="_blank" rel="noopener">View Accomplished Form</a>
                @elseif($job->document)
                    <a class="button secondary ui-pressable" href="{{ route('documents.download', $job->document) }}" target="_blank" rel="noopener">View Laundry Form</a>
                @endif
            </div>

            <p class="meta top-gap">Laundry Personnel sign the printed form; SPMU uploads it before return encoding.</p>
        </article>

        <article class="card laundry-linen-card">
            <div class="card-header laundry-detail-card-title"><x-icon name="box" size="27" /><h2>Linen Status</h2></div>
            <dl class="detail-list laundry-linen-facts">
                <dt>Total issued:</dt><dd>{{ $totalIssued }}</dd>
                <dt>SPMU return:</dt><dd>{{ $returnEncoded ? 'Complete' : 'Pending' }}</dd>
                <dt>Serviceable in Laundry:</dt><dd>{{ $totalInternalLaundry }}</dd>
                <dt>Laundry processing:</dt><dd>{{ $job->status === 'LAUNDRY_COMPLETED' ? 'Complete' : ($returnEncoded ? 'Pending' : 'Not started') }}</dd>
            </dl>
        </article>
    </div>
</section>

@if($job->status === 'FOR_LAUNDRY' && ! $legacyReadyForInternalLaundry)
<section class="content-area">
    <article class="card laundry-next-action">
        <x-icon name="requests" size="36" />
        <div>
            @if(! $formArchived)
                <h2>Return linen to the Laundry Area first</h2>
                <p>Laundry Personnel physically check the returned linen, record the actual quantity/condition, and wet-sign <strong>Received by</strong>. The borrower then brings the accomplished Laundry Form and Borrower Slip to SPMU for upload and encoding.</p>
            @else
                <h2>Encode the accomplished Laundry Form in SPMU Return</h2>
                <p>The signed form is already on file. Record the linen quantities and conditions exactly as written by Laundry Personnel. No separate Laundry turnover confirmation is required afterward.</p>
            @endif
        </div>
        <a class="button primary ui-pressable" href="{{ route('custody.return.show', $job->custody) }}#return-primary">Open SPMU Return</a>
    </article>
</section>
@endif

@if($job->status === 'TURNED_OVER_TO_LAUNDRY' || $legacyReadyForInternalLaundry)
<section class="content-area">
    <div class="content-grid two laundry-operation-grid">
        <article class="card">
            <div class="card-header">
                <div><p class="eyebrow">Borrower obligation</p><h2>Linen return completed</h2></div>
            </div>
            <div class="callout success">
                <strong>No further linen action is required from the borrower.</strong>
            </div>

        </article>

        <article class="card">
            <div class="card-header"><div><p class="eyebrow">Laundry processing</p><h2>Mark clean linen available</h2></div></div>
            <p class="meta">Quantity comes from SPMU Return. No reclassification is needed here.</p>

            <form method="post" action="{{ route('laundry.complete-processing', $job) }}" class="form-grid top-gap">
                @csrf
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr><th>Item</th><th>Serviceable quantity in Laundry</th></tr>
                        </thead>
                        <tbody>
                        @foreach($job->lines as $line)
                            @php $received = $internalLaundryQuantity($line); @endphp
                            <tr>
                                <td>{{ $line->custodyLine?->requestItem?->description_snapshot ?? 'Linen item' }}</td>
                                <td>{{ $received }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <label>
                    Laundry remarks <small>Optional</small>
                    <textarea name="worker_remarks" placeholder="Optional laundry note">{{ old('worker_remarks', $job->worker_remarks) }}</textarea>
                </label>
                <button class="button primary ui-pressable link-button" type="submit">Mark Laundry Complete</button>
            </form>
        </article>
    </div>
</section>
@endif

@if($job->status === 'LAUNDRY_COMPLETED')
<section class="content-area">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Laundry complete</p>
                <h2>Laundry processing completed</h2>
            </div>
            <x-status-badge status="LAUNDRY_COMPLETED" />
        </div>
        <div class="callout success">
            <strong>Clean/serviceable linen is available in the Laundry Area.</strong>
            <p>The linen is ready for future borrowing.</p>
        </div>
        @if($job->completed_at)
            <p class="meta">Recorded {{ $job->completed_at->format('d M Y, g:i A') }}</p>
        @endif
    </article>
</section>
@endif

</div>
@include('laundry.partials.detail-styles')
@endsection
