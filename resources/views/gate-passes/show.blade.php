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
    $hasPickupSchedule = (bool) $custody?->scheduled_release_at;
    $itemsReleased = (bool) $custody?->released_at;
    $gatePassRecorded = $gatePass->status === 'VERIFIED';
    $gatePassRecording = $gatePass->status === 'READY_FOR_PRINTING' && (bool) $custody?->released_at;

    $gatePassSteps = [
        ['label' => 'Approved', 'done' => $gatePassFinalized && ! $gatePassVoided, 'current' => ! $gatePassFinalized && ! $gatePassVoided],
        ['label' => 'Pickup Scheduled', 'done' => $hasPickupSchedule || $itemsReleased || $gatePassRecorded, 'current' => $gatePassFinalized && ! $hasPickupSchedule && ! $itemsReleased && ! $gatePassRecorded],
        ['label' => 'Items Released', 'done' => $itemsReleased || $gatePassRecorded, 'current' => $hasPickupSchedule && ! $itemsReleased && ! $gatePassRecorded],
        ['label' => 'Guard Verification', 'done' => $gatePassRecorded, 'current' => $itemsReleased && ! $gatePassRecorded],
        ['label' => 'SPMU Recorded', 'done' => $gatePassRecorded, 'current' => false],
    ];
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
    @unless($gatePassVoided)
        <article class="card gate-pass-progress-card" aria-label="Gate Pass progress">
            <div class="gate-pass-progress">
                @foreach($gatePassSteps as $step)
                    <div class="gate-pass-progress-step {{ $step['done'] ? 'is-done' : ($step['current'] ? 'is-current' : 'is-pending') }}">
                        <span class="gate-pass-progress-dot" aria-hidden="true">{{ $step['done'] ? '✓' : $loop->iteration }}</span>
                        <span>{{ $step['label'] }}</span>
                    </div>
                    @unless($loop->last)
                        <span class="gate-pass-progress-line" aria-hidden="true"></span>
                    @endunless
                @endforeach
            </div>
        </article>
    @endunless

    <div class="content-grid two gate-pass-detail-grid">
        <article class="card">
            <div class="card-header">
                <div>
                    <p class="eyebrow">Gate Pass</p>
                    <h2>
                        {{ $gatePassVoided
                            ? 'Voided Gate Pass'
                            : ($gatePassRecording
                                ? 'Record Accomplished Gate Pass'
                                : ($gatePassFinalized ? 'Approved Gate Pass' : 'Gate Pass Pending')) }}
                    </h2>
                </div>
                @if($gatePassRecording)
                    <span class="status-badge status-warning">Pending Copy</span>
                @else
                    <x-status-badge :status="$gatePassStatus['key']" :label="$gatePassStatus['label']" />
                @endif
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
                        <strong>Ready for printing.</strong>
                        <p>The Guard on Duty completes the printed Gate Pass at campus exit.</p>
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
                        <p>Upload the signed Gate Pass when the accomplished copy reaches SPMU, then copy the Guard on Duty and release date/time exactly as written on the form.</p>
                    </div>
                    <label>Signed Gate Pass Copy
                        <input type="file" name="accomplished_form" accept="application/pdf,image/png,image/jpeg,image/webp" required>
                    </label>
                    <label>Guard on Duty
                        <input name="guard_name" value="{{ old('guard_name', $gatePass->guard_name) }}" maxlength="255" required>
                    </label>
                    <label>Off-campus Release Date &amp; Time
                        <input type="datetime-local" name="guard_signed_at" value="{{ old('guard_signed_at', optional($gatePass->guard_signed_at)->format('Y-m-d\TH:i')) }}" required>
                    </label>
                    <label>Remarks <small class="meta">Optional</small>
                        <textarea name="remarks" rows="3" maxlength="2000" placeholder="Optional note">{{ old('remarks', $gatePass->verification_remarks) }}</textarea>
                    </label>
                    <button class="button primary ui-pressable">Save Gate Pass Record</button>
                </form>
            @elseif($gatePass->status === 'READY_FOR_PRINTING')
                <div class="callout info top-gap"><strong>Awaiting physical release.</strong> The printed Gate Pass is ready for use.</div>
            @elseif($gatePass->status === 'VERIFIED')
                <div class="callout success top-gap">
                    <strong>Gate Pass recorded.</strong>
                    <p>The accomplished copy is saved with this transaction.</p>
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
            <div class="card-header"><div><p class="eyebrow">Details</p><h2>Off-campus use</h2></div></div>
            <dl class="detail-list">
                <dt>Borrower</dt><dd>{{ $custody?->borrower?->full_name ?: '—' }}</dd>
                <dt>Event / Use Location</dt><dd>{{ $gatePass->destination ?: ($version?->location ?: '—') }}</dd>
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
                <table><thead><tr><th>Item</th><th>Qty</th></tr></thead><tbody>
                @foreach($offCampusLines as $line)
                    <tr><td>{{ $line->requestItem?->description_snapshot }}</td><td>{{ $line->approved_quantity + 0 }}</td></tr>
                @endforeach
                </tbody></table>
            </div>
        </article>
    </div>
</section>

<style>
    .gate-pass-progress-card { margin-bottom: 1rem; padding: 1rem 1.25rem; }
    .gate-pass-progress { display: flex; align-items: center; gap: .65rem; width: 100%; overflow-x: auto; padding: .15rem 0; }
    .gate-pass-progress-step { display: flex; align-items: center; gap: .5rem; min-width: max-content; font-weight: 700; color: var(--text-muted, #64748b); }
    .gate-pass-progress-dot { width: 2rem; height: 2rem; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--border, #cbd5e1); background: var(--surface, #fff); font-size: .82rem; }
    .gate-pass-progress-step.is-done { color: #08783f; }
    .gate-pass-progress-step.is-done .gate-pass-progress-dot { background: #e8f7ef; border-color: #9bd7b8; }
    .gate-pass-progress-step.is-current { color: #075fc4; }
    .gate-pass-progress-step.is-current .gate-pass-progress-dot { background: #eaf4ff; border-color: #7ab7f5; }
    .gate-pass-progress-line { flex: 1 1 2rem; min-width: 1.5rem; height: 2px; background: var(--border, #d7e0ea); }
    @media (max-width: 800px) { .gate-pass-progress { align-items: flex-start; } .gate-pass-progress-line { min-width: .75rem; } }
</style>
@endsection
