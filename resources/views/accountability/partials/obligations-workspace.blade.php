@php
    /*
    |--------------------------------------------------------------------------
    | Borrower - My Obligations
    |--------------------------------------------------------------------------
    |
    | Borrower-facing presentation groups related technical records into one
    | obligation. Example:
    |
    | Property Incident + Billing Statement + Borrowing Restriction
    | = ONE borrower obligation.
    |
    | The underlying records remain separate in the database and continue to
    | be available to SPMU/Admin for audit, enforcement, billing, and reports.
    |
    | Row grouping/action-state logic lives in BorrowerObligationService so
    | the borrower dashboard's counts and this workspace's counts can never
    | disagree - both read the one implementation.
    |
    */

    $obligationRows = app(\App\Services\BorrowerObligationService::class)->buildRows(
        $openIncidents,
        $openOverdueCases,
        $openBillings,
        $activeRestrictions
    );

    $obligationCount = count($obligationRows);
    $needsActionCount = collect($obligationRows)
        ->where('action_state', \App\Services\BorrowerObligationService::ACTION_BORROWER)
        ->count();
    $processingCount = collect($obligationRows)
        ->where('action_state', \App\Services\BorrowerObligationService::ACTION_PROCESSING)
        ->count();
    $resolvedCount = $resolvedHistory->count();

    /*
     * Four borrower-facing cards: how many grouped obligations exist, how
     * many need the borrower to do something right now, how many are simply
     * being processed by SPMU, and how many have already been cleared.
     * Resolved records never contribute to the first three.
     */
    $summaryCards = [
        ['info', 'accountability', $obligationCount, 'Outstanding Obligations', 'Unresolved accountability matters'],
        ['warning', 'warning', $needsActionCount, 'Needs My Action', $needsActionCount ? 'Requires your response' : 'Nothing requires your action'],
        ['orange', 'clock', $processingCount, 'Under SPMU Processing', $processingCount ? 'Being processed by SPMU' : 'Nothing currently processing'],
        ['success', 'check-circle', $resolvedCount, 'Resolved History', $resolvedCount ? 'Previously cleared records' : 'No resolved records yet'],
    ];
@endphp

@include('accountability.partials.obligations-styles')

<section class="content-area ob-workspace" data-borrower-accountability-clean>
    <div class="ob-section-heading">
        <div>
            <span>Accountability summary</span>
            <p>Related records arising from one accountability matter are grouped as a single obligation.</p>
        </div>
    </div>

    <div class="ob-summary" aria-label="Accountability record summary">
        @foreach($summaryCards as [$tone, $icon, $value, $label, $note])
            <article class="ob-summary-card is-{{ $tone }} {{ $value === 0 ? 'is-empty' : '' }}">
                <span class="ob-summary-icon" aria-hidden="true">
                    <x-icon :name="$icon" size="22" />
                </span>
                <span class="ob-summary-label">{{ $label }}</span>
                <strong class="ob-summary-value">{{ $value }}</strong>
                <span class="ob-summary-note">{{ $note }}</span>
            </article>
        @endforeach
    </div>

    <div class="ob-current-header">
        <div>
            <span>Outstanding obligations</span>
            <h2>{{ $obligationCount }} {{ $obligationCount === 1 ? 'outstanding obligation' : 'outstanding obligations' }}</h2>
        </div>

        @if($obligationCount > 1)
            <label class="ob-search">
                <x-icon name="search" size="17" />
                <input
                    type="search"
                    placeholder="Search obligations"
                    autocomplete="off"
                    data-obligation-clean-search
                >
            </label>
        @endif
    </div>

    @if($obligationCount > 0)
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
                            <span>Borrowing privileges are temporarily restricted until this obligation is resolved.</span>
                        </div>
                    @endif

                    <div class="ob-next-action is-{{ $row['next_tone'] }}">
                        <span>Required action</span>
                        <strong>{{ $row['next_action'] }}</strong>
                    </div>

                    <div class="ob-case-actions">
                        @foreach($row['actions'] as [$label, $url, $newTab, $buttonTone])
                            <a
                                class="button {{ $buttonTone === 'primary' ? 'primary' : 'secondary' }} small ui-pressable"
                                href="{{ $url }}"
                                @if($newTab) target="_blank" rel="noopener" @endif
                            >
                                {{ $label }}
                            </a>
                        @endforeach

                        <details class="ob-details">
                            <summary><span>View Details</span><x-icon name="chevron-down" size="14" class="ob-disclosure-chevron" /></summary>
                            <div class="ob-detail-grid">
                                @foreach($row['facts'] as [$factLabel, $factValue])
                                    <div>
                                        <small>{{ $factLabel }}</small>
                                        <strong>{{ $factValue }}</strong>
                                    </div>
                                @endforeach
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
                <strong>No unresolved obligations.</strong>
                <p>You currently have no outstanding accountability matters affecting your borrowing privileges.</p>
            </div>
        </article>
    @endif

    <details class="ob-resolved-history-disclosure">
        <summary>
            <span>Resolved History</span>
            <span class="ob-resolved-history-meta">
                <span class="ob-badge is-neutral">{{ $resolvedCount }} {{ $resolvedCount === 1 ? 'record' : 'records' }}</span>
                <x-icon name="chevron-down" size="16" class="ob-disclosure-chevron" />
            </span>
        </summary>
        <div class="ob-resolved-history-body">
            @include('accountability.partials.resolved-history')
        </div>
    </details>
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
