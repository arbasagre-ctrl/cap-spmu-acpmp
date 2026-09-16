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
    $itemsReleased = (bool) $custody?->released_at;
    $returns = $custody?->returns ?? collect();
    $recordedReturn = $returns
        ->filter(fn($return) => $return->received_at !== null)
        ->sortByDesc('received_at')
        ->first();
    $physicalReturnRecorded = (bool) $recordedReturn;
    $gatePassRecorded = $gatePass->status === 'VERIFIED';
    $gatePassRecording = $gatePass->status === 'READY_FOR_PRINTING' && $physicalReturnRecorded;

    $gatePassSteps = [
        ['label' => 'Gate Pass Prepared', 'done' => $gatePassFinalized && ! $gatePassVoided, 'current' => ! $gatePassFinalized && ! $gatePassVoided],
        ['label' => 'Items Released', 'done' => $itemsReleased || $gatePassRecorded, 'current' => $gatePassFinalized && ! $itemsReleased && ! $gatePassRecorded],
        ['label' => 'Physical Return Recorded', 'done' => $physicalReturnRecorded || $gatePassRecorded, 'current' => $itemsReleased && ! $physicalReturnRecorded && ! $gatePassRecorded],
        ['label' => 'Accomplished Gate Pass Received', 'done' => $gatePassRecorded, 'current' => $physicalReturnRecorded && ! $gatePassRecorded],
        ['label' => 'SPMU Recorded', 'done' => $gatePassRecorded, 'current' => false],
    ];
@endphp
<section class="page-heading">
    <div>
        <p class="eyebrow">Gate Pass transaction</p>
        <h1>Gate Pass</h1>
        <p>Request {{ $custody?->request?->request_no ?: '—' }} · Custody {{ $custody?->custody_no ?: '—' }} · {{ $custody?->borrower?->full_name ?: '—' }}</p>
    </div>
    @if($custody?->released_at)
        <a class="button secondary ui-pressable" href="{{ route('custody.return.show', $custody) }}#return-summary">← Back</a>
    @else
        <a class="button secondary ui-pressable" href="{{ route('gate-passes.index') }}">← Back</a>
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
                            : ($gatePassRecorded
                                ? 'Accomplished Gate Pass'
                                : ($gatePassRecording
                                    ? 'Record Accomplished Gate Pass'
                                    : ($gatePassFinalized ? 'Approved Gate Pass' : 'Gate Pass Pending'))) }}
                    </h2>
                </div>
                @if($gatePassRecording)
                    <x-status-badge :status="$gatePassStatus['key']" label="Pending Copy" />
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
                            <a class="button secondary ui-pressable" href="{{ route('documents.view', $gatePass->passDocument) }}" target="_blank" rel="noopener">Open Gate Pass</a>
                            <a class="button primary ui-pressable" href="{{ route('documents.download', $gatePass->passDocument) }}">Download / Print</a>
                        </div>
                    </div>
                @endif
            @endif

            @if($gatePass->status === 'READY_FOR_PRINTING' && $itemsReleased && ! $physicalReturnRecorded)
                <div class="callout info top-gap">
                    <strong>Awaiting physical return inspection.</strong>
                    <p>The Guard on Duty completes the <strong>Released by</strong>, date, and time fields on the printed Gate Pass when the property leaves campus. Record the accomplished Gate Pass only after the borrower returns the property and SPMU records the physical return inspection.</p>
                </div>
            @elseif($gatePass->status === 'READY_FOR_PRINTING' && $physicalReturnRecorded)
                <form method="post" action="{{ route('gate-passes.verify', $gatePass) }}" enctype="multipart/form-data" class="form-grid">
                    @csrf
                    <div class="callout info">
                        <strong>Record accomplished Gate Pass</strong>
                        <p>Receive the accomplished Gate Pass from the borrower after the physical return inspection, upload the copy, then encode the Guard on Duty and the off-campus release date/time exactly as written on the form.</p>
                    </div>
                    <label>Signed Gate Pass Copy
                        <input type="file" name="accomplished_form" accept="application/pdf,image/png,image/jpeg,image/webp" required>
                    </label>
                    <label>Guard on Duty
                        <input name="guard_name" value="{{ old('guard_name', $gatePass->guard_name) }}" maxlength="255" required>
                    </label>
                    <label>Date &amp; Time Released Off Campus
                        <input
                            type="datetime-local"
                            name="guard_signed_at"
                            value="{{ old('guard_signed_at', optional($gatePass->guard_signed_at)->format('Y-m-d\TH:i')) }}"
                            @if($custody?->released_at) min="{{ $custody->released_at->format('Y-m-d\TH:i') }}" @endif
                            @if($recordedReturn?->received_at) max="{{ $recordedReturn->received_at->format('Y-m-d\TH:i') }}" @endif
                            required
                        >
                        <small class="meta">Enter the Guard's handwritten exit date/time exactly as shown on the accomplished Gate Pass. Do not use the Physical Return Recorded time.</small>
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
                    <strong>Accomplished Gate Pass recorded.</strong>
                    <p>The accomplished Gate Pass has been received and recorded by SPMU.</p>
                    @if($gatePass->accomplishedFile)
                        <div class="inline-actions top-gap">
                            <a class="button secondary small ui-pressable" href="{{ route('files.show', $gatePass->accomplishedFile, false) }}" target="_blank" rel="noopener">Open Accomplished Gate Pass</a>
                        </div>
                    @endif
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
                    <dt>Date &amp; Time Released Off Campus</dt>
                    <dd>
                        {{ optional($gatePass->guard_signed_at)->format('d M Y, g:i A') ?: '—' }}
                        <small class="meta gate-pass-detail-note">Transcribed by the AO from the Guard's handwritten Released by / Date / Time section on the accomplished Gate Pass.</small>
                    </dd>
                    @if($recordedReturn?->received_at)
                        <dt>Physical Return Recorded</dt>
                        <dd>
                            {{ $recordedReturn->received_at->format('d M Y, g:i A') }}
                            <small class="meta gate-pass-detail-note">System timestamp of the AO physical return inspection. This is a separate event from the off-campus release time above.</small>
                        </dd>
                    @endif
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
    .gate-pass-progress-card { margin-bottom: 24px; padding: 1rem 1.25rem; }
    .gate-pass-progress { display: flex; align-items: center; gap: .65rem; width: 100%; overflow-x: auto; padding: .15rem 0; }
    .gate-pass-progress-step { display: flex; align-items: center; gap: .5rem; min-width: max-content; font-weight: 700; color: var(--text-muted, #64748b); }
    .gate-pass-progress-dot { width: 2rem; height: 2rem; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--border, #cbd5e1); background: var(--surface, #fff); font-size: .82rem; }
    .gate-pass-progress-step.is-done { color: #08783f; }
    .gate-pass-progress-step.is-done .gate-pass-progress-dot { background: #e8f7ef; border-color: #9bd7b8; }
    .gate-pass-progress-step.is-current { color: #075fc4; }
    .gate-pass-progress-step.is-current .gate-pass-progress-dot { background: #eaf4ff; border-color: #7ab7f5; }
    .gate-pass-progress-line { flex: 1 1 2rem; min-width: 1.5rem; height: 2px; background: var(--border, #d7e0ea); }
    .gate-pass-detail-grid { gap: 24px; }
    .gate-pass-detail-grid .callout p { max-width: 760px; line-height: 1.5; }
    .gate-pass-detail-note { display: block; margin-top: .2rem; line-height: 1.35; }
    @media (max-width: 800px) { .gate-pass-progress { align-items: flex-start; } .gate-pass-progress-line { min-width: .75rem; } }
</style>
@endsection
