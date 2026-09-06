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
    <a class="button secondary ui-pressable" href="{{ route('custody.return.show', $job->custody) }}#return-summary">Back to Return</a>
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

            <p class="laundry-form-description">The same printed form travels with the borrower during custody. On return, the borrower brings the linen and form to the Laundry Area. The offline Laundry Worker checks the quantity/condition, wet-signs Received by and the Date row, keeps the accomplished form, and later delivers it directly to SPMU. No Laundry portal login is used.</p>

            <div class="inline-actions laundry-form-actions">
                @if($job->latestEvidence?->file)
                    <a class="button secondary small ui-pressable" href="{{ route('files.show', $job->latestEvidence->file, false) }}" target="_blank" rel="noopener">View Accomplished Form</a>
                @elseif($job->document)
                    <a class="button secondary ui-pressable" href="{{ route('documents.download', $job->document) }}" target="_blank" rel="noopener">View Laundry Form</a>
                @endif
            </div>

            <p class="meta top-gap">The Laundry Worker checks and signs the physical form at return, then later delivers it directly to SPMU. The Action Officer uploads and encodes it while the linen remains in the Laundry Area for the internal washing cycle.</p>
        </article>

        <article class="card laundry-linen-card">
            <div class="card-header laundry-detail-card-title"><x-icon name="box" size="27" /><h2>Linen Status</h2></div>
            <dl class="detail-list laundry-linen-facts">
                <dt>Total issued:</dt><dd>{{ $totalIssued }}</dd>
                <dt>Completed form:</dt><dd>{{ $formArchived ? 'Received by SPMU' : 'Pending from Laundry Personnel' }}</dd>
                <dt>SPMU return encoding:</dt><dd>{{ $returnEncoded ? 'Complete' : 'Pending' }}</dd>
                <dt>Serviceable quantity:</dt><dd>{{ $totalInternalLaundry }}</dd>
                <dt>Availability:</dt><dd>{{ $job->status === 'LAUNDRY_COMPLETED' ? 'Available' : ($returnEncoded ? 'Pending finalization' : 'Waiting for final form') }}</dd>
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
                <h2>Laundry processing / final form pending</h2>
                <p>The borrower returns the linen and Laundry Form to the Laundry Area first. The Laundry Worker checks the actual quantity and condition at handover, records any finding, wet-signs <strong>Received by</strong> and the <strong>Date</strong> row, then keeps the accomplished form for delivery to SPMU. Even if SPMU receives it days later, the Date written on the form is the borrower's linen return date.</p>
            @else
                <h2>Encode the accomplished Laundry Form in SPMU Return</h2>
                <p>The accomplished form delivered by the Laundry Worker is already on file. Record the linen quantities and conditions exactly as written. No second linen inspection or Laundry portal action is required.</p>
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
            <div class="card-header"><div><p class="eyebrow">Inventory reconciliation</p><h2>Finalize linen availability</h2></div></div>
            <p class="meta">The returned quantity/condition has already been documented on the accomplished Laundry Form and encoded by SPMU. This step only restores the serviceable quantity after the internal washing cycle to Available inventory.</p>

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
                    Availability remarks <small>Optional</small>
                    <textarea name="worker_remarks" placeholder="Optional reconciliation note">{{ old('worker_remarks', $job->worker_remarks) }}</textarea>
                </label>
                <button class="button primary ui-pressable link-button" type="submit">Finalize Linen Availability</button>
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
                <p class="eyebrow">Inventory available</p>
                <h2>Serviceable linen available</h2>
            </div>
            <x-status-badge status="LAUNDRY_COMPLETED" />
        </div>
        <div class="callout success">
            <strong>Serviceable linen is available for future borrowing.</strong>
            <p>Any adverse-condition quantity remains outside normal Available stock and follows the applicable accountability process.</p>
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
