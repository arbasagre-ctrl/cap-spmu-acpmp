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
    $totalInternalLaundry = (int) round($job->lines->sum(fn ($line) => (float) ($line->received_quantity ?? 0)));
    // Physical release confirms the applicable handwritten acknowledgements;
    // Laundry receipt is recorded separately after the Received by signature.
    $issuedSigned = (bool) $job->custody->released_at;
    $receivedSigned = (bool) $job->worker_received_at;
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

            <p class="laundry-form-description">Same printed form used from release through return.</p>

            <dl class="laundry-signature-list" aria-label="Handwritten Laundry Form signatures">
                <div>
                    <dt><x-icon name="edit" size="19" />Issued by:</dt>
                    <dd><span class="status-badge {{ $issuedSigned ? 'status-success' : 'status-warning' }}">{{ $issuedSigned ? 'Signed' : 'Pending' }}</span></dd>
                </div>
                <div>
                    <dt><x-icon name="profile" size="19" />Received by:</dt>
                    <dd><span class="status-badge {{ $receivedSigned ? 'status-success' : 'status-warning' }}">{{ $receivedSigned ? 'Signed' : 'Pending' }}</span></dd>
                </div>
            </dl>

            @if($job->document)
                <div class="inline-actions laundry-form-actions">
                    <a class="button secondary ui-pressable" href="{{ route('documents.download', $job->document) }}" target="_blank" rel="noopener">View Laundry Form</a>
                    @if($job->latestEvidence?->file)
                        <a class="button secondary small ui-pressable" href="{{ route('files.show', $job->latestEvidence->file, false) }}" target="_blank" rel="noopener">View archived signed form</a>
                    @endif
                </div>
            @endif

            @if(in_array($job->status, ['TURNED_OVER_TO_LAUNDRY', 'LAUNDRY_COMPLETED'], true) && ! $job->latestEvidence)
                <form method="post" action="{{ route('laundry.spmu.upload-form', $job) }}" enctype="multipart/form-data" class="form-grid top-gap">
                    @csrf
                    <label>
                        Archive accomplished Laundry Form
                        <small>Required for final transaction documentation; this does not delay borrower clearance.</small>
                        <input type="file" name="evidence" accept="application/pdf,image/png,image/jpeg,image/webp" required>
                    </label>
                    <button class="button secondary ui-pressable link-button" type="submit">Archive accomplished form</button>
                </form>
            @endif
        </article>

        <article class="card laundry-linen-card">
            <div class="card-header laundry-detail-card-title"><x-icon name="box" size="27" /><h2>Linen Status</h2></div>
            <dl class="detail-list laundry-linen-facts">
                <dt>Total issued:</dt><dd>{{ $totalIssued }}</dd>
                <dt>Returned / accounted by SPMU:</dt><dd>{{ $allLinenReturned ? 'Complete' : 'Pending return inspection' }}</dd>
                <dt>Internal laundry quantity:</dt><dd>{{ $totalInternalLaundry }}</dd>
                <dt>Turnover recorded by:</dt><dd>{{ $job->worker_name ?: 'Not yet recorded' }}</dd>
                <dt>Laundry received:</dt><dd>{{ optional($job->worker_received_at)->format('d M Y, g:i A') ?: 'Not yet recorded' }}</dd>
                <dt>Internal laundry completed:</dt><dd>{{ optional($job->worker_completed_at)->format('d M Y, g:i A') ?: 'Not yet recorded' }}</dd>
            </dl>
        </article>
    </div>
</section>

@if($job->status === 'FOR_LAUNDRY')
<section class="content-area">
    @if(! $allLinenReturned)
        <article class="card laundry-next-action">
            <x-icon name="requests" size="36" />
            <div>
                <h2>Return Inspection required</h2>
                <p>Record the returned linen quantity and condition before Laundry Turnover can be confirmed.</p>
            </div>
            <a class="button primary ui-pressable" href="{{ route('custody.return.show', $job->custody) }}#return-primary">Open Return Inspection</a>
        </article>
    @else
        <article class="card laundry-turnover-card">
            <div class="card-header">
                <div><p class="eyebrow">Borrower-side Laundry turnover</p><h2>Confirm Laundry received the returned linen</h2></div>
                <x-status-badge status="FOR_LAUNDRY" />
            </div>
            <div class="callout success">
                <strong>SPMU return inspection is complete.</strong>
                <p>
                    The borrower brings the returned linen and the same printed Laundry Form to the Laundry Area. After Laundry Personnel wet-signs <strong>Received by</strong>, confirm the physical turnover below. At that point, the borrower has no further linen action and does not wait for washing.
                </p>
            </div>

            <form method="post" action="{{ route('laundry.receive', $job) }}" class="form-grid top-gap">
                @csrf
                <label class="check-row">
                    <input type="checkbox" name="laundry_received_signature_confirmed" value="1" required>
                    <span>Laundry Personnel physically received the linen and signed <strong>Received by</strong> on the same printed Laundry Form.</span>
                </label>
                <label>
                    SPMU turnover remarks <small>Optional</small>
                    <textarea name="worker_remarks" placeholder="Optional note about the physical turnover">{{ old('worker_remarks', $job->worker_remarks) }}</textarea>
                </label>
                <button class="button primary ui-pressable link-button" type="submit">Confirm Laundry Turnover</button>
            </form>
        </article>
    @endif
</section>
@endif

@if($job->status === 'TURNED_OVER_TO_LAUNDRY')
<section class="content-area">
    <div class="content-grid two laundry-operation-grid">
        <article class="card">
            <div class="card-header">
                <div><p class="eyebrow">Borrower obligation</p><h2>Linen turnover completed</h2></div>
                <x-status-badge status="TURNED_OVER_TO_LAUNDRY" />
            </div>
            <div class="callout success">
                <strong>The washing cycle no longer blocks the borrower.</strong>
                <p>
                    Laundry Personnel have physically received the returned linen. Washing may be batched and completed later inside the Laundry Area. Any separate incident, overdue, or Gate Pass obligation can still keep the overall borrowing transaction open.
                </p>
            </div>

        </article>

        <article class="card">
            <div class="card-header"><div><p class="eyebrow">Internal Laundry processing</p><h2>Complete washing and mark serviceable linen available</h2></div></div>
            <p class="meta">Record this when washing is actually complete. Clean/serviceable linen remains in the Laundry Area and becomes Available for future borrowing.</p>

            <form method="post" action="{{ route('laundry.complete-processing', $job) }}" class="form-grid top-gap">
                @csrf
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr><th>Item</th><th>In Laundry</th><th>Clean / Available</th><th>Maintenance</th><th>Remarks</th></tr>
                        </thead>
                        <tbody>
                        @foreach($job->lines as $line)
                            @php $received = (int) round((float) ($line->received_quantity ?? 0)); @endphp
                            <tr>
                                <td>{{ $line->custodyLine?->requestItem?->description_snapshot ?? 'Linen item' }}</td>
                                <td>{{ $received }}</td>
                                <td><input type="number" min="0" step="1" inputmode="numeric" name="lines[{{ $line->id }}][cleaned_quantity]" value="{{ old('lines.'.$line->id.'.cleaned_quantity', $received) }}" required></td>
                                <td><input type="number" min="0" step="1" inputmode="numeric" name="lines[{{ $line->id }}][damaged_quantity]" value="{{ old('lines.'.$line->id.'.damaged_quantity', 0) }}" required></td>
                                <td><input name="lines[{{ $line->id }}][remarks]" value="{{ old('lines.'.$line->id.'.remarks', $line->remarks) }}" placeholder="Optional"></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <label>
                    Internal laundry remarks <small>Optional</small>
                    <textarea name="worker_remarks" placeholder="Optional note when Laundry processing is completed">{{ old('worker_remarks', $job->worker_remarks) }}</textarea>
                </label>
                <button class="button primary ui-pressable link-button" type="submit">Complete Laundry Processing</button>
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
                <p class="eyebrow">Internal Laundry complete</p>
                <h2>Laundry processing completed</h2>
            </div>
            <x-status-badge status="LAUNDRY_COMPLETED" />
        </div>
        <div class="callout success">
            <strong>Clean/serviceable linen is available in the Laundry Area.</strong>
            <p>
                This internal completion is separate from borrower clearance, which occurred when Laundry Personnel physically received the returned linen.
                No second SPMU return inspection is required after washing.
            </p>
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
