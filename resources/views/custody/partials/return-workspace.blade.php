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
     * the form and encodes its findings here; there is no Laundry portal login.
     */
    $eligibleReturnLines = $custody->lines->filter(function ($line) {
        return max(
            0,
            (float) $line->actual_released_quantity - (float) $line->returned_quantity
        ) > 0;
    });

    $linenLines = $custody->lines->filter(
        fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
    );

    $linenOutstanding = $linenLines->sum(
        fn ($line) => max(0, (float) $line->actual_released_quantity - (float) $line->returned_quantity)
    );

    $returnFlashMessage = session('status');

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

    $lateReturnCase = $custody->overdueCase;
    $lateReturnRecorded = $lateReturnCase
        && $lateReturnCase->actual_return_date !== null
        && ! in_array($lateReturnCase->status, [
            App\Services\LateReturnService::STATUS_OVERDUE,
            App\Services\LateReturnService::STATUS_RESOLVED,
        ], true);

    $showLaundryDocument = (bool) $hasLaundryItem;
    $showGatePassDocument = (bool) $hasOffCampusItem;
    $hasOperationalReturnDocuments = $showLaundryDocument || $showGatePassDocument;

    $laundryFormRecorded = (bool) $laundryJob?->latestEvidence?->file;
    $gatePassRecorded = (bool) $custody->gatePass?->accomplishedFile;
@endphp

@include('custody.partials.return-process-styles')

<div class="return-page-stack">
    @if($useReturnProcessLayout)
        @if($returnFlashMessage)
            <div class="notice success return-flash" role="status" data-return-flash>
                <x-icon name="success" size="22" />
                <div>{{ $returnFlashMessage }}</div>
                <button class="icon-button return-flash-dismiss" type="button" aria-label="Dismiss notification" data-return-flash-dismiss><x-icon name="close" size="18" /></button>
            </div>
        @endif
        @if($errors->any())
            <div class="notice error" role="alert">
                <x-icon name="error" />
                <div>
                    <strong>Please correct the following:</strong>
                    <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            </div>
        @endif
    @endif

    <div class="return-top-grid {{ $hasOperationalReturnDocuments ? 'has-documents' : 'summary-only' }}">
        <section class="content-area return-summary-section" id="return-summary">
        <article class="card return-summary-card">
            <div class="card-header return-summary-header">
                <div>
                    <h2>Transaction Summary</h2>
                </div>
                @if($gatePassDocumentationPending && !$returnAccountability)
                    <span class="status-badge status-warning">Awaiting Accomplished Gate Pass</span>
                @elseif($returnWorkflowStatus['key'] !== 'COMPLETED')
                    <x-status-badge :status="$returnWorkflowStatus['key']" :label="$returnWorkflowStatus['label']" />
                @else
                    <x-status-badge status="CLOSED" label="Completed" />
                @endif
            </div>

            <dl class="return-summary-grid">
                <div class="return-summary-item">
                    <dt><x-icon name="requests" size="20" />Purpose / Event</dt>
                    <dd>{{ $version?->purpose_event ?: '—' }}</dd>
                </div>
                <div class="return-summary-item">
                    <dt><x-icon name="calendar" size="20" />Scheduled Use</dt>
                    <dd>{{ $scheduleDate?->format('d F Y') ?: '—' }}</dd>
                </div>
                <div class="return-summary-item">
                    <dt><x-icon name="location" size="20" />Use Location</dt>
                    <dd>{{ $hasOffCampusItem ? 'Includes off-campus use' : 'On-campus only' }}</dd>
                </div>
                <div class="return-summary-item">
                    <dt><x-icon name="calendar" size="20" />{{ $returnDateAdjusted ? 'Effective Return' : 'Expected Return' }}</dt>
                    <dd>
                        {{ $returnDate?->format('d F Y') ?: '—' }}
                        @if($returnDateAdjusted)
                            <small class="meta return-date-adjustment-note">
                                Adjusted from {{ $originalReturnDate?->format('d F Y') ?: '—' }}
                                @if($custody->due_adjustment_reason)
                                    · {{ $custody->due_adjustment_reason }}
                                @endif
                            </small>
                        @endif
                    </dd>
                </div>
                <div class="return-summary-item">
                    <dt><x-icon name="box" size="20" />{{ $custody->status === 'OVERDUE' ? 'Total Overdue' : 'Total On Custody' }}</dt>
                    <dd>{{ $outstandingTotal + 0 }}</dd>
                </div>
                <div class="return-summary-item">
                    <dt><x-icon name="calendar" size="20" />Issued</dt>
                    <dd>{{ optional($custody->released_at)->format('d M Y, g:i A') ?: '—' }}</dd>
                </div>
            </dl>

            @if($returnAccountability || $lateReturnRecorded)
                <div class="return-summary-notes">
                    @if($returnAccountability)
                        <div class="return-summary-note">
                            <strong>Accountability</strong>
                            <span>{{ $returnAccountability['label'] }}</span>
                        </div>
                    @endif
                    @if($lateReturnRecorded)
                        <div class="return-summary-note return-summary-note-action">
                            <div>
                                <strong>Late return recorded</strong>
                                <span>The physical return is already recorded and ready for accountability review.</span>
                            </div>
                            <a class="button secondary small ui-pressable return-navigation-action" href="{{ route('accountability.index') }}">
                                <span>View Accountability</span>
                                <span aria-hidden="true">→</span>
                            </a>
                        </div>
                    @endif
                </div>
            @endif
        </article>
    </section>

    @if($hasOperationalReturnDocuments)
        <section class="content-area return-documents-section" id="return-documents">
            <article class="card return-documents-card return-documents-card--compact">
                <div class="card-header">
                    <div>
                        <h2>Return Documents</h2>
                    </div>
                </div>

                <div class="return-document-list return-document-list--compact">
                    @if($showLaundryDocument)
                        @if($laundryFormRecorded)
                            <div class="return-document-row return-document-row-laundry">
                                <div class="return-document-copy">
                                    <x-icon name="linen" size="22" />
                                    <div>
                                        <strong>Laundry Form</strong>
                                        <small>Accomplished form recorded.</small>
                                    </div>
                                </div>

                                <div class="return-document-actions">
                                    <a class="button secondary small ui-pressable return-navigation-action" href="{{ route('files.preview', $laundryJob->latestEvidence->file) }}">
                                        <span>Preview</span>
                                        <span aria-hidden="true">→</span>
                                    </a>
                                </div>
                            </div>
                        @elseif($laundryJob)
                            <details
                                class="return-laundry-disclosure return-document-disclosure"
                                @if($errors->has('evidence') || $errors->has('laundry_received_on')) open @endif
                            >
                                <summary class="return-document-disclosure-summary">
                                    <div class="return-document-copy">
                                        <x-icon name="linen" size="22" />
                                        <div>
                                            <strong>Laundry Form</strong>
                                            <small>Awaiting accomplished form.</small>
                                        </div>
                                    </div>

                                    <span class="button secondary small ui-pressable return-disclosure-trigger" aria-hidden="true">
                                        <span>Record Form</span>
                                        <x-icon name="chevron-down" size="15" class="return-disclosure-chevron" />
                                    </span>
                                </summary>

                                <form method="post" action="{{ route('laundry.spmu.upload-form', $laundryJob) }}" enctype="multipart/form-data" class="return-laundry-form-upload">
                                    @csrf

                                    <label>
                                        Accomplished Laundry Form
                                        <input
                                            id="return-laundry-form-evidence"
                                            type="file"
                                            name="evidence"
                                            required
                                            accept="application/pdf,image/png,image/jpeg,image/webp"
                                        >
                                        <small>PDF, JPG, PNG, or WEBP</small>
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
                                        <span class="return-laundry-date-meta">
                                            <small id="laundry-return-date-help">Use the RECEIVED BY date written on the accomplished form.</small>
                                            <small id="laundry-return-date-status" class="return-laundry-date-status" data-laundry-return-date-status aria-live="polite"></small>
                                        </span>
                                    </label>

                                    <button class="button secondary small ui-pressable" type="submit">Record Laundry Form</button>
                                </form>
                            </details>
                        @else
                            <div class="return-document-row return-document-row-laundry">
                                <div class="return-document-copy">
                                    <x-icon name="linen" size="22" />
                                    <div>
                                        <strong>Laundry Form</strong>
                                        <small>Awaiting accomplished form.</small>
                                    </div>
                                </div>
                                <span class="status-badge status-warning">Form unavailable</span>
                            </div>
                        @endif
                    @endif

                    @if($showGatePassDocument)
                        <div class="return-document-row">
                            <div class="return-document-copy">
                                <x-icon name="shield-lock" size="22" />
                                <div>
                                    <strong>Gate Pass</strong>
                                    <small>
                                        @if($gatePassRecorded)
                                            Accomplished copy recorded.
                                        @else
                                            Awaiting accomplished copy.
                                        @endif
                                    </small>
                                </div>
                            </div>

                            <div class="return-document-actions">
                                @if($custody->gatePass)
                                    <a class="button secondary small ui-pressable return-navigation-action" href="{{ route('gate-passes.show', $custody->gatePass) }}">
                                        <span>{{ $gatePassRecorded ? 'View Record' : 'Record Gate Pass' }}</span>
                                        <span aria-hidden="true">→</span>
                                    </a>
                                @else
                                    <span class="status-badge status-warning">Gate Pass unavailable</span>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            </article>
        </section>
    @endif
    </div>

    <section class="content-area return-primary-section" id="return-primary">
        @include('custody.partials.return-inspection-form')
    </section>
</div>

@if($custody->returns->isNotEmpty())
    <section class="content-area return-history-section">
        <article class="card">
            <div class="card-header">
                <div>
                    <h2>Return History</h2>
                </div>
            </div>

            <div class="table-wrap return-history-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Return</th>
                            <th>Received</th>
                            <th>Timing</th>
                            <th>Status</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($custody->returns->sortByDesc('id') as $return)
                            <tr>
                                <td>{{ $return->return_no }}</td>
                                <td>{{ optional($return->received_at)->format('d M Y, g:i A') ?: '—' }}</td>
                                @php
                                    $returnTiming = match (strtoupper((string) ($return->return_type ?: 'NORMAL'))) {
                                        'EARLY' => 'Early',
                                        'OVERDUE', 'LATE', 'LATE_RETURN' => 'Late',
                                        default => 'On Time',
                                    };
                                @endphp
                                <td>{{ $returnTiming }}</td>
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
