@php
    $availabilityVersion = $borrowingRequest->currentVersion;
    $availabilityFromValue = $availabilityVersion?->getAttribute('schedule_date')
        ?: $availabilityVersion?->getAttribute('needed_from');
    $availabilityToValue = $availabilityVersion?->getAttribute('return_date')
        ?: $availabilityVersion?->getAttribute('return_due_at');

    $availabilityFrom = $availabilityFromValue
        ? \Illuminate\Support\Carbon::parse($availabilityFromValue)->format('Y-m-d')
        : null;
    $availabilityTo = $availabilityToValue
        ? \Illuminate\Support\Carbon::parse($availabilityToValue)->format('Y-m-d')
        : null;
@endphp

<section
    class="card spmu-availability-review"
    data-spmu-availability-review
    data-endpoint="{{ route('inventory.availability') }}"
    data-from="{{ $availabilityFrom }}"
    data-to="{{ $availabilityTo }}"
>
    <div class="spmu-availability-review__header">
        <div>
            <p class="eyebrow">Inventory check</p>
            <h3>Requested items &amp; availability</h3>
            <p class="meta">Check requested quantities for the selected dates.</p>
        </div>

        <span
            class="status-badge status-neutral"
            data-availability-summary
            role="status"
            aria-live="polite"
        >
            Checking&hellip;
        </span>
    </div>

    @if($availabilityFrom && $availabilityTo)
        <div class="spmu-availability-period">
            <span>
                <small>Items needed from</small>
                <strong>{{ \Illuminate\Support\Carbon::parse($availabilityFrom)->format('d M Y') }}</strong>
            </span>

            <span class="spmu-availability-period__arrow" aria-hidden="true">&rarr;</span>

            <span>
                <small>Expected return</small>
                <strong>{{ \Illuminate\Support\Carbon::parse($availabilityTo)->format('d M Y') }}</strong>
            </span>
        </div>
    @else
        <div class="callout warning spmu-availability-guidance">
            <strong>Borrowing dates are incomplete.</strong>
            Availability cannot be calculated until the requested period is complete.
        </div>
    @endif

    <div class="table-wrap spmu-availability-table-wrap">
        <table class="spmu-availability-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Requested</th>
                    <th>Available</th>
                    <th>Reserved</th>
                    <th>Remaining</th>
                    <th>Result</th>
                </tr>
            </thead>

            <tbody>
                @foreach($availabilityVersion->items as $requestItem)
                    @php
                        $requestedQuantity = (float) (
                            $requestItem->approved_quantity
                            ?? $requestItem->requested_quantity
                            ?? 0
                        );
                    @endphp

                    <tr
                        data-availability-row
                        data-item-id="{{ $requestItem->inventory_item_id }}"
                        data-requested="{{ $requestedQuantity }}"
                    >
                        <td>
                            <strong>{{ $requestItem->description_snapshot }}</strong>
                            <small>{{ $requestItem->unit_snapshot }}</small>
                        </td>

                        <td>
                            <strong>{{ $requestedQuantity + 0 }}</strong>
                            <small>{{ $requestItem->unit_snapshot }}</small>
                        </td>

                        <td>
                            <strong data-period-available>&mdash;</strong>
                        </td>

                        <td>
                            <strong data-period-allocated>&mdash;</strong>
                        </td>

                        <td>
                            <strong data-after-approval>&mdash;</strong>
                        </td>

                        <td>
                            <span class="status-badge status-neutral" data-availability-result>
                                Checking
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="callout info spmu-availability-guidance" data-availability-guidance>
        <strong>Checking availability for the selected dates.</strong>
        Final availability is rechecked upon approval.
    </div>
</section>

<style>
/*
|--------------------------------------------------------------------------
| Inventory check - requested items and availability
|--------------------------------------------------------------------------
|
| The table is laid out with fixed column widths so quantity columns stay
| aligned and result badges never wrap onto a second line. The section is a
| full-width card, so it needs no internal scrolling of its own; the page
| scrolls instead.
|
*/

.spmu-availability-review {
    display: grid;
    gap: 14px;
    padding: 18px 20px 20px;
}

.spmu-availability-review__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
}

.spmu-availability-review__header .eyebrow {
    margin: 0 0 3px;
    font-size: 11px;
    letter-spacing: .06em;
}

.spmu-availability-review__header h3 {
    margin: 0 0 3px;
    color: var(--heading);
    font-size: 16px;
    line-height: 1.3;
}

.spmu-availability-review__header .meta {
    margin: 0;
    color: var(--text-muted);
    font-size: 12.5px;
}

.spmu-availability-review__header .status-badge {
    flex: 0 0 auto;
    white-space: nowrap;
}

/* Borrowing period */
.spmu-availability-period {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 18px;
    padding: 10px 14px;
    background: var(--surface-subtle);
    border: 1px solid var(--border);
    border-radius: 9px;
}

.spmu-availability-period span:not([aria-hidden]) {
    display: grid;
    gap: 2px;
}

.spmu-availability-period small {
    color: var(--text-muted);
    font-size: 11px;
    font-weight: 650;
}

.spmu-availability-period strong {
    color: var(--heading);
    font-size: 13px;
}

.spmu-availability-period__arrow {
    color: var(--text-soft);
    font-size: 15px;
}

/* Item table */
.spmu-availability-table-wrap {
    margin: 0;
    overflow-x: auto;
}

.spmu-availability-table {
    width: 100%;
    min-width: 680px;
    table-layout: fixed;
}

.spmu-availability-table th {
    padding: 9px 10px;
    color: var(--text-muted);
    background: var(--surface-subtle);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .02em;
    white-space: nowrap;
    vertical-align: middle;
}

.spmu-availability-table td {
    padding: 10px;
    font-size: 12.5px;
    line-height: 1.3;
    vertical-align: middle;
}

.spmu-availability-table td strong {
    display: block;
    color: var(--heading);
    font-weight: 700;
    overflow-wrap: anywhere;
    word-break: normal;
}

.spmu-availability-table td small {
    display: block;
    margin-top: 2px;
    color: var(--text-muted);
    font-size: 11px;
}

/* Quantities read as a column of figures, so they are aligned and compact. */
.spmu-availability-table th:not(:first-child),
.spmu-availability-table td:not(:first-child) { text-align: center; }

.spmu-availability-table th:nth-child(1),
.spmu-availability-table td:nth-child(1) { width: 34%; text-align: left; }

.spmu-availability-table th:nth-child(2),
.spmu-availability-table td:nth-child(2) { width: 13%; }

.spmu-availability-table th:nth-child(3),
.spmu-availability-table td:nth-child(3),
.spmu-availability-table th:nth-child(4),
.spmu-availability-table td:nth-child(4),
.spmu-availability-table th:nth-child(5),
.spmu-availability-table td:nth-child(5) { width: 12%; }

.spmu-availability-table th:nth-child(6),
.spmu-availability-table td:nth-child(6) { width: 17%; }

/* A result never breaks across lines. */
.spmu-availability-table .status-badge {
    white-space: nowrap;
    font-size: 11px;
}

/* Compact result notice */
.spmu-availability-guidance {
    margin: 0;
    padding: 10px 13px;
    font-size: 12.5px;
    line-height: 1.5;
}

.spmu-availability-guidance strong { font-weight: 700; }

.spmu-availability-guidance p { margin: 2px 0 0; }

@media (max-width: 760px) {
    .spmu-availability-review { padding: 16px; }

    .spmu-availability-review__header {
        align-items: flex-start;
        flex-direction: column;
        gap: 10px;
    }

    .spmu-availability-period {
        align-items: flex-start;
        flex-direction: column;
        gap: 10px;
    }

    .spmu-availability-period__arrow { display: none; }
}
</style>

<script>
(() => {
    const root = document.querySelector('[data-spmu-availability-review]');
    if (!root || root.dataset.initialized === '1') return;

    root.dataset.initialized = '1';

    const endpoint = root.dataset.endpoint;
    const from = root.dataset.from;
    const to = root.dataset.to;
    const summary = root.querySelector('[data-availability-summary]');
    const guidance = root.querySelector('[data-availability-guidance]');
    const rows = [...root.querySelectorAll('[data-availability-row]')];
    const approveButton = document.querySelector('[data-approve-button]');

    let inventoryState = 'pending';

    const setBadge = (node, label, tone) => {
        if (!node) return;

        node.textContent = label;
        node.classList.remove(
            'status-neutral',
            'status-success',
            'status-warning',
            'status-danger',
            'status-info'
        );
        node.classList.add(`status-${tone}`);
    };

    const numberValue = (value) => {
        const parsed = Number.parseFloat(String(value ?? '0'));
        return Number.isFinite(parsed) ? parsed : 0;
    };

    const formatQuantity = (value) => {
        const numeric = numberValue(value);

        if (Number.isInteger(numeric)) {
            return String(numeric);
        }

        return numeric.toFixed(3).replace(/0+$/, '').replace(/\.$/, '');
    };

    /*
     * The notice states the outcome and the one thing that is not obvious
     * from the table: the backend rechecks availability on approval. It
     * deliberately does not restate the per-item results above it.
     */
    const updateGuidance = (state) => {
        if (!guidance) return;

        guidance.classList.remove('info', 'warning', 'success', 'error');

        if (state === 'sufficient') {
            guidance.classList.add('success');
            guidance.innerHTML =
                '<strong>Quantities are sufficient for the selected dates.</strong> '
                + 'Final availability is rechecked upon approval.';
            return;
        }

        if (state === 'exact') {
            guidance.classList.add('warning');
            guidance.innerHTML =
                '<strong>Fulfillable, but one or more items will have no stock left after approval.</strong> '
                + 'Final availability is rechecked upon approval.';
            return;
        }

        if (state === 'insufficient') {
            guidance.classList.add('warning');
            guidance.innerHTML =
                '<strong>One or more quantities cannot be fulfilled for these dates.</strong> '
                + 'Return the request for revision instead of approving it.';
            return;
        }

        guidance.classList.add('info');
        guidance.innerHTML =
            '<strong>Availability could not be checked.</strong> '
            + 'Reload the page; the final check still runs on approval.';
    };

    if (approveButton) {
        approveButton.addEventListener('click', (event) => {
            if (inventoryState !== 'insufficient') return;

            event.preventDefault();
            event.stopImmediatePropagation();

            root.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });

            updateGuidance('insufficient');
        }, true);
    }

    if (!endpoint || !from || !to || rows.length === 0) {
        inventoryState = 'error';
        setBadge(summary, 'Unavailable', 'warning');
        updateGuidance('error');
        return;
    }

    const url = new URL(endpoint, window.location.origin);
    url.searchParams.set('from', from);
    url.searchParams.set('to', to);

    fetch(url, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin'
    })
        .then((response) => {
            if (!response.ok) {
                throw new Error(`Availability request failed: ${response.status}`);
            }

            return response.json();
        })
        .then((balances) => {
            let allSufficient = true;
            let hasExactAvailability = false;

            rows.forEach((row) => {
                const itemId = row.dataset.itemId;
                const requested = numberValue(row.dataset.requested);
                const balance = balances[itemId] ?? {};

                const available = numberValue(
                    balance.available
                    ?? balance.borrower_available
                    ?? 0
                );

                const allocated = numberValue(
                    balance.allocated
                    ?? 0
                );

                const afterApproval = Math.max(0, available - requested);
                const sufficient = available + 0.0005 >= requested;

                row.querySelector('[data-period-available]').textContent =
                    formatQuantity(available);

                row.querySelector('[data-period-allocated]').textContent =
                    formatQuantity(allocated);

                row.querySelector('[data-after-approval]').textContent =
                    sufficient
                        ? formatQuantity(afterApproval)
                        : '—';

                const result = row.querySelector('[data-availability-result]');

                const exactAvailability =
                    sufficient && Math.abs(available - requested) < 0.0005;

                if (exactAvailability) {
                    setBadge(result, 'Exact', 'warning');
                    hasExactAvailability = true;
                } else if (sufficient) {
                    setBadge(result, 'Sufficient', 'success');
                } else {
                    setBadge(result, 'Insufficient', 'danger');
                    allSufficient = false;
                }
            });

            inventoryState = !allSufficient
                ? 'insufficient'
                : (hasExactAvailability ? 'exact' : 'sufficient');

            root.dataset.inventoryState = inventoryState;

            setBadge(
                summary,
                !allSufficient
                    ? 'Inventory conflict'
                    : (hasExactAvailability
                        ? 'Exact availability'
                        : 'All quantities sufficient'),
                !allSufficient
                    ? 'danger'
                    : (hasExactAvailability ? 'warning' : 'success')
            );

            updateGuidance(inventoryState);
        })
        .catch(() => {
            inventoryState = 'error';
            root.dataset.inventoryState = 'error';

            rows.forEach((row) => {
                setBadge(
                    row.querySelector('[data-availability-result]'),
                    'Unavailable',
                    'warning'
                );
            });

            setBadge(summary, 'Check unavailable', 'warning');
            updateGuidance('error');
        });
})();
</script>
