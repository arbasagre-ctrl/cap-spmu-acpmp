@php
    /*
    |--------------------------------------------------------------------------
    | Borrower - My Obligations
    |--------------------------------------------------------------------------
    |
    | Related technical records are intentionally grouped into one borrower
    | obligation. A Property Incident + linked Billing + linked Restriction is
    | one obligation here, not three separate cards. The grouping/action-state
    | comes from BorrowerObligationService, which is also used by the borrower
    | dashboard so both screens always agree.
    */

    $obligationRows = app(\App\Services\BorrowerObligationService::class)->buildRows(
        $openIncidents,
        $openOverdueCases,
        $openBillings,
        $activeRestrictions
    );

    $obligationCollection = collect($obligationRows);
    $obligationCount = $obligationCollection->count();
    $needsActionCount = $obligationCollection
        ->where('action_state', \App\Services\BorrowerObligationService::ACTION_BORROWER)
        ->count();
    $resolvedCount = $resolvedHistory->count();

    $outstandingBalance = (float) $openBillings->sum(function ($billing): float {
        $verifiedPayments = $billing->payments
            ->where('status', 'VERIFIED')
            ->sum(fn ($payment): float => (float) $payment->amount);

        return max(0, (float) $billing->total_amount - $verifiedPayments);
    });

    $activeRestrictionCount = $activeRestrictions->count();
    $borrowingStatus = $activeRestrictionCount > 0 ? 'Restricted' : 'Clear';

    /* Current-account cards only. Resolved records stay in the collapsed
       history section below instead of competing with current obligations. */
    $summaryCards = [
        ['info', 'accountability', (string) $obligationCount, 'Active Obligations', $obligationCount ? 'Unresolved matters on your account' : 'No unresolved obligations'],
        ['warning', 'warning', (string) $needsActionCount, 'Needs My Action', $needsActionCount ? 'Requires your response' : 'No action required from you'],
        ['orange', 'coins', 'PHP '.number_format($outstandingBalance, 2), 'Amount Due', $outstandingBalance > 0 ? 'Outstanding verified balance' : 'No payment currently due'],
        [$activeRestrictionCount > 0 ? 'warning' : 'success', $activeRestrictionCount > 0 ? 'lock' : 'check-circle', $borrowingStatus, 'Borrowing Status', $activeRestrictionCount > 0 ? 'A borrowing restriction is active' : 'No active borrowing restriction'],
    ];
@endphp

@include('accountability.partials.obligations-styles')

<section class="content-area ob-workspace" data-borrower-accountability-clean>
    <div class="ob-summary" aria-label="My obligations overview">
        @foreach($summaryCards as [$tone, $icon, $value, $label, $note])
            <article class="ob-summary-card is-{{ $tone }}">
                <span class="ob-summary-icon" aria-hidden="true">
                    <x-icon :name="$icon" size="20" />
                </span>
                <span class="ob-summary-label">{{ $label }}</span>
                <strong class="ob-summary-value">{{ $value }}</strong>
                <span class="ob-summary-note">{{ $note }}</span>
            </article>
        @endforeach
    </div>

    @if($obligationCount > 0)
        <div class="ob-current-header">
            <h2>
                Current Obligations
                <span class="accountability-count-chip">{{ $obligationCount }}</span>
            </h2>

            @if($obligationCount > 5)
                <label class="ob-search">
                    <span>Search</span>
                    <span class="search-input-shell">
                        <span class="search-input-icon" aria-hidden="true"><x-icon name="search" size="17" /></span>
                        <input
                            type="search"
                            placeholder="Search obligations..."
                            autocomplete="off"
                            data-obligation-clean-search
                        >
                    </span>
                </label>
            @endif
        </div>

        <div class="ob-case-list" data-obligation-clean-list>
            @foreach($obligationRows as $index => $row)
                <article
                    class="ob-case-card"
                    data-obligation-clean-row
                    data-search="{{ $row['search'] }}"
                >
                    <div class="ob-case-top">
                        <div class="ob-case-identity">
                            <span class="ob-case-icon is-{{ $row['tone'] }}" aria-hidden="true">
                                <x-icon :name="$row['icon']" size="20" />
                            </span>

                            <div>
                                <span class="ob-case-type">{{ $row['type'] }}</span>
                                <h3>{{ $row['title'] }}</h3>
                                <small>{{ $row['reference'] }}</small>
                            </div>
                        </div>

                        <div class="ob-case-state">
                            <span class="ob-badge is-{{ $row['badge_tone'] }}">{{ $row['badge'] }}</span>
                            @if($row['status_meta'])
                                <strong>{{ $row['status_meta'] }}</strong>
                            @endif
                        </div>
                    </div>

                    <p class="ob-case-summary">{{ $row['summary'] }}</p>

                    @if($row['restricted'])
                        <div class="ob-linked-restriction">
                            <x-icon name="lock" size="15" />
                            <span>Borrowing restricted until resolved.</span>
                        </div>
                    @endif

                    <div class="ob-next-action is-{{ $row['next_tone'] }}">
                        <span>{{ $row['action_state'] === \App\Services\BorrowerObligationService::ACTION_BORROWER ? 'Next step' : 'Current status' }}</span>
                        <strong>{{ $row['next_action'] }}</strong>
                    </div>

                    @if($row['rslddp_upload_incident_id'] ?? null)
                        <details class="ob-details" open>
                            <summary><span>Upload Accomplished RSLDDP</span><x-icon name="chevron-down" size="14" class="ob-disclosure-chevron" /></summary>
                            <div class="ob-detail-body">
                                <form method="post" action="{{ route('incidents.rslddp.upload', $row['rslddp_upload_incident_id']) }}" enctype="multipart/form-data" class="form-grid">
                                    @csrf
                                    <label>Accomplished/Notarized RSLDDP Scan<input type="file" name="evidence" accept="application/pdf,image/png,image/jpeg,image/webp" required></label>
                                    <button class="button primary">Upload Accomplished RSLDDP</button>
                                </form>
                            </div>
                        </details>
                    @endif

                    @php
                        /*
                         * One obligation = one obvious primary action: View
                         * Obligation. Every document/reference action this row
                         * carries only appears after it is opened, grouped by
                         * what it actually is, instead of a row of buttons
                         * competing with each other on the collapsed card.
                         */
                        $documentActions = collect($row['actions'])->filter(fn ($action) => ($action[4] ?? 'document') === 'document');
                        $referenceActions = collect($row['actions'])->filter(fn ($action) => ($action[4] ?? 'document') === 'reference');
                        $obligationRestriction = $row['restricted'] ? ($row['restriction'] ?? null) : null;
                        $hasHistoryFacts = ! empty($row['facts']);
                    @endphp

                    <div class="ob-case-actions">
                        <details class="ob-details">
                            <summary><span>View Details</span><x-icon name="chevron-down" size="14" class="ob-disclosure-chevron" /></summary>
                            <div class="ob-detail-body">
                                @if($documentActions->isNotEmpty())
                                    <div class="ob-detail-section">
                                        <p class="ob-detail-section-heading">Documents</p>
                                        <div class="ob-document-list">
                                            @foreach($documentActions as $documentAction)
                                                @php
                                                    [$label, $url, $newTab, $buttonTone] = $documentAction;
                                                    $documentName = $documentAction[5] ?? 'Document';
                                                @endphp
                                                <div class="ob-document-item">
                                                    <span class="ob-document-name">
                                                        <x-icon name="document" size="14" />
                                                        <strong>{{ $documentName }}</strong>
                                                    </span>
                                                    <a
                                                        class="table-action ui-pressable"
                                                        href="{{ $url }}"
                                                        @if($newTab) target="_blank" rel="noopener" @endif
                                                    >
                                                        {{ $label }} <span aria-hidden="true">→</span>
                                                    </a>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                @if($referenceActions->isNotEmpty())
                                    <div class="ob-detail-section">
                                        <p class="ob-detail-section-heading">Borrowing Reference</p>
                                        <div class="ob-detail-actions">
                                            @foreach($referenceActions as [$label, $url, $newTab, $buttonTone])
                                                <a
                                                    class="button {{ $buttonTone === 'primary' ? 'primary' : 'secondary' }} small ui-pressable"
                                                    href="{{ $url }}"
                                                    @if($newTab) target="_blank" rel="noopener" @endif
                                                >
                                                    {{ $label }}
                                                </a>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                @if($obligationRestriction)
                                    <div class="ob-detail-section">
                                        <p class="ob-detail-section-heading">Restriction</p>
                                        <div class="ob-detail-grid">
                                            <div>
                                                <small>Reason</small>
                                                <strong>{{ $obligationRestriction->reason ?: str($obligationRestriction->restriction_type)->replace('_', ' ')->title() }}</strong>
                                            </div>
                                            <div>
                                                <small>Effective From</small>
                                                <strong>{{ optional($obligationRestriction->effective_from)->format('d M Y') ?: '—' }}</strong>
                                            </div>
                                            <div>
                                                <small>Status</small>
                                                <strong>{{ $obligationRestriction->effective_to ? 'In effect until '.$obligationRestriction->effective_to->format('d M Y') : 'Active until resolved' }}</strong>
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                @if($hasHistoryFacts)
                                    <div class="ob-detail-section">
                                        <p class="ob-detail-section-heading">History / Details</p>
                                        <div class="ob-detail-grid">
                                            @foreach($row['facts'] as [$factLabel, $factValue])
                                                <div>
                                                    <small>{{ $factLabel }}</small>
                                                    <strong>{{ $factValue }}</strong>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </details>
                    </div>
                </article>
            @endforeach
        </div>

        <div class="ob-no-match" data-obligation-clean-empty hidden>
            No outstanding obligations match the search criteria.
        </div>
    @else
        <article class="ob-clear-card">
            <span aria-hidden="true"><x-icon name="check-circle" size="34" /></span>
            <div>
                <strong>No unresolved obligations</strong>
                <p>No property, payment, late-return, or borrowing restriction currently requires your action.</p>
            </div>
        </article>
    @endif

    @if($resolvedCount > 0)
        <details class="ob-resolved-history-disclosure">
            <summary>
                <span>Resolved Obligations</span>
                <span class="ob-resolved-history-meta">
                    <span class="ob-badge is-neutral">{{ $resolvedCount }} {{ $resolvedCount === 1 ? 'record' : 'records' }}</span>
                    <x-icon name="chevron-down" size="16" class="ob-disclosure-chevron" />
                </span>
            </summary>
            <div class="ob-resolved-history-body">
                @include('accountability.partials.resolved-history')
            </div>
        </details>
    @endif
</section>

<script>
(() => {
    const workspace = document.querySelector('[data-borrower-accountability-clean]');
    if (!workspace) return;

    const input = workspace.querySelector('[data-obligation-clean-search]');
    const rows = Array.from(workspace.querySelectorAll('[data-obligation-clean-row]'));
    const empty = workspace.querySelector('[data-obligation-clean-empty]');

    if (!input || rows.length === 0) return;

    input.addEventListener('input', () => {
        const query = input.value.trim().toLowerCase();
        let visible = 0;

        rows.forEach((row) => {
            const matches = !query || (row.dataset.search || '').includes(query);
            row.hidden = !matches;
            if (matches) visible += 1;
        });

        if (empty) empty.hidden = visible !== 0;
    });
})();
</script>
