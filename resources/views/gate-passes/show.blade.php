@extends('layouts.app', ['title' => 'Gate Pass '.$gatePass->custody?->custody_no])
@section('content')
@php
    $custody = $gatePass->custody;
    $version = $custody?->request?->currentVersion;
    $offCampusLines = $custody?->lines?->filter(fn($line) => $line->requestItem?->use_location === 'OFF_CAMPUS') ?? collect();
    $gatePassFinalized = in_array($gatePass->status, ['READY_FOR_PRINTING', 'VERIFIED'], true)
        && (bool) $gatePass->passDocument;
@endphp
<section class="page-heading">
    <div>
        <p class="eyebrow">Gate Pass transaction</p>
        <h1>{{ $custody?->custody_no }}</h1>
        <p>{{ $custody?->request?->request_no }} · {{ $custody?->borrower?->full_name }}</p>
    </div>
    <a class="button secondary ui-pressable" href="{{ route('gate-passes.index') }}">Back to Gate Pass</a>
</section>

<section class="content-area">
    <div class="content-grid two gate-pass-detail-grid">
        <article class="card">
            <div class="card-header">
                <div>
                    <p class="eyebrow">SPMU Gate Pass workflow</p>
                    <h2>{{ $gatePassFinalized ? 'Final Gate Pass' : 'Pending Gate Pass Verification' }}</h2>
                </div>
                <x-status-badge :status="$gatePass->status" />
            </div>

            @if(!$gatePassFinalized)
                <div class="callout info">
                    <strong>Do not print a Gate Pass yet.</strong>
                    <p>
                        The request is approved, but the Gate Pass becomes official only when the Action Officer confirms the actual Physical Release. That step applies the Action Officer E-signature and generates the single FINAL Gate Pass.
                    </p>
                </div>
            @else
                <div class="callout success">
                    <strong>Final Gate Pass is ready.</strong>
                    <p>
                        This copy contains the borrower/bearer E-signature, the Action Officer verification E-signature, and the SPMU Head approval E-signature. SPMU should print this final copy and give it to the borrower before the property exits campus.
                    </p>
                    <div class="inline-actions top-gap">
                        <a class="button primary ui-pressable" href="{{ route('documents.download', $gatePass->passDocument) }}">Print Final Gate Pass</a>
                    </div>
                </div>
            @endif

            @if($gatePass->accomplishedFile)
                <div class="evidence-row top-gap">
                    <div>
                        <strong>Fully accomplished Gate Pass</strong>
                        <small>Uploaded {{ optional($gatePass->uploaded_at)->format('d M Y, g:i A') }}</small>
                    </div>
                    <a class="button secondary small" href="{{ route('files.show', $gatePass->accomplishedFile, false) }}" target="_blank" rel="noopener">View uploaded scan</a>
                </div>
            @endif

            @if($gatePass->status === 'READY_FOR_PRINTING')
                <form method="post" action="{{ route('gate-passes.verify', $gatePass) }}" enctype="multipart/form-data" class="form-grid top-gap">
                    @csrf
                    <div class="callout info">
                        <strong>After the guard processes the printed Gate Pass</strong>
                        <p>Upload the fully accomplished physical copy and record the guard release details for archival verification.</p>
                    </div>
                    <label>Fully accomplished Gate Pass
                        <input type="file" name="accomplished_form" accept="application/pdf,image/png,image/jpeg,image/webp" required>
                    </label>
                    <label>Guard Name
                        <input name="guard_name" value="{{ old('guard_name', $gatePass->guard_name) }}" maxlength="255" required>
                    </label>
                    <label>Date &amp; Time Released Off Campus
                        <input type="datetime-local" name="guard_signed_at" value="{{ old('guard_signed_at', optional($gatePass->guard_signed_at)->format('Y-m-d\TH:i')) }}" required>
                    </label>
                    <label>Remarks
                        <textarea name="remarks" maxlength="2000">{{ old('remarks', $gatePass->verification_remarks) }}</textarea>
                    </label>
                    <button class="button primary ui-pressable">Upload &amp; Verify Accomplished Gate Pass</button>
                </form>
            @elseif($gatePass->status === 'VERIFIED')
                <div class="callout success top-gap"><strong>Gate Pass completed.</strong> The accomplished scan and guard release details are archived.</div>
            @endif
        </article>

        <article class="card">
            <div class="card-header"><div><p class="eyebrow">Recorded details</p><h2>Gate Pass information</h2></div></div>
            <dl class="detail-list">
                <dt>Borrower</dt><dd>{{ $custody?->borrower?->full_name ?: '—' }}</dd>
                <dt>Destination</dt><dd>{{ $gatePass->destination ?: ($version?->location ?: '—') }}</dd>
                <dt>Purpose</dt><dd>{{ $gatePass->purpose ?: ($version?->purpose_event ?: '—') }}</dd>
                <dt>Guard Name</dt><dd>{{ $gatePass->guard_name ?: 'Pending accomplished form' }}</dd>
                <dt>Date &amp; Time Released</dt><dd>{{ optional($gatePass->guard_signed_at)->format('d M Y, g:i A') ?: 'Pending accomplished form' }}</dd>
            </dl>

            @if($gatePassFinalized)
                <a class="button secondary ui-pressable" href="{{ route('documents.download', $gatePass->passDocument) }}">View Final Gate Pass</a>
            @else
                <span class="status-badge status-warning">Pending SPMU Verification</span>
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
