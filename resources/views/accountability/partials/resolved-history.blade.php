{{--
    Resolved accountability history.

    Read-only. Every figure comes from records that already exist - the frozen
    late-return assessment on the overdue case, the billing, and its verified
    payment. Nothing here can reopen or change a case, and no outcome is
    relabelled: only a settled billing with a verified payment reads as Paid.

    Shared by the staff Accountability page and the borrower's My Obligations
    page. Staff (AO/Head) see a compact table, one row per case, matching the
    same universal table used by Current Accountability - this partial is
    the only thing that differs between them. The borrower's own view keeps
    its existing card layout unchanged; that page was not part of this
    redesign.
--}}
@php
    /*
     * Historical records stay secondary to current work: only the most
     * recent rows render open by default, and the rest reveal on request
     * instead of loading a long table/list into view.
     */
    $resolvedHistoryInitialLimit = 10;
@endphp
<section class="content-area" id="resolved-history">
@if($isBorrower ?? false)
    <div class="section-heading accountability-section-heading">
        <div>
            <p class="eyebrow">Closed records</p>
            <h2>Resolved Accountability</h2>
            <p>Read-only. Settled, waived and voided outcomes are shown as they were recorded.</p>
        </div>

        @if($resolvedHistory->isNotEmpty())
            <span class="status-badge status-neutral">
                {{ $resolvedHistory->count() }}
                {{ $resolvedHistory->count() === 1 ? 'record' : 'records' }}
            </span>
        @endif
    </div>

    @if($resolvedHistory->isEmpty())
        <article class="card top-gap accountability-empty">
            <strong>No resolved accountability cases yet.</strong>
            <span>Cases appear here after payment verification or another final resolution.</span>
        </article>
    @else
        @foreach($resolvedHistory as $resolvedHistoryIndex => $row)
            @php
                $case = $row['case'];
                $incident = $row['incident'] ?? null;
                $billing = $row['billing'];
                $payment = $row['payment'];
                $fromLaundry = $case?->return_date_source === 'LAUNDRY_RECEIPT';

                /*
                 * resolvedHistory() only ever builds a row from one of two
                 * sources: a billing tied back to a late-return overdue case
                 * ($case present), or a case that closed with no billing at
                 * all. Anything else - a property-case billing, for example -
                 * has no $case, so it is Property Accountability instead of
                 * Late Return. Nothing here recomputes an outcome; it only
                 * labels the two sources the array already distinguishes.
                 */
                $typeLabel = $case
                    ? (((int) ($case->late_days ?? 0)) > 0
                        ? ($fromLaundry ? 'Laundry-Reported Late Return' : 'Late Return')
                        : 'Overdue Record Cleared')
                    : 'Property Accountability';
                $amountLabel = $billing ? 'PHP '.number_format((float) $billing->total_amount, 2) : 'No charge';
                $lateReturnNotice = $case?->documents
                    ?->where('document_type', 'LATE_RETURN_NOTICE')
                    ->whereNotIn('status', ['SUPERSEDED', 'INVALIDATED', 'EXPIRED'])
                    ->sortByDesc('generated_at')
                    ->first();

                /*
                 * This partial is shared by the AO/Head Accountability page
                 * and Borrower My Obligations. Which staff member handled a
                 * step is an internal operational detail, not a fact about
                 * the borrower's own case, so it is shown only to SPMU staff.
                 */
                $showsStaffIdentity = ! ($isBorrower ?? false);
            @endphp

            <article
                class="card top-gap accountability-case-card resolved-history-card"
                @if($resolvedHistoryIndex >= $resolvedHistoryInitialLimit) hidden data-resolved-history-extra @endif
            >
                <div class="card-header">
                    <div>
                        <strong>{{ $row['reference'] }}</strong>
                        <h3>{{ $row['borrower']?->full_name ?? 'Unknown borrower' }}</h3>
                        <small>{{ $typeLabel }}</small>
                    </div>

                    <x-status-badge :status="$row['tone'] === 'success' ? 'COMPLETED' : 'CANCELLED'" :label="$row['outcome']" />
                </div>

                <div class="resolved-history-summary">
                    <span>{{ $amountLabel }}</span>
                    @if($row['resolved_at'])
                        <span>Resolved {{ \Carbon\Carbon::parse($row['resolved_at'])->format('d M Y') }}</span>
                    @endif
                </div>

                <details class="accountability-case-details">
                    <summary class="resolved-history-detail-toggle"><span>View Details</span><x-icon name="chevron-down" size="14" class="resolved-history-chevron" /></summary>
                    @include('accountability.partials.resolved-history-detail')
                </details>
            </article>
        @endforeach

        @if($resolvedHistory->count() > $resolvedHistoryInitialLimit)
            <button type="button" class="button secondary top-gap" id="resolved-history-show-more">
                Show {{ $resolvedHistory->count() - $resolvedHistoryInitialLimit }} More
            </button>
            <script>
            (() => {
                const button = document.getElementById('resolved-history-show-more');
                if (!button) return;
                button.addEventListener('click', () => {
                    document.querySelectorAll('[data-resolved-history-extra]').forEach(row => { row.hidden = false; });
                    button.remove();
                }, { once: true });
            })();
            </script>
        @endif
    @endif
@else
    <article class="card accountability-cases-card">
        <div class="accountability-cases-head">
            <h2>
                Resolved Accountability
                @if($resolvedHistory->isNotEmpty())
                    <span class="accountability-count-chip">{{ $resolvedHistory->count() }}</span>
                @endif
            </h2>
        </div>

        @if($resolvedHistory->isEmpty())
            <div class="empty-state">
                <div>
                    <strong>No resolved accountability cases yet.</strong>
                    <p>Cases appear here after payment verification or another final resolution.</p>
                </div>
            </div>
        @else
            <div class="table-wrap accountability-cases-table">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Reference / Borrower</th>
                            <th scope="col">Case Type</th>
                            <th scope="col">Outcome</th>
                            <th scope="col" class="is-numeric">Amount</th>
                            <th scope="col">Resolved Date</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($resolvedHistory as $resolvedHistoryIndex => $row)
                            @php
                                $case = $row['case'];
                                $incident = $row['incident'] ?? null;
                                $billing = $row['billing'];
                                $payment = $row['payment'];
                                $fromLaundry = $case?->return_date_source === 'LAUNDRY_RECEIPT';
                                $typeLabel = $case
                                    ? (((int) ($case->late_days ?? 0)) > 0
                                        ? ($fromLaundry ? 'Laundry-Reported Late Return' : 'Late Return')
                                        : 'Overdue Record Cleared')
                                    : 'Property Accountability';
                                $amountLabel = $billing ? 'PHP '.number_format((float) $billing->total_amount, 2) : 'No charge';
                                $lateReturnNotice = $case?->documents
                                    ?->where('document_type', 'LATE_RETURN_NOTICE')
                                    ->whereNotIn('status', ['SUPERSEDED', 'INVALIDATED', 'EXPIRED'])
                                    ->sortByDesc('generated_at')
                                    ->first();
                                $showsStaffIdentity = ! ($isBorrower ?? false);
                            @endphp
                            <tr
                                class="accountability-case-row"
                                @if($resolvedHistoryIndex >= $resolvedHistoryInitialLimit) hidden data-resolved-history-extra @endif
                            >
                                <td>
                                    <span class="accountability-case-ref">{{ $row['reference'] }}</span>
                                    <span class="accountability-case-borrower">{{ $row['borrower']?->full_name ?? 'Unknown borrower' }}</span>
                                </td>
                                <td><span class="accountability-case-type-label">{{ $typeLabel }}</span></td>
                                <td><x-status-badge :status="$row['tone'] === 'success' ? 'COMPLETED' : 'CANCELLED'" :label="$row['outcome']" /></td>
                                <td class="is-numeric">{{ $amountLabel }}</td>
                                <td>{{ $row['resolved_at'] ? \Carbon\Carbon::parse($row['resolved_at'])->format('d M Y') : '—' }}</td>
                                <td>
                                    <button type="button" class="table-action" data-case-toggle aria-expanded="false">
                                        <span>View Details</span>
                                        <x-icon name="chevron-down" size="13" class="accountability-toggle-chevron" />
                                    </button>
                                </td>
                            </tr>
                            <tr class="accountability-case-detail-row" hidden>
                                <td colspan="6">
                                    @include('accountability.partials.resolved-history-detail')
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($resolvedHistory->count() > $resolvedHistoryInitialLimit)
                <div class="actions" style="padding:14px 17px;">
                    <button type="button" class="button secondary" id="resolved-history-show-more">
                        Show {{ $resolvedHistory->count() - $resolvedHistoryInitialLimit }} More
                    </button>
                </div>
                <script>
                (() => {
                    const button = document.getElementById('resolved-history-show-more');
                    if (!button) return;
                    button.addEventListener('click', () => {
                        document.querySelectorAll('[data-resolved-history-extra]').forEach(row => { row.hidden = false; });
                        button.remove();
                    }, { once: true });
                })();
                </script>
            @endif
        @endif
    </article>
@endif
</section>

<style>
.resolved-history-card .accountability-case-facts { margin-top: 12px; }

/* Always-visible compact summary; everything else lives behind "View Details". */
.resolved-history-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 14px;
    margin-top: 8px;
    color: var(--text-secondary);
    font-size: 12px;
    font-weight: 650;
}

.resolved-history-card > .accountability-case-details { margin-top: 12px; }
.resolved-history-detail-toggle { display:inline-flex; align-items:center; gap:6px; list-style:none; }
.resolved-history-detail-toggle::-webkit-details-marker { display:none; }
.resolved-history-chevron { transition:transform .16s ease; }
.accountability-case-details[open] > .resolved-history-detail-toggle .resolved-history-chevron { transform:rotate(180deg); }

.resolved-history-detail {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 16px;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--row-border);
}

.resolved-history-detail h4 {
    margin: 0 0 6px;
    color: var(--text-muted);
    font-size: 10.5px;
    font-weight: 800;
    letter-spacing: .06em;
    text-transform: uppercase;
}

.resolved-history-detail p {
    margin: 0;
    color: var(--heading);
    font-size: 12.5px;
    line-height: 1.6;
    overflow-wrap: anywhere;
}

.resolved-history-note { color: var(--text-muted) !important; font-size: 11.5px !important; }

.resolved-history-payment { display: grid; gap: 7px; margin: 0; }
.resolved-history-payment > div { display: grid; gap: 1px; }
.resolved-history-payment dt { color: var(--text-muted); font-size: 11px; }

/* Receipt numbers can be long, so they wrap instead of widening the page. */
.resolved-history-payment dd {
    margin: 0;
    color: var(--heading);
    font-size: 12.5px;
    font-weight: 650;
    overflow-wrap: anywhere;
}

.resolved-history-links {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid var(--row-border);
}

.resolved-history-links a {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    color: var(--interactive);
    font-size: 12.5px;
    font-weight: 700;
    text-decoration: none;
}

.resolved-history-links a:hover { text-decoration: underline; }
</style>
