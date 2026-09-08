{{--
    Resolved accountability history.

    Read-only. Every figure comes from records that already exist - the frozen
    late-return assessment on the overdue case, the billing, and its verified
    payment. Nothing here can reopen or change a case, and no outcome is
    relabelled: only a settled billing with a verified payment reads as Paid.
--}}
<section class="content-area" id="resolved-history">
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
        @foreach($resolvedHistory as $row)
            @php
                $case = $row['case'];
                $billing = $row['billing'];
                $payment = $row['payment'];
                $fromLaundry = $case?->return_date_source === 'LAUNDRY_RECEIPT';
            @endphp

            <article class="card top-gap accountability-case-card resolved-history-card">
                <div class="card-header">
                    <div>
                        <strong>{{ $row['reference'] }}</strong>
                        <h3>{{ $row['borrower']?->full_name ?? 'Unknown borrower' }}</h3>
                        <small>Late Return</small>
                    </div>

                    <span class="status-badge status-{{ $row['tone'] }}">{{ $row['outcome'] }}</span>
                </div>

                @if($case)
                    <dl class="accountability-case-facts">
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
                            <dd>{{ $billing ? 'PHP '.number_format((float) $billing->total_amount, 2) : 'No charge' }}</dd>
                        </div>
                    </dl>
                @endif

                <div class="resolved-history-detail">
                    @if($case?->ao_confirmed_at)
                        <section>
                            <h4>AO Confirmation</h4>
                            <p>
                                {{ $case->confirmedBy?->full_name ?? 'Action Officer' }}
                                &middot; {{ $case->ao_confirmed_at->format('d M Y, h:i A') }}
                            </p>
                        </section>
                    @endif

                    @if($billing)
                        <section>
                            <h4>Head Approval</h4>
                            <p>
                                {{ $billing->responsibleSpmuUser?->full_name ?? 'SPMU Head' }}
                                &middot; {{ optional($billing->issued_at)->format('d M Y, h:i A') }}
                            </p>
                            <p class="resolved-history-note">
                                Billing {{ $billing->billing_no }} &middot; {{ $billing->status }}
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
                                    <dd>{{ $payment->verifiedBy?->full_name ?? '—' }}</dd>
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

                @if($billing || $payment?->evidence_file_id)
                    <div class="resolved-history-links">
                        @foreach(($billing?->documents ?? collect()) as $document)
                            <a href="{{ route('documents.view', $document) }}" target="_blank" rel="noopener">
                                <x-icon name="external-link" size="15" />
                                View Late Return Fee Form
                            </a>
                        @endforeach

                        @if($payment?->evidence_file_id)
                            <a href="{{ route('files.show', $payment->evidence_file_id, false) }}" target="_blank" rel="noopener">
                                <x-icon name="external-link" size="15" />
                                View Receipt
                            </a>
                        @endif
                    </div>
                @endif
            </article>
        @endforeach
    @endif
</section>

<style>
.resolved-history-card .accountability-case-facts { margin-top: 12px; }

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
