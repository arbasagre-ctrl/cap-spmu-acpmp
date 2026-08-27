@extends('layouts.app', ['title' => 'Laundry '.$job->custody->custody_no])
@section('content')
<section class="page-heading">
    <div>
        <p class="eyebrow">Laundry Request</p>
        <h1>{{ $job->custody->request->request_no }}</h1>
        <p>Borrower: <strong>{{ $job->custody->borrower->full_name }}</strong> · Custody {{ $job->custody->custody_no }}</p>
    </div>
    <x-status-badge :status="$job->status" />
</section>

<section class="content-area">
    <x-laundry-progress-tracker :job="$job" />
</section>

<section class="content-grid two">
    <article class="card">
        <div class="card-header"><div><p class="eyebrow">Physical form</p><h2>Laundry Form</h2></div></div>
        <p>
            Use the same printed form from borrower turnover through SPMU final acceptance.
            It must contain the Borrower signature, the required laundry-service acknowledgement, and final authorized SPMU signature before the final scan is archived.
        </p>
        @if($job->document)
            <a class="button secondary ui-pressable" href="{{ route('documents.download', $job->document) }}" target="_blank" rel="noopener">View / Print Laundry Form</a>
        @else
            <div class="callout warning"><strong>Laundry Form is not available yet.</strong><p>Ask SPMU to regenerate the form before continuing.</p></div>
        @endif
    </article>

    <article class="card">
        <div class="card-header"><div><p class="eyebrow">Linen covered</p><h2>Items under Laundry</h2></div></div>
        <div class="document-list">
            @foreach($job->lines as $line)
                <article>
                    <div>
                        <strong>{{ $line->custodyLine->requestItem->description_snapshot }}</strong>
                        <small>{{ $line->issued_quantity + 0 }} {{ $line->custodyLine->requestItem->unit_snapshot }} issued</small>
                    </div>
                </article>
            @endforeach
        </div>
    </article>
</section>

@if($job->status === 'FOR_LAUNDRY')
<section class="content-area">
    <form method="post" action="{{ route('laundry.receive', $job) }}" class="card form-grid" data-confirm-message="Confirm that the borrower signed the turnover portion and handed the used linen plus the physical Laundry Form to Laundry?">
        @csrf
        <div class="card-header">
            <div><p class="eyebrow">Action 1</p><h2>Receive used linen from Borrower</h2></div>
            <x-status-badge status="FOR_LAUNDRY" />
        </div>

        <div class="callout info">
            <strong>Borrower turnover only.</strong>
            <p>The Borrower signs the physical turnover portion, then hands over all used linen and the same printed Laundry Form. The SPMU Action Officer records the actual quantity physically received for processing.</p>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Issued Qty</th>
                        <th>Actual Received by Laundry</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($job->lines as $line)
                        <tr>
                            <td>
                                <strong>{{ $line->custodyLine->requestItem->description_snapshot }}</strong>
                                <small>{{ $line->custodyLine->requestItem->unit_snapshot }}</small>
                            </td>
                            <td>{{ $line->issued_quantity + 0 }}</td>
                            <td>
                                <input
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    max="{{ $line->issued_quantity }}"
                                    name="lines[{{ $line->id }}][received_quantity]"
                                    value="{{ old('lines.'.$line->id.'.received_quantity', $line->received_quantity) }}"
                                    placeholder="Enter actual count"
                                    required
                                >
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <label class="checkbox-row">
            <input type="checkbox" name="borrower_turnover_signature_confirmed" value="1" required>
            <span>I confirm the Borrower signed the physical turnover portion and handed the used linen together with the Laundry Form.</span>
        </label>

        <p class="meta">If the actual quantity received is less than the issued quantity, record the actual count. The discrepancy will remain for final SPMU accountability; do not treat it as an accepted partial return.</p>

        <button class="button primary ui-pressable">Confirm Borrower Turnover to Laundry</button>
    </form>
</section>
@endif

@if($job->status === 'IN_PROCESS')
<section class="content-area">
    <form method="post" action="{{ route('laundry.complete-processing', $job) }}" class="card form-grid" data-confirm-message="Confirm that Laundry processing is complete and the cleaned linen plus physical Laundry Form are ready to be brought directly to SPMU?">
        @csrf
        <div class="card-header">
            <div><p class="eyebrow">Action 2</p><h2>Record laundry completion</h2></div>
            <x-status-badge status="IN_PROCESS" />
        </div>

        <div class="callout info">
            <strong>Process all linen physically received.</strong>
            <p>Complete the laundry-service portion of the physical form and record the completed quantities and condition below. The Borrower does not collect the cleaned linen.</p>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Issued</th>
                        <th>Received</th>
                        <th>Condition / Issue</th>
                        <th>Affected</th>
                        <th>Completed</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($job->lines as $line)
                        <tr>
                            <td>
                                <strong>{{ $line->custodyLine->requestItem->description_snapshot }}</strong>
                                <small>{{ $line->custodyLine->requestItem->unit_snapshot }}</small>
                            </td>
                            <td>{{ $line->issued_quantity + 0 }}</td>
                            <td>{{ ($line->received_quantity ?? 0) + 0 }}</td>
                            <td>
                                <select name="lines[{{ $line->id }}][issue_type]" required>
                                    @foreach([
                                        'NONE' => 'No issue',
                                        'STAINED' => 'Stained',
                                        'TORN' => 'Torn',
                                        'DAMAGED' => 'Damaged',
                                        'OTHER' => 'Other',
                                    ] as $value => $label)
                                        <option value="{{ $value }}" @selected(old('lines.'.$line->id.'.issue_type', $line->issue_type ?? 'NONE') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <input
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    max="{{ $line->received_quantity ?? 0 }}"
                                    name="lines[{{ $line->id }}][affected_quantity]"
                                    value="{{ old('lines.'.$line->id.'.affected_quantity', $line->affected_quantity ?? 0) }}"
                                    required
                                >
                            </td>
                            <td>
                                <input
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    max="{{ $line->received_quantity ?? 0 }}"
                                    name="lines[{{ $line->id }}][completed_quantity]"
                                    value="{{ old('lines.'.$line->id.'.completed_quantity', $line->completed_quantity) }}"
                                    placeholder="Enter completed qty"
                                    required
                                >
                            </td>
                            <td>
                                <input name="lines[{{ $line->id }}][remarks]" value="{{ old('lines.'.$line->id.'.remarks', $line->remarks) }}" placeholder="Optional">
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <label>
            SPMU Action Officer Remarks
            <textarea name="worker_remarks" placeholder="Optional notes about the laundry process">{{ old('worker_remarks', $job->worker_remarks) }}</textarea>
        </label>

        <button class="button primary ui-pressable">Confirm Laundry Complete &amp; Ready for SPMU</button>
    </form>
</section>
@endif

@if($job->status === 'READY_FOR_SPMU_RETURN')
<section class="content-area narrow">
    <article class="card attention-card">
        <div class="card-header">
            <div><p class="eyebrow">Action 3</p><h2>Bring cleaned linen directly to SPMU</h2></div>
            <x-status-badge status="READY_FOR_SPMU_RETURN" />
        </div>
        <div class="callout success">
            <strong>Laundry processing is complete.</strong>
            <p>Bring the cleaned linen and the same physical Laundry Form directly to SPMU. Do not release the linen back to the Borrower.</p>
        </div>
        <p>
            Continue to the final quantity/condition inspection. The SPMU Head or authorized SPMU signatory signs the final receiving/acceptance portion of the physical form. The SPMU Action Officer archives the fully accomplished form after final acceptance.
        </p>
    </article>
</section>
@endif

@if(in_array($job->status, ['AWAITING_FINAL_FORM_UPLOAD', 'FORM_REPLACEMENT_REQUIRED'], true))
<section class="content-area narrow">
    <article class="card attention-card">
        <div class="card-header">
            <div><p class="eyebrow">Final archive</p><h2>SPMU is completing the Laundry record</h2></div>
            <x-status-badge :status="$job->status" />
        </div>
        <p>The final accomplished Laundry Form is archived by the SPMU Action Officer after the cleaned linen has been physically accepted. No separate role upload is required.</p>
    </article>
</section>
@endif

@if($job->status === 'LAUNDRY_COMPLETED')
<section class="content-area narrow">
    <article class="card">
        <div class="card-header"><div><p class="eyebrow">Completed</p><h2>Laundry transaction settled</h2></div><x-status-badge status="LAUNDRY_COMPLETED" /></div>
        <p>SPMU final acceptance was completed and the SPMU Action Officer archived the fully accomplished physical Laundry Form.</p>
        @if($job->completed_at)<p class="meta">Completed {{ $job->completed_at->format('d M Y, g:i A') }}</p>@endif
    </article>
</section>
@endif

@if($job->latestEvidence)
<section class="content-area narrow">
    <article class="card">
        <div class="card-header"><div><p class="eyebrow">Final archive</p><h2>Fully accomplished Laundry Form</h2></div><x-status-badge :status="$job->latestEvidence->verification_status" /></div>
        <a class="button secondary small ui-pressable" href="{{ route('files.show', $job->latestEvidence->file, false) }}" target="_blank" rel="noopener">View Uploaded Final Form</a>
        @if($job->latestEvidence->rejection_reason)<div class="callout warning top-gap"><strong>Remark</strong><p>{{ $job->latestEvidence->rejection_reason }}</p></div>@endif
    </article>
</section>
@endif
@endsection
