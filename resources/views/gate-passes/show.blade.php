@extends('layouts.app', ['title' => 'Gate Pass '.$gatePass->custody?->custody_no])
@section('content')
@php
    $custody = $gatePass->custody;
    $version = $custody?->request?->currentVersion;
    $offCampusLines = $custody?->lines?->filter(fn($line) => $line->requestItem?->use_location === 'OFF_CAMPUS') ?? collect();
    $gatePassFinalized = in_array($gatePass->status, ['READY_FOR_PRINTING', 'VERIFIED'], true)
        && (bool) $gatePass->passDocument;
    $gatePassStatus = $gatePass->workflowStatus();
    $gatePassVoided = $gatePassStatus['key'] === 'VOID';
@endphp
<section class="page-heading">
    <div>
        <p class="eyebrow">Gate Pass transaction</p>
        <h1>{{ $custody?->custody_no }}</h1>
        <p>{{ $custody?->request?->request_no }} · {{ $custody?->borrower?->full_name }}</p>
    </div>
    @if($custody?->released_at)
        <a class="button secondary ui-pressable" href="{{ route('custody.return.show', $custody) }}#return-summary">Back to Return</a>
    @else
        <a class="button secondary ui-pressable" href="{{ route('gate-passes.index') }}">Back to Gate Pass</a>
    @endif
</section>

<section class="content-area">
    <div class="content-grid two gate-pass-detail-grid">
        <article class="card">
            <div class="card-header">
                <div>
                    <p class="eyebrow">SPMU Gate Pass workflow</p>
                    <h2>{{ $gatePassVoided ? 'Voided Gate Pass' : ($gatePassFinalized ? 'Approved Gate Pass' : 'Gate Pass Pending') }}</h2>
                </div>
                <x-status-badge :status="$gatePassStatus['key']" :label="$gatePassStatus['label']" />
            </div>

            @if($gatePassVoided)
                <div class="callout neutral">
                    <strong>This Gate Pass is voided.</strong>
                    <p>The related borrowing request was cancelled. No release or Gate Pass completion action is required.</p>
                </div>
            @elseif(!$gatePassFinalized)
                <div class="callout info">
                    <strong>Gate Pass is not ready for release.</strong>
                    <p>Complete the approved Gate Pass record before any off-campus handover.</p>
                </div>
            @else
                @if(!$custody?->released_at)
                    <div class="callout success">
                        <strong>Approved Gate Pass is ready.</strong>
                        <p>Print the Gate Pass before release. The Guard on Duty completes <strong>Released by</strong>, Date, and Time when the borrower exits the campus.</p>
                        <div class="inline-actions top-gap">
                            <a class="button secondary ui-pressable" href="{{ route('documents.view', $gatePass->passDocument) }}" target="_blank" rel="noopener">View</a>
                            <a class="button primary ui-pressable" href="{{ route('documents.download', $gatePass->passDocument) }}">Download / Print</a>
                        </div>
                    </div>
                @endif
            @endif

            @if($gatePass->status === 'READY_FOR_PRINTING' && $custody?->released_at)
                <form method="post" action="{{ route('gate-passes.verify', $gatePass) }}" enctype="multipart/form-data" class="form-grid">
                    @csrf
                    <div class="callout info">
                        <strong>Record accomplished Gate Pass</strong>
                        <p>When the borrower returns, upload the Gate Pass and copy the Guard on Duty, Date, and Time exactly as written on the form.</p>
                    </div>
                    <label>Accomplished Gate Pass
                        <input type="file" name="accomplished_form" accept="application/pdf,image/png,image/jpeg,image/webp" required>
                    </label>
                    <label>Guard on Duty
                        <input name="guard_name" value="{{ old('guard_name', $gatePass->guard_name) }}" maxlength="255" required>
                    </label>
                    <label>Date &amp; Time Released Off Campus
                        <input type="datetime-local" name="guard_signed_at" value="{{ old('guard_signed_at', optional($gatePass->guard_signed_at)->format('Y-m-d\TH:i')) }}" required>
                    </label>
                    <label>Remarks <small class="meta">Optional</small>
                        <textarea name="remarks" maxlength="2000">{{ old('remarks', $gatePass->verification_remarks) }}</textarea>
                    </label>
                    <button class="button primary ui-pressable">Save Gate Pass Record</button>
                </form>
            @elseif($gatePass->status === 'READY_FOR_PRINTING')
                <div class="callout info top-gap"><strong>Awaiting physical release.</strong> The accomplished Gate Pass will be recorded when the borrower returns.</div>
            @elseif($gatePass->status === 'VERIFIED')
                <div class="callout success top-gap">
                    <strong>Accomplished Gate Pass recorded.</strong>
                    <p>Continue with this same borrowing transaction; there is no need to search the Gate Pass queue.</p>
                    <div class="inline-actions top-gap">
                        @if($gatePass->accomplishedFile)
                            <a class="button secondary small ui-pressable" href="{{ route('files.show', $gatePass->accomplishedFile, false) }}" target="_blank" rel="noopener">View Accomplished Gate Pass</a>
                        @endif
                        <a class="button primary small ui-pressable" href="{{ route('custody.return.show', $custody) }}#return-summary">Continue Transaction</a>
                    </div>
                </div>
            @endif
        </article>

        <article class="card">
            <div class="card-header"><div><p class="eyebrow">Recorded details</p><h2>Gate Pass information</h2></div></div>
            <dl class="detail-list">
                <dt>Borrower</dt><dd>{{ $custody?->borrower?->full_name ?: '—' }}</dd>
                <dt>Destination</dt><dd>{{ $gatePass->destination ?: ($version?->location ?: '—') }}</dd>
                <dt>Purpose</dt><dd>{{ $gatePass->purpose ?: ($version?->purpose_event ?: '—') }}</dd>
                @if($gatePass->status === 'VERIFIED')
                    <dt>Guard on Duty</dt><dd>{{ $gatePass->guard_name ?: '—' }}</dd>
                    <dt>Date &amp; Time Released Off Campus</dt><dd>{{ optional($gatePass->guard_signed_at)->format('d M Y, g:i A') ?: '—' }}</dd>
                @endif
            </dl>

            @if(! $gatePassFinalized && ! $gatePassVoided)
                <span class="status-badge status-danger">Missing approved document</span>
            @endif

            <div class="table-wrap top-gap">
                <table><thead><tr><th>Item</th><th>Qty</th><th>Use</th></tr></thead><tbody>
                @foreach($offCampusLines as $line)
                    <tr><td>{{ $line->requestItem?->description_snapshot }}</td><td>{{ $line->approved_quantity + 0 }}</td><td>Off Campus</td></tr>
                @endforeach
                </tbody></table>
            </div>
        </article>
    </div>
</section>
@endsection
