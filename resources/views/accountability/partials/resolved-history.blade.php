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
            <p></p>
        </div>

        @if($resolvedHistory->isNotEmpty())
            <span class="status-badge status-neutral">
                {{ $resolvedHistory->count() }}
                {{ $resolvedHistory->count() === 1 ? 'record' : 'records' }}
            </span>
        @endif
    </div>

    @if($resolvedHistory->isEmpty())
        <article class="card top-gap accountability-empty resolved-history-empty">
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
