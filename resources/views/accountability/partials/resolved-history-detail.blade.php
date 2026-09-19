{{--
    Shared expanded content for one resolved history row/card.

    Included by resolved-history.blade.php from both the borrower's
    <details> card and the staff table's detail row, so the same facts never
    have to be maintained in two places. Expects $case, $incident, $billing,
    $payment, $fromLaundry, $amountLabel, $lateReturnNotice, $showsStaffIdentity,
    and $row to already be set by the caller.
--}}
@if($incident)
    <dl class="accountability-case-facts top-gap">
        <div>
            <dt>Incident</dt>
            <dd>{{ $incident->incident_no ?: '—' }}</dd>
        </div>
        <div>
            <dt>Finding</dt>
            <dd>{{ str($incident->incident_type)->replace('_', ' ')->title() }}</dd>
        </div>
        <div>
            <dt>Custody</dt>
            <dd>{{ $incident->custody?->custody_no ?: '—' }}</dd>
        </div>
        <div>
            <dt>Final Status</dt>
            <dd><x-status-badge :status="$incident->status" /></dd>
        </div>
    </dl>
@endif

@if($case)
    <dl class="accountability-case-facts top-gap">
        <div>
            <dt>Expected Return</dt>
            <dd>{{ optional($case->grace_expires_at)->format('d M Y') ?: '—' }}</dd>
        </div>
        <div>
            <dt>{{ $fromLaundry ? 'Laundry Received' : 'Actual Return' }}</dt>
            <dd>{{ optional($case->actual_return_date)->format('d M Y') ?: '—' }}</dd>
        </div>
        <div>
            <dt>Late Days</dt>
            <dd>{{ $case->late_days === null ? '—' : $case->late_days }}</dd>
        </div>
        <div>
            <dt>Final Late Return Fee</dt>
            <dd>{{ $amountLabel }}</dd>
        </div>
    </dl>
@endif

<div class="resolved-history-detail">
    @if($case?->ao_confirmed_at)
        <section>
            <h4>AO Confirmation</h4>
            <p>
                {{ $showsStaffIdentity ? ($case->confirmedBy?->full_name ?? 'Action Officer') : 'SPMU Action Officer' }}
                &middot; {{ $case->ao_confirmed_at->format('d M Y, h:i A') }}
            </p>
        </section>
    @endif

    @if($billing)
        <section>
            <h4>Head Approval</h4>
            <p>
                {{ $showsStaffIdentity ? ($billing->responsibleSpmuUser?->full_name ?? 'SPMU Head') : 'SPMU Head/Admin' }}
                &middot; {{ optional($billing->issued_at)->format('d M Y, h:i A') }}
            </p>
            <p class="resolved-history-note">
                Billing {{ $billing->billing_no }} &middot; <x-status-badge :status="$billing->status" />
            </p>
        </section>
    @endif

    @if($payment)
        <section>
            <h4>Payment</h4>
            <dl class="resolved-history-payment">
                <div>
                    <dt>Official Receipt No.</dt>
                    <dd>{{ $payment->official_receipt_no }}</dd>
                </div>
                <div>
                    <dt>Receipt Date</dt>
                    <dd>{{ optional($payment->receipt_date)->format('d M Y') ?: '—' }}</dd>
                </div>
                <div>
                    <dt>Amount Paid</dt>
                    <dd>PHP {{ number_format((float) $payment->amount, 2) }}</dd>
                </div>
                <div>
                    <dt>Verified By</dt>
                    <dd>{{ $showsStaffIdentity ? ($payment->verifiedBy?->full_name ?? '—') : 'SPMU Action Officer' }}</dd>
                </div>
                <div>
                    <dt>Verified At</dt>
                    <dd>{{ optional($payment->verified_at)->format('d M Y, h:i A') ?: '—' }}</dd>
                </div>
            </dl>
        </section>
    @endif

    <section>
        <h4>Resolution</h4>
        <p>
            {{ $row['outcome'] }}
            @if($row['resolved_at'])
                &middot; {{ \Carbon\Carbon::parse($row['resolved_at'])->format('d M Y, h:i A') }}
            @endif
        </p>
    </section>
</div>

@if($lateReturnNotice || $billing || $payment?->evidence_file_id)
    <div class="resolved-history-links">
        @if($lateReturnNotice)
            <a href="{{ route('documents.preview', $lateReturnNotice) }}">
                <x-icon name="external-link" size="15" />
                Preview
            </a>
        @endif

        @foreach(($billing?->documents ?? collect())->whereNotIn('status', ['SUPERSEDED', 'INVALIDATED', 'EXPIRED']) as $document)
            <a href="{{ route('documents.preview', $document) }}">
                <x-icon name="external-link" size="15" />
                Preview
            </a>
        @endforeach

        @if($payment?->evidence_file_id)
            <a href="{{ route('files.preview', $payment->evidence_file_id, false) }}">
                <x-icon name="external-link" size="15" />
                Preview
            </a>
        @endif
    </div>
@endif
