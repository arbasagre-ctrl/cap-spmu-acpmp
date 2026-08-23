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
    class="spmu-availability-review"
    data-spmu-availability-review
    data-endpoint="{{ route('inventory.availability') }}"
    data-from="{{ $availabilityFrom }}"
    data-to="{{ $availabilityTo }}"
>
    <div class="spmu-availability-review__header">
        <div>
            <p class="eyebrow">Inventory check</p>
            <h3>Requested items &amp; availability</h3>
            <p class="meta">
                Review the requested quantities for the selected dates against existing approved allocations
                and unavailable stock before SPMU records the approval decision.
            </p>
        </div>

        <span
            class="status-badge status-neutral"
            data-availability-summary
            role="status"
            aria-live="polite"
        >
            Checkingâ€¦
        </span>
    </div>

    @if($availabilityFrom && $availabilityTo)
        <div class="spmu-availability-period">
            <span>
                <small>Items needed from</small>
                <strong>{{ \Illuminate\Support\Carbon::parse($availabilityFrom)->format('d M Y') }}</strong>
            </span>

            <span aria-hidden="true">&rarr;</span>

            <span>
                <small>Expected return</small>
                <strong>{{ \Illuminate\Support\Carbon::parse($availabilityTo)->format('d M Y') }}</strong>
            </span>
        </div>
    @else
        <div class="callout warning">
            <strong>Borrowing dates are incomplete.</strong>
            <p>Availability cannot be calculated until the requested period is complete.</p>
        </div>
    @endif

    <div class="table-wrap spmu-availability-table-wrap">
        <table class="spmu-availability-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Request</th>
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
                            <strong data-period-available>â€”</strong>
                        </td>

                        <td>
                            <strong data-period-allocated>â€”</strong>
                        </td>

                        <td>
                            <strong data-after-approval>â€”</strong>
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
        <strong>Approval is the reservation point.</strong>
        <p>
            The values above are a review aid. The backend performs the final atomic
            availability check again when SPMU approves and creates the allocation.
        </p>
    </div>
</section>

<style>
.spmu-availability-review {
    display: grid;
    gap: 14px;
    margin: 18px 0;
    padding: 16px;
    border: 1px solid var(--border, #d7dee8);
    border-radius: 12px;
    background: var(--surface-subtle, #f8fafc);
}

.spmu-availability-review__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
}

.spmu-availability-review__header h3 {
    margin: 2px 0 4px;
}

.spmu-availability-review__header .meta {
    margin: 0;
}

.spmu-availability-period {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border: 1px solid var(--border, #d7dee8);
    border-radius: 10px;
    background: var(--surface, #fff);
}

.spmu-availability-period span:not([aria-hidden]) {
    display: grid;
    gap: 2px;
}

.spmu-availability-period small {
    color: var(--text-muted, #64748b);
}

.spmu-availability-table-wrap {
    margin: 0;
}

.spmu-availability-table td strong {
    white-space: nowrap;
}

.spmu-availability-guidance {
    margin: 0;
}

@media (max-width: 760px) {
    .spmu-availability-review__header {
        flex-direction: column;
    }

    .spmu-availability-period {
        align-items: flex-start;
        flex-direction: column;
    }
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

    const updateGuidance = (state) => {
        if (!guidance) return;

        guidance.classList.remove('info', 'warning', 'success', 'error');

        if (state === 'sufficient') {
            guidance.classList.add('success');
            guidance.innerHTML = `
                <strong>All requested quantities are currently sufficient for the selected dates.</strong>
                <p>SPMU may continue the document review. Approval will recheck availability atomically and then reserve the approved quantities.</p>
            `;
            return;
        }

        if (state === 'exact') {
            guidance.classList.add('warning');
            guidance.innerHTML = `
                <strong>The request is fulfillable, but one or more items will have no remaining availability after approval.</strong>
                <p>SPMU may still continue the review. Approval will perform the final atomic availability check before the quantities are reserved.</p>
            `;
            return;
        }

        if (state === 'insufficient') {
            guidance.classList.add('warning');
            guidance.innerHTML = `
                <strong>One or more requested quantities cannot currently be fulfilled for these dates.</strong>
                <p>Do not approve the request as documented. Return it for revision or coordinate a corrected approved request/document before reservation.</p>
            `;
            return;
        }

        guidance.classList.add('info');
        guidance.innerHTML = `
            <strong>Availability check could not be completed.</strong>
            <p>Reload the page or review Inventory for the same requested dates. The backend will still perform the final atomic check if an approval is attempted.</p>
        `;
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
                        : 'â€”';

                const result = row.querySelector('[data-availability-result]');

                const exactAvailability =
                    sufficient && Math.abs(available - requested) < 0.0005;

                if (exactAvailability) {
                    setBadge(result, 'Exact availability', 'warning');
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
                    'Check unavailable',
                    'warning'
                );
            });

            setBadge(summary, 'Check unavailable', 'warning');
            updateGuidance('error');
        });
})();
</script>

<style>
/* SPMU_COMPACT_APPROVAL_REVIEW */

/*
 * Do not stretch the right review card to the height of the document viewer.
 * Both columns keep their own compact scroll areas.
 */
.spmu-verification-grid {
    align-items: start !important;
}

.spmu-verification-grid > * {
    min-height: 0 !important;
    align-self: start;
}

.spmu-checklist-panel {
    max-height: clamp(560px, 66vh, 690px);
    overflow-y: auto;
    overscroll-behavior: contain;
}

/* Compact inventory review inside the right-hand decision card. */
.spmu-availability-review {
    gap: 9px !important;
    margin: 10px 0 14px !important;
    padding: 12px !important;
    border-radius: 10px !important;
}

.spmu-availability-review__header {
    gap: 10px !important;
}

.spmu-availability-review__header h3 {
    margin: 0 0 2px !important;
    font-size: 1rem;
    line-height: 1.25;
}

.spmu-availability-review__header .meta {
    font-size: .76rem;
    line-height: 1.42;
}

.spmu-availability-review [data-availability-summary] {
    flex: 0 0 auto;
    max-width: 112px;
    white-space: normal;
    text-align: center;
    line-height: 1.15;
}

.spmu-availability-period {
    gap: 7px !important;
    padding: 8px 10px !important;
}

.spmu-availability-period small {
    font-size: .7rem;
}

.spmu-availability-period strong {
    font-size: .86rem;
}

/*
 * The table is intentionally short. More requested items remain accessible
 * through its own vertical/horizontal scroll instead of making the right card tall.
 */
.spmu-availability-table-wrap {
    max-height: 205px;
    overflow: auto !important;
    overscroll-behavior: contain;
    border-radius: 8px;
}

.spmu-availability-table {
    min-width: 660px;
}

.spmu-availability-table th,
.spmu-availability-table td {
    padding: 8px 10px !important;
    font-size: .78rem;
}

.spmu-availability-table thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: var(--surface-subtle, #eef3f8);
}

.spmu-availability-guidance {
    padding: 10px 12px !important;
    font-size: .78rem;
    line-height: 1.42;
}

.spmu-availability-guidance strong {
    font-size: .82rem;
}

.spmu-availability-guidance p {
    margin: 3px 0 0 !important;
}

/* Slightly tighten the existing verification controls below availability. */
.spmu-checklist-panel .card-header {
    margin-bottom: 10px;
}

.spmu-checklist-panel .spmu-verification-form {
    gap: 10px;
}

.spmu-checklist-panel .spmu-check-item,
.spmu-checklist-panel .verification-check-item {
    padding-block: 10px;
}

@media (max-width: 1100px) {
    .spmu-checklist-panel {
        max-height: none;
        overflow: visible;
    }

    .spmu-availability-table-wrap {
        max-height: 240px;
    }
}

@media (max-width: 760px) {
    .spmu-availability-review {
        padding: 10px !important;
    }

    .spmu-availability-table-wrap {
        max-height: 220px;
    }
}
</style>

<style>
/* SPMU_BALANCED_REVIEW_COLUMNS_START */

/*
 * Desktop review workspace:
 * - equal 50/50 column widths
 * - equal responsive card heights
 * - bottoms always align
 * - PDF and long right-side review content scroll inside their own cards
 */
@media (min-width: 1101px) {
    .spmu-verification-grid {
        display: grid !important;
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        align-items: stretch !important;
        gap: 20px !important;
    }

    .spmu-verification-grid > .formal-document-review-card,
    .spmu-verification-grid > .scanned-document-card,
    .spmu-verification-grid > .spmu-checklist-panel {
        width: 100% !important;
        height: clamp(600px, 72vh, 740px) !important;
        min-height: 600px !important;
        max-height: 740px !important;
        align-self: stretch !important;
        box-sizing: border-box;
    }

    /*
     * Left: keep header fixed and let the actual scanned document viewer
     * consume the remaining height with its own scrolling.
     */
    .spmu-verification-grid > .formal-document-review-card,
    .spmu-verification-grid > .scanned-document-card {
        display: flex !important;
        flex-direction: column !important;
        overflow: hidden !important;
    }

    .spmu-verification-grid .formal-document-review-header,
    .spmu-verification-grid .scanned-document-header {
        flex: 0 0 auto;
    }

    .spmu-verification-grid .formal-document-review-stage,
    .spmu-verification-grid .formal-pdf-stage,
    .spmu-verification-grid .scanned-pdf-stage,
    .spmu-verification-grid .scanned-image-stage {
        flex: 1 1 auto !important;
        height: auto !important;
        min-height: 0 !important;
        max-height: none !important;
        overflow: auto !important;
    }

    .spmu-verification-grid .formal-document-review-frame,
    .spmu-verification-grid .formal-pdf-frame,
    .spmu-verification-grid .scanned-pdf-frame {
        width: 100% !important;
        height: 100% !important;
        min-height: 0 !important;
    }

    /*
     * Right: whole review card has its own vertical scroll.
     * This prevents many requested items / long checklist copy from
     * making the page column taller than the scanned-document column.
     */
    .spmu-verification-grid > .spmu-checklist-panel {
        display: block !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
        overscroll-behavior: contain;
        scrollbar-gutter: stable;
    }

    /*
     * Availability item table gets a compact internal scroll too.
     * It can contain many request lines without stretching the card.
     */
    .spmu-availability-table-wrap {
        max-height: 190px !important;
        overflow: auto !important;
        overscroll-behavior: contain;
        scrollbar-gutter: stable;
    }

    .spmu-availability-table {
        min-width: 660px;
    }

    .spmu-availability-table thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        background: var(--surface-subtle, #eef3f8);
    }
}

/*
 * Slightly shorter desktops/laptops still keep the two cards equal,
 * but the clamp adapts to viewport height.
 */
@media (min-width: 1101px) and (max-height: 800px) {
    .spmu-verification-grid > .formal-document-review-card,
    .spmu-verification-grid > .scanned-document-card,
    .spmu-verification-grid > .spmu-checklist-panel {
        height: clamp(540px, 68vh, 620px) !important;
        min-height: 540px !important;
        max-height: 620px !important;
    }
}

/*
 * Tablet/mobile: stack naturally instead of forcing two narrow equal columns.
 */
@media (max-width: 1100px) {
    .spmu-verification-grid {
        grid-template-columns: 1fr !important;
        align-items: start !important;
    }

    .spmu-verification-grid > .formal-document-review-card,
    .spmu-verification-grid > .scanned-document-card,
    .spmu-verification-grid > .spmu-checklist-panel {
        width: 100% !important;
        height: auto !important;
        min-height: 0 !important;
        max-height: none !important;
    }

    .spmu-verification-grid > .spmu-checklist-panel {
        overflow: visible !important;
    }
}

/* SPMU_BALANCED_REVIEW_COLUMNS_END */
</style>

<style>
/* SPMU_FINAL_UNIFIED_AVAILABILITY */
.spmu-availability-review {
    gap: 9px !important;
    margin: 0 0 14px !important;
    padding: 12px !important;
}

.spmu-availability-review__header {
    gap: 12px !important;
}

.spmu-availability-review__header h3 {
    margin: 1px 0 3px !important;
    font-size: 1rem;
    line-height: 1.25;
}

.spmu-availability-review__header .meta {
    margin: 0 !important;
    font-size: .76rem;
    line-height: 1.4;
}

.spmu-availability-review [data-availability-summary] {
    flex: 0 0 auto;
    max-width: 122px;
    white-space: normal;
    text-align: center;
    line-height: 1.15;
}

.spmu-availability-period {
    padding: 8px 10px !important;
}

.spmu-availability-table-wrap {
    max-height: 180px !important;
    overflow: auto !important;
    scrollbar-gutter: stable;
    overscroll-behavior: contain;
}

.spmu-availability-table {
    min-width: 700px;
}

.spmu-availability-table th,
.spmu-availability-table td {
    padding: 8px 10px !important;
    font-size: .76rem;
}

.spmu-availability-table thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: var(--surface-subtle, #eef3f8);
}

.spmu-availability-guidance {
    margin: 0 !important;
    padding: 10px 12px !important;
    font-size: .77rem;
    line-height: 1.42;
}

.spmu-availability-guidance p {
    margin: 3px 0 0 !important;
}
</style>

<style>
/* SPMU_CANONICAL_INVENTORY_TABLE_START */

.spmu-availability-table-wrap {
    width: 100% !important;
    max-width: 100% !important;
    max-height: 245px !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    scrollbar-gutter: stable;
    overscroll-behavior: contain;
}

.spmu-availability-table {
    width: 100% !important;
    min-width: 0 !important;
    table-layout: fixed !important;
}

.spmu-availability-table th {
    position: sticky !important;
    top: 0 !important;
    z-index: 2 !important;
    background: var(--surface-subtle, #eef3f8) !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: clip !important;
    line-height: 1.15 !important;
    font-size: .70rem !important;
    padding: 8px 6px !important;
    vertical-align: middle !important;
}

.spmu-availability-table td {
    white-space: normal !important;
    overflow-wrap: anywhere !important;
    word-break: normal !important;
    line-height: 1.25 !important;
    padding: 9px 6px !important;
    vertical-align: middle !important;
}

.spmu-availability-table th:nth-child(1),
.spmu-availability-table td:nth-child(1) {
    width: 29% !important;
}

.spmu-availability-table th:nth-child(2),
.spmu-availability-table td:nth-child(2) {
    width: 13% !important;
}

.spmu-availability-table th:nth-child(3),
.spmu-availability-table td:nth-child(3) {
    width: 15% !important;
}

.spmu-availability-table th:nth-child(4),
.spmu-availability-table td:nth-child(4) {
    width: 14% !important;
}

.spmu-availability-table th:nth-child(5),
.spmu-availability-table td:nth-child(5) {
    width: 15% !important;
}

.spmu-availability-table th:nth-child(6),
.spmu-availability-table td:nth-child(6) {
    width: 14% !important;
}

.spmu-availability-table .status-badge {
    max-width: 100% !important;
    white-space: normal !important;
    overflow-wrap: anywhere !important;
    line-height: 1.05 !important;
    text-align: center !important;
    padding: 5px 6px !important;
}

/* SPMU_CANONICAL_INVENTORY_TABLE_END */
</style>

<style>
/* SPMU_BOTTOM_INVENTORY_SCROLL_START */

@media (min-width: 1000px) {
    .spmu-review-bottom-row .spmu-availability-table-wrap {
        min-height: 0 !important;
        height: 100% !important;
        max-height: none !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
        scrollbar-gutter: stable !important;
        overscroll-behavior: contain !important;
    }

    .spmu-review-bottom-row .spmu-availability-table {
        width: 100% !important;
        min-width: 0 !important;
        table-layout: fixed !important;
    }

    .spmu-review-bottom-row .spmu-availability-table thead th {
        position: sticky !important;
        top: 0 !important;
        z-index: 3 !important;
    }
}

@media (max-width: 999px) {
    .spmu-availability-table-wrap {
        max-height: 280px !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
    }
}

/* SPMU_BOTTOM_INVENTORY_SCROLL_END */
</style>
