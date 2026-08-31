@php
    /*
    |--------------------------------------------------------------------------
    | Borrower - My Obligations
    |--------------------------------------------------------------------------
    |
    | Overdue returns, property cases, billings and restrictions are four
    | different models. They are normalised into one row shape here so the
    | borrower reads a single register instead of four stacks of cards, while
    | every row keeps its own record's guidance behind View.
    |
    | Filtering, sorting and the result count are driven by public/js/app.js
    | through the data-accountability-* hooks, so the data attributes below
    | must keep their existing names and raw status values.
    |
    */
    $obligationRows = [];

    /* ---------------------------------------------------------- Overdue --- */
    foreach ($openOverdueCases as $overdue) {
        $custody = $overdue->custody;
        $recordDate = $overdue->overdue_started_at ?: $overdue->created_at;
        $dueAt = $custody?->due_at;

        $lines = $custody?->lines ?? collect();
        $firstLine = $lines->first();
        $firstName = $firstLine?->requestItem?->description_snapshot;
        $firstQuantity = (float) ($firstLine?->actual_released_quantity ?? 0);

        $description = $firstName
            ? $firstName.' ('.($firstQuantity + 0).' pcs)'
            : 'Outstanding issued items';

        if ($lines->count() > 1) {
            $description .= ' +'.($lines->count() - 1).' more';
        }

        $daysLate = $dueAt && now()->greaterThan($dueAt)
            ? (int) $dueAt->diffInDays(now())
            : null;

        $obligationRows[] = [
            'category' => 'overdue',
            'status' => $overdue->status,
            'date' => $recordDate,
            'tone' => 'danger',
            'icon' => 'calendar',
            'type' => 'Overdue Return',
            'reference' => $custody?->custody_no ?: 'No custody reference',
            'reference_sub' => $custody?->request?->request_no,
            'description' => $description,
            'description_sub' => $dueAt ? 'Due date: '.$dueAt->format('d M Y') : null,
            'badge' => 'Overdue',
            'badge_tone' => 'danger',
            'status_sub' => $daysLate !== null
                ? $daysLate.' '.($daysLate === 1 ? 'day' : 'days').' late'
                : null,
            'facts' => [
                ['Expected return', optional($dueAt)->format('d M Y') ?: '—'],
                ['Late fee rate', $overdue->rate_snapshot === null
                    ? 'Not configured'
                    : '₱'.number_format((float) $overdue->rate_snapshot, 2)],
                ['Accrued amount', $overdue->rate_snapshot === null
                    ? 'Not determined'
                    : '₱'.number_format((float) $overdue->accrued_amount, 2)],
            ],
            'action_tone' => 'warning',
            'action_title' => 'Return the outstanding items to SPMU',
            'action_text' => 'Bring the issued items to SPMU for physical return inspection. Do not record the return yourself; the Action Officer confirms the actual quantities and condition during handover.',
            'links' => $custody
                ? [['Open borrowing record', route('custody.show', $custody)]]
                : [],
            'search' => strtolower(implode(' ', [
                'overdue return',
                $custody?->custody_no,
                $custody?->request?->request_no,
                $overdue->status,
                $description,
            ])),
        ];
    }

    /* --------------------------------------------------------- Property --- */
    foreach ($openIncidents as $incident) {
        $recordDate = $incident->reported_at ?: $incident->created_at;
        $incidentType = (string) str($incident->incident_type)->replace('_', ' ')->title();
        $custody = $incident->custody;

        $incidentBilling = $openBillings->first(fn ($billing) => $billing->lines->contains(
            fn ($line) => (int) $line->incident_id === (int) $incident->id
        ));

        $itemName = $custody?->lines?->first()?->requestItem?->description_snapshot;

        $actionTone = 'info';
        $actionTitle = 'No action required yet';
        $actionText = 'SPMU is processing this property case. Wait for a formal billing, waiver, compliance, or case-resolution instruction before taking any payment action.';

        if ($incident->status === 'COMPLIANCE_REQUIRED') {
            $actionTone = 'warning';
            $actionTitle = 'Coordinate the required compliance with SPMU';
            $actionText = 'The SPMU Head requires repair, replacement, or another compliance action. Coordinate directly with SPMU; your linked borrowing restriction stays active until SPMU verifies completion.';
        } elseif ($incident->status === 'FOR_BILLING') {
            $actionTone = 'warning';
            $actionTitle = 'Wait for the Billing Statement';
            $actionText = 'The SPMU Head determined that this case requires billing. No payment is due until SPMU issues the Billing Statement with the approved amount and basis.';
        }

        if ($incidentBilling) {
            if ($incidentBilling->status === 'RECEIPT_SUBMITTED') {
                $actionTone = 'info';
                $actionTitle = 'Wait for SPMU receipt verification';
                $actionText = 'The paid CSPC Cashier receipt has been recorded by SPMU and is awaiting verification. No borrower upload is required.';
            } else {
                $actionTone = 'warning';
                $actionTitle = 'Settle the issued Billing Statement';
                $actionText = 'Download the Billing Statement, pay through the CSPC Cashier, then present the paid official receipt to SPMU for recording and verification.';
            }
        }

        $badge = match ($incident->status) {
            'OPEN' => 'Under Review',
            'FOR_BILLING' => 'For Billing',
            'BILLING_PENDING' => 'Billing Pending',
            'COMPLIANCE_REQUIRED' => 'Compliance Required',
            default => (string) str($incident->status)->replace('_', ' ')->title(),
        };

        $obligationRows[] = [
            'category' => 'property',
            'status' => $incident->status,
            'date' => $recordDate,
            'tone' => 'warning',
            'icon' => 'accountability',
            'type' => 'Property Case',
            'reference' => $incident->incident_no,
            'reference_sub' => 'Case for '.strtolower($incidentType),
            'description' => $itemName ?: $incidentType.' case',
            'description_sub' => $incident->remarks ?: $custody?->custody_no,
            'badge' => $badge,
            'badge_tone' => 'warning',
            'status_sub' => null,
            'facts' => [
                ['Finding', $incidentType],
                ['Custody', $custody?->custody_no ?: '—'],
                ['Affected lines', (string) $incident->lines->count()],
            ],
            'action_tone' => $actionTone,
            'action_title' => $actionTitle,
            'action_text' => $actionText,
            'links' => $custody
                ? [['Open borrowing record', route('custody.show', $custody)]]
                : [],
            'search' => strtolower(implode(' ', [
                'property case',
                $incident->incident_no,
                $incidentType,
                $incident->status,
                $incident->remarks,
                $custody?->custody_no,
            ])),
        ];
    }

    /* ---------------------------------------------------------- Billing --- */
    foreach ($openBillings as $billing) {
        $recordDate = $billing->issued_at ?: $billing->created_at;
        $latestPayment = $billing->payments
            ->sortByDesc(fn ($payment) => $payment->submitted_at ?: $payment->created_at)
            ->first();

        $actionTone = 'warning';
        $actionTitle = 'Settle this Billing Statement';
        $actionText = 'Download the Billing Statement, pay the amount through the CSPC Cashier, then present the paid official receipt to SPMU. SPMU records and verifies the receipt.';

        if ($billing->status === 'RECEIPT_SUBMITTED') {
            $actionTone = 'info';
            $actionTitle = 'Receipt submitted — wait for verification';
            $actionText = 'SPMU has recorded the paid CSPC Cashier receipt. No borrower upload is required while the payment evidence is being verified.';
        } elseif ($latestPayment?->status === 'REJECTED') {
            $actionTone = 'danger';
            $actionTitle = 'Present the corrected paid receipt to SPMU';
            $actionText = 'The previous receipt record requires correction. Bring the correct CSPC Cashier official receipt to SPMU so the payment evidence can be recorded again.';
        }

        $billingLinks = [];
        foreach ($billing->documents->whereNotIn('status', ['SUPERSEDED', 'INVALIDATED', 'EXPIRED']) as $document) {
            $billingLinks[] = ['Download Billing Statement', route('documents.download', $document)];
        }

        $badge = match ($billing->status) {
            'ISSUED' => 'Unpaid',
            'RECEIPT_SUBMITTED' => 'For Verification',
            default => (string) str($billing->status)->replace('_', ' ')->title(),
        };

        $obligationRows[] = [
            'category' => 'billing',
            'status' => $billing->status,
            'date' => $recordDate,
            'tone' => 'info',
            'icon' => 'requests',
            'type' => 'Open Billing',
            'reference' => $billing->billing_no,
            'reference_sub' => 'Billing Statement',
            'description' => $billing->lines->first()?->description ?: 'Assessed charge',
            'description_sub' => $billing->due_at
                ? 'Payment due: '.$billing->due_at->format('d M Y')
                : $billing->remarks,
            'badge' => $badge,
            'badge_tone' => $billing->status === 'RECEIPT_SUBMITTED' ? 'info' : 'info',
            'status_sub' => '₱'.number_format((float) $billing->total_amount, 2),
            'facts' => [
                ['Total amount', '₱'.number_format((float) $billing->total_amount, 2)],
                ['Payment due', optional($billing->due_at)->format('d M Y') ?: 'Not specified'],
                ['Payments recorded', (string) $billing->payments->count()],
            ],
            'action_tone' => $actionTone,
            'action_title' => $actionTitle,
            'action_text' => $actionText,
            'links' => $billingLinks,
            'search' => strtolower(implode(' ', [
                'billing statement open billing',
                $billing->billing_no,
                $billing->status,
                $billing->total_amount,
                $billing->remarks,
                $billing->lines->pluck('description')->join(' '),
            ])),
        ];
    }

    /* ------------------------------------------------------ Restriction --- */
    foreach ($activeRestrictions as $restriction) {
        $recordDate = $restriction->effective_from ?: $restriction->created_at;
        $restrictionType = (string) str($restriction->restriction_type)->replace('_', ' ')->title();

        /*
         * Restrictions carry no reference column of their own, so the register
         * shows a derived one built from the record's own date and id.
         */
        $reference = 'RES-'
            .optional($recordDate)->format('Ymd')
            .'-'.str_pad((string) $restriction->id, 5, '0', STR_PAD_LEFT);

        $obligationRows[] = [
            'category' => 'restriction',
            'status' => $restriction->status,
            'date' => $recordDate,
            'tone' => 'orange',
            'icon' => 'lock',
            'type' => 'Restriction',
            'reference' => $reference,
            'reference_sub' => 'Restriction record',
            'description' => 'Borrowing temporarily restricted',
            'description_sub' => $restriction->reason,
            'badge' => 'Active',
            'badge_tone' => 'warning',
            'status_sub' => $restriction->effective_to
                ? 'Until '.$restriction->effective_to->format('d M Y')
                : 'Until resolved',
            'facts' => [
                ['Restriction type', $restrictionType],
                ['Effective from', optional($restriction->effective_from)->format('d M Y') ?: '—'],
                ['Lifted when', $restriction->effective_to
                    ? $restriction->effective_to->format('d M Y')
                    : 'The linked case is resolved'],
            ],
            'action_tone' => 'info',
            'action_title' => 'Temporary until the related obligation is cleared',
            'action_text' => 'You cannot submit a new borrowing request while this restriction is active. Eligibility returns when SPMU resolves the linked case, or when a related Billing Statement is verified as settled or formally waived.',
            'links' => [],
            'search' => strtolower(implode(' ', [
                'active restriction borrowing restricted',
                $reference,
                $restrictionType,
                $restriction->status,
                $restriction->reason,
            ])),
        ];
    }

    $obligationCount = count($obligationRows);

    $overdueNote = $openOverdueCases->count() === 0
        ? 'No open records'
        : $openOverdueCases->count().' '.($openOverdueCases->count() === 1 ? 'item' : 'items').' needing return';

    $propertyNote = $openIncidents->count() === 0
        ? 'No open records'
        : $openIncidents->count().' open '.($openIncidents->count() === 1 ? 'case' : 'cases');

    $billingTotal = (float) $openBillings->sum('total_amount');
    $billingNote = $openBillings->count() === 0
        ? 'No open records'
        : '₱'.number_format($billingTotal, 2).' outstanding';

    $restrictionNote = $activeRestrictions->count() === 0
        ? 'No restrictions'
        : $activeRestrictions->count().' '.($activeRestrictions->count() === 1 ? 'restriction' : 'restrictions').' in effect';

    $summaryCards = [
        ['overdue', 'danger', 'calendar', $openOverdueCases->count(), 'Overdue Returns', $overdueNote],
        ['property', 'warning', 'accountability', $openIncidents->count(), 'Property Cases', $propertyNote],
        ['billing', 'info', 'requests', $openBillings->count(), 'Open Billings', $billingNote],
        ['restriction', 'orange', 'lock', $activeRestrictions->count(), 'Active Restrictions', $restrictionNote],
    ];
@endphp

@include('accountability.partials.obligations-styles')

<section class="content-area ob-workspace" data-borrower-accountability>

    {{-- Summary: each card also filters the register below it. --}}
    <div class="ob-summary" aria-label="Obligation overview" data-accountability-card-filters>
        @foreach($summaryCards as [$cardKey, $cardTone, $cardIcon, $cardValue, $cardLabel, $cardNote])
            <button
                type="button"
                class="ob-summary-card is-{{ $cardTone }} {{ $cardValue === 0 ? 'is-empty' : '' }}"
                data-accountability-card-filter="{{ $cardKey }}"
                aria-pressed="false"
            >
                <span class="ob-summary-icon" aria-hidden="true">
                    <x-icon :name="$cardIcon" size="22" />
                </span>

                <span class="ob-summary-copy">
                    <strong class="ob-summary-value">{{ $cardValue }}</strong>
                    <span class="ob-summary-label">{{ $cardLabel }}</span>
                    <span class="ob-summary-note">{{ $cardNote }}</span>
                </span>
            </button>
        @endforeach
    </div>

    {{-- Search and filters --}}
    <div class="ob-toolbar" aria-label="Search and filter obligations">
        <label class="ob-field">
            <span>Search</span>
            <span class="ob-field-shell">
                <span class="search-input-icon" aria-hidden="true"><x-icon name="search" size="17" /></span>
                <input
                    type="search"
                    placeholder="Search reference, type, status, or details..."
                    autocomplete="off"
                    data-accountability-search
                >
            </span>
        </label>

        <label class="ob-field">
            <span>Status</span>
            <select data-accountability-status>
                <option value="">All Statuses</option>
                @foreach($borrowerStatuses as $status)
                    <option value="{{ $status }}">{{ str($status)->replace('_', ' ')->title() }}</option>
                @endforeach
            </select>
        </label>

        <label class="ob-field">
            <span>Sort</span>
            <select data-accountability-sort>
                <option value="newest">Newest first</option>
                <option value="oldest">Oldest first</option>
            </select>
        </label>
    </div>

    {{-- Register --}}
    <div class="ob-table-card" data-accountability-table @if($obligationCount === 0) hidden @endif>
        <p class="ob-table-heading">Obligations ({{ $obligationCount }})</p>

        <div class="ob-table-scroll">
            <table class="ob-table">
                <thead>
                    <tr>
                        <th scope="col">Type</th>
                        <th scope="col">Reference</th>
                        <th scope="col">Description</th>
                        <th scope="col">Status</th>
                        <th scope="col">Date Created</th>
                        <th scope="col">Action</th>
                    </tr>
                </thead>

                <tbody data-accountability-records>
                    @foreach($obligationRows as $index => $row)
                        @php $detailId = 'obligation-detail-'.$index; @endphp

                        <tr
                            data-accountability-record
                            data-category="{{ $row['category'] }}"
                            data-status="{{ $row['status'] }}"
                            data-date="{{ optional($row['date'])->timestamp ?? 0 }}"
                            data-search="{{ $row['search'] }}"
                        >
                            <td>
                                <span class="ob-type is-{{ $row['tone'] }}">
                                    <span class="ob-type-icon" aria-hidden="true">
                                        <x-icon :name="$row['icon']" size="17" />
                                    </span>
                                    <span class="ob-type-label">{{ $row['type'] }}</span>
                                </span>
                            </td>

                            <td class="ob-col-reference">
                                <span class="ob-primary">{{ $row['reference'] }}</span>
                                @if($row['reference_sub'])
                                    <span class="ob-secondary">{{ $row['reference_sub'] }}</span>
                                @endif
                            </td>

                            <td class="ob-col-description">
                                <span class="ob-primary">{{ $row['description'] }}</span>
                                @if($row['description_sub'])
                                    <span class="ob-secondary">{{ $row['description_sub'] }}</span>
                                @endif
                            </td>

                            <td>
                                <span class="ob-badge is-{{ $row['badge_tone'] }}">{{ $row['badge'] }}</span>
                                @if($row['status_sub'])
                                    <span class="ob-secondary">{{ $row['status_sub'] }}</span>
                                @endif
                            </td>

                            <td>
                                <span class="ob-primary">{{ optional($row['date'])->format('d M Y') ?: '—' }}</span>
                                <span class="ob-secondary">{{ optional($row['date'])->format('h:i A') }}</span>
                            </td>

                            <td>
                                <span class="ob-actions">
                                    <button
                                        type="button"
                                        class="ob-view"
                                        data-obligation-toggle="{{ $detailId }}"
                                        aria-expanded="false"
                                        aria-controls="{{ $detailId }}"
                                    >
                                        View
                                    </button>

                                    <span class="ob-menu" data-obligation-menu>
                                        <button
                                            type="button"
                                            class="ob-menu-trigger"
                                            aria-haspopup="true"
                                            aria-expanded="false"
                                            aria-label="More actions for {{ $row['reference'] }}"
                                            data-obligation-menu-trigger
                                        >
                                            <x-icon name="more" size="18" />
                                        </button>

                                        <span class="ob-menu-panel" data-obligation-menu-panel hidden>
                                            <button type="button" data-obligation-toggle="{{ $detailId }}">
                                                View details
                                            </button>

                                            @foreach($row['links'] as [$linkLabel, $linkUrl])
                                                <a href="{{ $linkUrl }}">{{ $linkLabel }}</a>
                                            @endforeach

                                            <button type="button" data-obligation-copy="{{ $row['reference'] }}">
                                                Copy reference
                                            </button>
                                        </span>
                                    </span>
                                </span>
                            </td>
                        </tr>

                        <tr class="ob-detail-row" id="{{ $detailId }}" data-obligation-detail hidden>
                            <td colspan="6">
                                <div class="ob-detail">
                                    <div class="ob-detail-facts">
                                        @foreach($row['facts'] as [$factLabel, $factValue])
                                            <div>
                                                <small>{{ $factLabel }}</small>
                                                <strong>{{ $factValue }}</strong>
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="ob-detail-action is-{{ $row['action_tone'] }}">
                                        <span>What you need to do</span>
                                        <strong>{{ $row['action_title'] }}</strong>
                                        <p>{{ $row['action_text'] }}</p>
                                    </div>

                                    @if($row['links'])
                                        <div class="ob-detail-links">
                                            @foreach($row['links'] as [$linkLabel, $linkUrl])
                                                <a class="button secondary small ui-pressable" href="{{ $linkUrl }}">
                                                    {{ $linkLabel }}
                                                </a>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="ob-footer">
            <p data-accountability-result-count role="status" aria-live="polite">
                Showing 1 to {{ $obligationCount }} of {{ $obligationCount }} records
            </p>

            <div class="ob-pagination">
                <span class="ob-page ob-page-previous" aria-disabled="true" aria-label="Previous page">
                    <x-icon name="chevron-right" size="15" />
                </span>
                <span class="ob-page is-active" aria-current="page">1</span>
                <span class="ob-page" aria-disabled="true" aria-label="Next page">
                    <x-icon name="chevron-right" size="15" />
                </span>
            </div>
        </div>
    </div>

    {{-- Empty state --}}
    <div class="ob-empty-card" data-accountability-empty @if($obligationCount > 0) hidden @endif>
        <span class="ob-empty-mark" aria-hidden="true">
            <x-icon name="check-circle" size="46" />
        </span>

        <div class="ob-empty-copy">
            <strong>No unresolved obligations</strong>
            <span>You're all clear! You have no overdue returns, property cases, open billings, or restrictions.</span>
        </div>
    </div>

    <div class="ob-footer-outside" data-accountability-empty-footer @if($obligationCount > 0) hidden @endif>
        <p>Showing 0 of 0 records</p>

        <div class="ob-pagination">
            <span class="ob-page ob-page-previous" aria-disabled="true" aria-label="Previous page">
                <x-icon name="chevron-right" size="15" />
            </span>
            <span class="ob-page is-active" aria-current="page">1</span>
            <span class="ob-page" aria-disabled="true" aria-label="Next page">
                <x-icon name="chevron-right" size="15" />
            </span>
        </div>
    </div>
</section>

<script>
(() => {
    const workspace = document.querySelector('[data-borrower-accountability]');

    if (!workspace) {
        return;
    }

    /* View opens the record's own detail row. */
    const setDetail = (detail, open) => {
        detail.hidden = !open;

        workspace
            .querySelectorAll(`[data-obligation-toggle="${detail.id}"]`)
            .forEach((trigger) => {
                trigger.setAttribute('aria-expanded', String(open));
                if (trigger.classList.contains('ob-view')) {
                    trigger.textContent = open ? 'Hide' : 'View';
                }
            });
    };

    const closeMenus = () => {
        workspace.querySelectorAll('[data-obligation-menu-panel]').forEach((panel) => {
            panel.hidden = true;
            panel.parentElement
                ?.querySelector('[data-obligation-menu-trigger]')
                ?.setAttribute('aria-expanded', 'false');
        });
    };

    workspace.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-obligation-toggle]');

        if (toggle) {
            const detail = document.getElementById(toggle.dataset.obligationToggle);

            if (detail) {
                setDetail(detail, detail.hidden);
            }

            closeMenus();
            return;
        }

        const menuTrigger = event.target.closest('[data-obligation-menu-trigger]');

        if (menuTrigger) {
            const panel = menuTrigger.parentElement.querySelector('[data-obligation-menu-panel]');
            const willOpen = panel.hidden;

            closeMenus();
            panel.hidden = !willOpen;
            menuTrigger.setAttribute('aria-expanded', String(willOpen));
            return;
        }

        const copy = event.target.closest('[data-obligation-copy]');

        if (copy) {
            navigator.clipboard?.writeText(copy.dataset.obligationCopy);
            copy.textContent = 'Copied';
            window.setTimeout(() => { copy.textContent = 'Copy reference'; }, 1400);
            return;
        }

        closeMenus();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeMenus();
        }
    });

    /*
     * A hidden row must not leave its detail row open behind it, so the
     * detail follows whatever the shared filter script decides about the row.
     */
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            const row = mutation.target;
            const detail = row.nextElementSibling;

            if (detail?.matches('[data-obligation-detail]') && row.hidden) {
                setDetail(detail, false);
            }
        });
    });

    workspace.querySelectorAll('[data-accountability-record]').forEach((row) => {
        observer.observe(row, { attributes: true, attributeFilter: ['hidden'] });
    });
})();
</script>
