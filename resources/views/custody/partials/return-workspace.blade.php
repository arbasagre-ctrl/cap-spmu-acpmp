@php
    /*
     * Return workflow:
     * non-linen is physically inspected by the Action Officer. Linen goes to
     * the Laundry Area first. Laundry Personnel are an offline actor: they
     * count/check the linen at handover, record the quantity/condition and
     * wet-sign Received by (including the Date row) on the physical Laundry
     * Form and keep it for documentation. The Laundry Worker later delivers
     * that accomplished form directly to SPMU while the linen remains in the
     * Laundry Area for the internal washing cycle. The Action Officer uploads
     * the form and encodes its
     * findings here; there is no Laundry portal login.
     */
    $eligibleReturnLines = $custody->lines->filter(function ($line) {
        return max(
            0,
            (float) $line->actual_released_quantity
                - (float) $line->returned_quantity
        ) > 0;
    });
    $linenLines = $custody->lines->filter(
        fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
    );

    $linenOutstanding = $linenLines->sum(
        fn ($line) => max(0, (float) $line->actual_released_quantity - (float) $line->returned_quantity)
    );

    $linenOperationalStatus = match (true) {
        $linenLines->isEmpty() => 'Not applicable',
        $linenOutstanding > 0 => $laundryJob?->hasVerifiedAccomplishedForm()
            ? 'Form ready'
            : 'Form pending',
        ! $laundryJob => 'Returned',
        $laundryJob->status === 'FOR_LAUNDRY' =>
            $laundryJob->hasVerifiedAccomplishedForm()
                ? 'Form ready'
                : 'Form pending',
        $laundryJob->status === 'TURNED_OVER_TO_LAUNDRY' =>
            'Return encoded',
        $laundryJob->status === 'LAUNDRY_COMPLETED' =>
            'Available',
        default => 'Documentation pending',
    };

    [$linenNextTitle, $linenNextCopy, $linenNextTone] = match (true) {
        $linenLines->isEmpty() => [
            'No Laundry requirement',
            'This custody does not contain laundry-required linen.',
            'info',
        ],
        $linenOutstanding > 0 && $laundryJob?->hasVerifiedAccomplishedForm() => [
            'Encode the final Laundry Form',
            'Laundry Personnel have completed the physical form. Record the returned quantity and condition exactly as written on it.',
            'warning',
        ],
        $linenOutstanding > 0 => [
            'Accomplished Laundry Form pending',
            'The borrower returns the linen and physical Laundry Form to the Laundry Area. The Laundry Worker checks the quantity/condition, wet-signs RECEIVED BY and the Date row, then later delivers the accomplished form directly to SPMU. The recorded Laundry receipt date—not the SPMU upload date—controls lateness.',
            'warning',
        ],
        $laundryJob?->status === 'FOR_LAUNDRY' => [
            'Encoding pending',
            'Use the signed Laundry Form for encoding.',
            'info',
        ],
        $laundryJob?->status === 'TURNED_OVER_TO_LAUNDRY' => [
            'Return encoded',
            'Linen return has been encoded.',
            'success',
        ],
        $laundryJob?->status === 'LAUNDRY_COMPLETED' => [
            'Linen available',
            'Linen is available for future borrowing.',
            'success',
        ],
        default => [
            'Review Laundry Operations',
            'Check the Laundry case for the next action.',
            'info',
        ],
    };

    // Flash only once after an action. Do not recreate a release notice from
    // custody state on every page load; that made the banner look permanent.
    $returnFlashMessage = session('status');

    /*
     * A pending accomplished Gate Pass is a documentation requirement, not a
     * property/accountability obligation, once all physical property is back.
     * Keep real late-return, property-condition, and billing cases separate.
     */
    $hasNonGoodReturnFinding = $custody->returns
        ->flatMap(fn ($return) => $return->lines ?? collect())
        ->contains(fn ($line) => strtoupper((string) ($line->condition_code ?? 'FINE')) !== 'FINE');

    $hasLateReturnFinding = $custody->returns->contains(
        fn ($return) => in_array(
            strtoupper((string) ($return->return_type ?? 'NORMAL')),
            ['LATE', 'LATE_RETURN', 'OVERDUE'],
            true
        )
    );

    $gatePassDocumentationPending =
        $hasOffCampusItem
        && (float) $outstandingTotal <= 0
        && $custody->gatePass
        && ! $custody->gatePass?->accomplishedFile
        && ! $hasNonGoodReturnFinding
        && ! $hasLateReturnFinding
        && $relatedBillings->isEmpty();

    $returnWorkflowStatus = $custody->workflowStatus();
    $returnAccountability = $custody->activeAccountabilityIndicator();
@endphp

@include('custody.partials.return-process-styles')

<div class="return-workspace-grid">
    <div class="return-primary-stack">
        @if($useReturnProcessLayout)
            @if($returnFlashMessage)
                <div class="notice success return-flash" role="status" data-return-flash>
                    <x-icon name="success" size="22" />
                    <div>{{ $returnFlashMessage }}</div>
                    <button class="icon-button return-flash-dismiss" type="button" aria-label="Dismiss notification" data-return-flash-dismiss><x-icon name="close" size="18" /></button>
                </div>
            @endif
            @if($errors->any())
                <div class="notice error" role="alert"><x-icon name="error" /><div><strong>Please correct the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>
            @endif
        @endif

        <section class="content-grid two return-context-grid" id="return-summary">
            <article class="card return-context-card">
                <div class="card-header">
                    <div>
                        <p class="eyebrow">Transaction summary</p>
                        <h2>Borrowing context</h2>
                    </div>
                </div>

                <dl class="detail-list compact-detail-list">
                    <dt><x-icon name="requests" size="20" />Purpose / Event</dt>
                    <dd>{{ $version?->purpose_event ?: '—' }}</dd>

                    <dt><x-icon name="calendar" size="20" />Scheduled Use</dt>
                    <dd>{{ $scheduleDate?->format('d F Y') ?: '—' }}</dd>

                    <dt><x-icon name="calendar" size="20" />Expected Return</dt>
                    <dd>{{ $returnDate?->format('d F Y') ?: '—' }}</dd>

                    <dt><x-icon name="location" size="20" />Use Location</dt>
                    <dd>{{ $hasOffCampusItem ? 'Includes off-campus use' : 'On-campus only' }}</dd>
                </dl>
            </article>

            <article class="card return-context-card return-documents-card">
                <div class="card-header">
                    <div>
                        <p class="eyebrow">Operational documents</p>
                        <h2>Required documents</h2>
                    </div>
                </div>

                <div class="return-document-list">
                    <div class="return-document-row return-document-row-laundry">
                        <div class="return-document-copy">
                            <x-icon name="linen" size="22" />
                            <div>
                                <strong>Laundry Form</strong>
                                <small>
                                    @if(!$hasLaundryItem)
                                        Not applicable — no linen items.
                                    @elseif($laundryJob?->latestEvidence?->file)
                                        Accomplished form recorded.
                                    @else
                                        Accomplished form pending.
                                    @endif
                                </small>
                            </div>
                        </div>

                        @if(!$hasLaundryItem)
                            <span class="status-badge status-neutral">Locked</span>
                        @elseif($laundryJob?->latestEvidence?->file)
                            <a
                                class="button secondary small ui-pressable"
                                href="{{ route('files.show', $laundryJob->latestEvidence->file, false) }}"
                                target="_blank"
                                rel="noopener"
                            >View Form</a>
                        @elseif($laundryJob)
                            <details
                                class="return-laundry-disclosure"
                                @if($errors->has('evidence') || $errors->has('laundry_received_on')) open @endif
                            >
                                <summary class="button secondary small ui-pressable">
                                    Record Accomplished Form
                                </summary>

                                <form
                                    method="post"
                                    action="{{ route('laundry.spmu.upload-form', $laundryJob) }}"
                                    enctype="multipart/form-data"
                                    class="return-laundry-form-upload"
                                >
                                    @csrf

                                    <label>
                                        Signed Laundry Form
                                        <input
                                            id="return-laundry-form-evidence"
                                            type="file"
                                            name="evidence"
                                            required
                                            accept="application/pdf,image/png,image/jpeg,image/webp"
                                        >
                                    </label>

                                    <label>
                                        Laundry Received Date
                                        <input
                                            id="laundry-actual-return-date"
                                            type="date"
                                            name="laundry_received_on"
                                            value="{{ old('laundry_received_on') }}"
                                            @if($custody->released_at) min="{{ $custody->released_at->toDateString() }}" @endif
                                            data-laundry-return-date
                                            data-due-date="{{ optional($custody->due_at)->toDateString() }}"
                                            required
                                            autocomplete="off"
                                            aria-describedby="laundry-return-date-help laundry-return-date-status"
                                        >
                                        <small id="laundry-return-date-help">Use the RECEIVED BY date on the form.</small>
                                        <small
                                            id="laundry-return-date-status"
                                            data-laundry-return-date-status
                                            aria-live="polite"
                                        ></small>
                                    </label>

                                    <button class="button secondary small ui-pressable" type="submit">
                                        Upload Form
                                    </button>
                                </form>
                            </details>
                        @else
                            <span class="status-badge status-warning">Pending Form</span>
                        @endif
                    </div>

                    <div class="return-document-row">
                        <div class="return-document-copy">
                            <x-icon name="shield-lock" size="22" />
                            <div><strong>Gate Pass</strong>
                            <small>
                                @if(!$hasOffCampusItem)
                                    Not applicable — on-campus only.
                                @elseif($custody->gatePass?->accomplishedFile)
                                    Accomplished copy recorded.
                                @else
                                    Accomplished copy pending.
                                @endif
                            </small></div>
                        </div>
                        @if(!$hasOffCampusItem)
                            <span class="status-badge status-neutral">Locked</span>
                        @elseif($custody->gatePass?->accomplishedFile)
                            <a class="button secondary small ui-pressable" href="{{ route('files.show', $custody->gatePass->accomplishedFile, false) }}" target="_blank" rel="noopener">View Gate Pass</a>
                        @elseif($custody->gatePass)
                            <a class="button secondary small ui-pressable" href="{{ route('gate-passes.show', $custody->gatePass) }}">Record Gate Pass</a>
                        @else
                            <span class="status-badge status-warning">Gate Pass record unavailable</span>
                        @endif
                    </div>

                    <div class="return-document-row">
                        <div class="return-document-copy">
                            <x-icon name="receipt" size="22" />
                            <div><strong>Receipt</strong>
                            <small>{{ $relatedBillings->isNotEmpty() ? 'Payment evidence for a billing linked to this borrowing.' : 'Not applicable — no billing obligation.' }}</small></div>
                        </div>
                        @if($relatedBillings->isEmpty())
                            <span class="status-badge status-neutral">Locked</span>
                        @elseif($latestReceipt?->evidence_file_id)
                            <a class="button secondary small ui-pressable" href="{{ route('files.show', $latestReceipt->evidence_file_id, false) }}" target="_blank" rel="noopener">View receipt</a>
                        @else
                            <span class="status-badge status-warning">Pending receipt upload</span>
                        @endif
                    </div>
                </div>
            </article>
        </section>

        <section class="content-area return-primary-section" id="return-primary">
            @include('custody.partials.return-inspection-form')
        </section>
    </div>

    <aside class="card return-status-card" aria-label="Return status">
        <div class="card-header">
            <p class="eyebrow">Return status</p>
            <div class="return-status-title">
                <h2>Status</h2>
                @if($gatePassDocumentationPending && !$returnAccountability)
                    <span class="status-badge status-warning">Gate Pass Pending</span>
                @elseif($returnWorkflowStatus['key'] !== 'COMPLETED')
                    <x-status-badge :status="$returnWorkflowStatus['key']" :label="$returnWorkflowStatus['label']" />
                @else
                    <x-status-badge status="CLOSED" label="Completed" />
                @endif
            </div>
        </div>

        <div class="return-status-scroll">
            @if($gatePassDocumentationPending)
                <div class="callout warning return-next-callout">
                    <x-icon name="information" size="24" />
                    <div>
                        <strong>Next: Record Gate Pass</strong>
                        <p>All items are already returned. Record the accomplished Gate Pass to complete the remaining documentation.</p>
                        @if($custody->gatePass)
                            <a class="button primary small ui-pressable top-gap" href="{{ route('gate-passes.show', $custody->gatePass) }}">Open Gate Pass</a>
                        @endif
                    </div>
                </div>
            @endif

            <dl class="detail-list compact-detail-list">
                @if($returnAccountability)
                    <dt><x-icon name="warning" size="20" />Accountability</dt>
                    <dd><strong>{{ $returnAccountability['label'] }}</strong></dd>
                @endif

                <dt><x-icon name="calendar" size="20" />Issued</dt>
                <dd>
                    {{ optional($custody->released_at)->format('d M Y, g:i A') ?: '—' }}
                </dd>

                <dt><x-icon name="calendar" size="20" />Expected Return</dt>
                <dd>{{ $returnDate?->format('d M Y') ?: '—' }}</dd>

                <dt><x-icon name="box" size="20" />{{ $custody->status === 'OVERDUE' ? 'Total Overdue' : 'Total On Custody' }}</dt>
                <dd>{{ $outstandingTotal + 0 }}</dd>

                @if($linenLines->isNotEmpty())
                    <dt><x-icon name="requests" size="20" />Linen</dt>
                    <dd>
                        {{ $linenOperationalStatus }}
                        @if($linenOutstanding > 0)
                            · {{ $linenOutstanding + 0 }} outstanding
                        @endif
                    </dd>
                @endif
            </dl>

            @if($linenLines->isNotEmpty())
                @if($linenOutstanding <= 0 && $laundryJob && in_array($laundryJob->status, ['TURNED_OVER_TO_LAUNDRY', 'LAUNDRY_COMPLETED'], true))
                    <a
                        class="button primary ui-pressable"
                        href="{{ route('laundry.show', $laundryJob) }}"
                    >
                        Continue to Laundry Operations
                    </a>
                @endif

                @if($laundryJob?->latestEvidence?->file)
                    <a
                        class="button secondary small ui-pressable"
                        href="{{ route('files.show', $laundryJob->latestEvidence->file, false) }}"
                        target="_blank"
                        rel="noopener"
                    >
                        View Laundry Form
                    </a>
                @endif
            @endif
        </div>
    </aside>
</div>

@if($custody->returns->isNotEmpty())
    <section class="content-area return-history-section">
        <article class="card">
            <div class="card-header">
                <div>
                    <p class="eyebrow">Return history</p>
                    <h2>Recorded inspections</h2>
                </div>
            </div>

            <div class="table-wrap return-history-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Return</th>
                            <th>Received</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($custody->returns->sortByDesc('id') as $return)
                            <tr>
                                <td>{{ $return->return_no }}</td>
                                <td>{{ optional($return->received_at)->format('d M Y, g:i A') ?: '—' }}</td>
                                <td>{{ str($return->return_type ?: 'NORMAL')->replace('_', ' ')->title() }}</td>
                                <td><x-status-badge :status="$return->status" /></td>
                                <td>{{ $return->remarks ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </article>
    </section>
@endif

@include('custody.partials.return-process-interactions')


@once
<style>
.return-flow-page .return-document-row-laundry {
    align-items: flex-start;
}

.return-flow-page .return-laundry-disclosure {
    width: min(100%, 390px);
}

.return-flow-page .return-laundry-disclosure > summary {
    width: max-content;
    max-width: 100%;
    margin-left: auto;
    list-style: none;
    cursor: pointer;
}

.return-flow-page .return-laundry-disclosure > summary::-webkit-details-marker {
    display: none;
}

.return-flow-page .return-laundry-disclosure[open] > summary {
    margin-bottom: 10px;
}

.return-flow-page .return-laundry-form-upload {
    display: grid;
    gap: 10px;
    padding: 12px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--surface-subtle);
}

.return-flow-page .return-laundry-form-upload label {
    display: grid;
    gap: 6px;
    color: var(--heading);
    font-size: 12px;
    font-weight: 700;
}

.return-flow-page .return-laundry-form-upload label small {
    color: var(--text-muted);
    font-size: 11px;
    font-weight: 400;
}

.return-flow-page .return-laundry-form-upload input {
    width: 100%;
}

.return-flow-page .return-laundry-form-upload .button {
    justify-self: start;
}

@media (max-width: 760px) {
    .return-flow-page .return-laundry-disclosure {
        width: 100%;
    }

    .return-flow-page .return-laundry-disclosure > summary {
        margin-left: 0;
    }
}
</style>
@endonce
