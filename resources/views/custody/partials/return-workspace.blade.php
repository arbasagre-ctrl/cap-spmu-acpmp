@php
    /*
     * Revised return workflow:
     * linen and non-linen are inspected together when the borrower
     * physically returns them. Laundry washing is a later internal stock
     * process and does not delay the SPMU return inspection.
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
        $linenOutstanding > 0 => 'Awaiting borrower return inspection',
        ! $laundryJob => 'Returned / accounted',
        $laundryJob->status === 'FOR_LAUNDRY' =>
            $laundryJob->hasVerifiedAccomplishedForm()
                ? 'Laundry receipt confirmed — awaiting SPMU return verification'
                : 'Awaiting Laundry return — borrower returns linen to the Laundry Area first',
        $laundryJob->status === 'TURNED_OVER_TO_LAUNDRY' =>
            'Turned over to Laundry — borrower no longer waits for washing',
        $laundryJob->status === 'LAUNDRY_COMPLETED' =>
            'Laundry completed / available in Laundry Area',
        default => 'Laundry turnover / internal processing',
    };

    [$linenNextTitle, $linenNextCopy, $linenNextTone] = match (true) {
        $linenLines->isEmpty() => [
            'No Laundry requirement',
            'This custody does not contain laundry-required linen.',
            'info',
        ],
        $linenOutstanding > 0 && $laundryJob?->hasVerifiedAccomplishedForm() => [
            'Encode the linen condition from the accomplished Laundry Form',
            'Laundry Personnel already received the linen, recorded the actual quantity and condition, and wet-signed Received by. Record those values in this Return Inspection exactly as written on the verified form.',
            'warning',
        ],
        $linenOutstanding > 0 => [
            'Awaiting Laundry return',
            'The borrower returns the linen to the Laundry Area first with the same printed Laundry Form. After Laundry Personnel records the condition and wet-signs Received by, upload the accomplished form here before the linen return can be finalized.',
            'warning',
        ],
        $laundryJob?->status === 'FOR_LAUNDRY' => [
            'Confirm physical Laundry turnover',
            'The SPMU return verification is recorded. Confirm the Laundry turnover in Laundry Operations so the linen enters the internal washing queue.',
            'warning',
        ],
        $laundryJob?->status === 'TURNED_OVER_TO_LAUNDRY' => [
            'Borrower linen turnover completed',
            'Laundry Personnel have received the linen. Washing may be completed later inside the Laundry Area; the borrower has no further linen action.',
            'success',
        ],
        $laundryJob?->status === 'LAUNDRY_COMPLETED' => [
            'Linen available in Laundry Area',
            'Internal laundry processing is complete. Clean/serviceable linen is now Available for future borrowing in the Laundry Area.',
            'success',
        ],
        default => [
            'Review Laundry Operations',
            'Review the current Laundry case for the next internal action.',
            'info',
        ],
    };

    $returnFlashMessage = session('status');
    if (! $returnFlashMessage && $custody->released_at && $custody->status === 'ACTIVE' && $custody->returns->isEmpty()) {
        $returnFlashMessage = 'Physical release completed. The transaction is now under Return tracking.';
    }
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
                        <h2>Accomplished return documents</h2>
                    </div>
                </div>

                <div class="return-document-list">
                    <div class="return-document-row">
                        <div class="return-document-copy">
                            <x-icon name="linen" size="22" />
                            <div><strong>Laundry Form</strong>
                            <small>{{ $hasLaundryItem ? 'Required before the linen return can be finalized. Signed by Laundry Personnel on physical return.' : 'Not applicable — no linen items.' }}</small></div>
                        </div>
                        @if(!$hasLaundryItem)
                            <span class="status-badge status-neutral">Locked</span>
                        @elseif($laundryJob?->latestEvidence?->file)
                            <a class="button secondary small ui-pressable" href="{{ route('files.show', $laundryJob->latestEvidence->file, false) }}" target="_blank" rel="noopener">View uploaded form</a>
                        @elseif($laundryJob)
                            <form
                                method="post"
                                action="{{ route('laundry.spmu.upload-form', $laundryJob) }}"
                                enctype="multipart/form-data"
                                class="return-laundry-form-upload"
                            >
                                @csrf
                                <label class="visually-hidden" for="return-laundry-form-evidence">Accomplished Laundry Form</label>
                                <input
                                    id="return-laundry-form-evidence"
                                    type="file"
                                    name="evidence"
                                    required
                                    accept="application/pdf,image/png,image/jpeg,image/webp"
                                >
                                <label class="return-laundry-form-attest">
                                    <input type="checkbox" name="laundry_received_signature_confirmed" value="1" required>
                                    <span>I confirm that the uploaded Laundry Form is the accomplished form presented by the borrower and contains the Laundry Personnel RECEIVED BY wet signature, including returned quantity and condition/remarks where applicable.</span>
                                </label>
                                <button class="button secondary small ui-pressable" type="submit">Upload signed form</button>
                            </form>
                        @else
                            <span class="status-badge status-warning">Pending scan</span>
                        @endif
                    </div>

                    <div class="return-document-row">
                        <div class="return-document-copy">
                            <x-icon name="shield-lock" size="22" />
                            <div><strong>Gate Pass</strong>
                            <small>{{ $hasOffCampusItem ? 'Required for approved off-campus use.' : 'Not applicable — on-campus only.' }}</small></div>
                        </div>
                        @if(!$hasOffCampusItem)
                            <span class="status-badge status-neutral">Locked</span>
                        @elseif($custody->gatePass?->accomplishedFile)
                            <a class="button secondary small ui-pressable" href="{{ route('files.show', $custody->gatePass->accomplishedFile, false) }}" target="_blank" rel="noopener">View uploaded gate pass</a>
                        @else
                            <span class="status-badge status-warning">Pending scan</span>
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
                <h2>What needs attention</h2>
                @unless($custody->status === 'CLOSED')
                    <x-status-badge :status="$custody->status" />
                @endunless
            </div>
        </div>

        <div class="return-status-scroll">
            <dl class="detail-list compact-detail-list">
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
                <div
                    class="callout {{ $linenNextTone }} return-next-callout"
                >
                    <x-icon :name="$linenNextTone === 'warning' ? 'warning' : 'information'" size="24" />
                    <div><strong>{{ $linenNextTitle }}</strong>
                    <p>{{ $linenNextCopy }}</p></div>
                </div>

                <a
                    class="button primary ui-pressable"
                    href="{{ $laundryJob ? route('laundry.show', $laundryJob) : route('laundry.index') }}"
                >
                    Open Laundry Operations
                </a>

                @if($laundryJob?->latestEvidence?->file)
                    <a
                        class="button secondary small ui-pressable"
                        href="{{ route('files.show', $laundryJob->latestEvidence->file, false) }}"
                        target="_blank"
                        rel="noopener"
                    >
                        View Archived Laundry Form
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
