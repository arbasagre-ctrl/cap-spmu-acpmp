@extends('layouts.app', ['title' => 'Notification Delivery'])
@section('content')
@php
    $recipientIds = $deliveries
        ->pluck('recipient_user_id')
        ->filter()
        ->map(fn ($id) => (int) $id)
        ->unique()
        ->values();

    $recipientNames = $recipientIds->isEmpty()
        ? collect()
        : \App\Models\User::query()
            ->whereIn('id', $recipientIds)
            ->pluck('full_name', 'id');

    $channelOptions = $deliveries
        ->pluck('channel')
        ->filter()
        ->map(fn ($channel) => strtoupper((string) $channel))
        ->unique()
        ->sort()
        ->values();

    $statusOptions = $deliveries
        ->pluck('delivery_status')
        ->filter()
        ->map(fn ($status) => strtoupper((string) $status))
        ->unique()
        ->sort()
        ->values();

    $eventOptions = $deliveries
        ->map(fn ($delivery) => (string) ($delivery->event?->event_code ?? ''))
        ->filter()
        ->unique()
        ->sort()
        ->values();

    $eventLabel = function (?string $code): string {
        $code = trim((string) $code);

        return $code === ''
            ? 'Unknown Event'
            : ucwords(strtolower(str_replace(['_', '.'], ' ', $code)));
    };

    $channelLabel = function (?string $channel): string {
        return match (strtoupper(trim((string) $channel))) {
            'SYSTEM' => 'In-system',
            'EMAIL' => 'Email',
            'SMS' => 'SMS',
            default => ucwords(strtolower(trim((string) $channel))),
        };
    };
@endphp

<section class="page-heading notification-delivery-heading">
    <div>
        <p class="eyebrow">ICTU notification administration</p>
        <h1>Notification Delivery</h1>
        <p>Review system, email, and SMS delivery attempts and provider responses.</p>
    </div>
</section>

<section class="content-area notification-delivery-area">
    <div class="card notification-delivery-toolbar" aria-label="Notification delivery filters">
        <div class="notification-delivery-search">
            <label for="delivery-search">Search</label>
            <input
                id="delivery-search"
                type="search"
                placeholder="Search recipient, event, channel, status..."
                autocomplete="off"
            >
        </div>

        <div>
            <label for="delivery-date-filter">Date</label>
            <select id="delivery-date-filter">
                <option value="ALL">All dates</option>
                <option value="TODAY">Today</option>
                <option value="YESTERDAY">Yesterday</option>
                <option value="7_DAYS">Last 7 days</option>
                <option value="30_DAYS">Last 30 days</option>
            </select>
        </div>

        <div>
            <label for="delivery-channel-filter">Channel</label>
            <select id="delivery-channel-filter">
                <option value="ALL">All channels</option>
                @foreach($channelOptions as $channelOption)
                    <option value="{{ $channelOption }}">{{ $channelLabel($channelOption) }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="delivery-status-filter">Status</label>
            <select id="delivery-status-filter">
                <option value="ALL">All statuses</option>
                @foreach($statusOptions as $statusOption)
                    <option value="{{ $statusOption }}">
                        {{ ucwords(strtolower(str_replace('_', ' ', $statusOption))) }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="delivery-event-filter">Event</label>
            <select id="delivery-event-filter">
                <option value="ALL">All events</option>
                @foreach($eventOptions as $eventOption)
                    <option value="{{ $eventOption }}">{{ $eventLabel($eventOption) }}</option>
                @endforeach
            </select>
        </div>

        <button class="button secondary notification-delivery-reset" type="button" id="delivery-reset">
            Reset
        </button>
    </div>

    <div class="notification-delivery-summary">
        <span id="delivery-result-count">
            {{ $deliveries->count() }} delivery attempt{{ $deliveries->count() === 1 ? '' : 's' }}
        </span>
    </div>

    @if($deliveries->isEmpty())
        <div class="card empty-state">No notification delivery attempts have been recorded.</div>
    @else
        <div class="table-wrap notification-delivery-table-wrap">
            <table class="notification-delivery-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Event</th>
                        <th>Recipient</th>
                        <th>Channel</th>
                        <th>Status</th>
                        <th>Delivery Details</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($deliveries as $delivery)
                        @php
                            $eventCode = (string) ($delivery->event?->event_code ?? '');
                            $eventDisplay = $eventLabel($eventCode);
                            $channelCode = strtoupper((string) $delivery->channel);
                            $channelDisplay = $channelLabel($delivery->channel);
                            $statusCode = strtoupper((string) $delivery->delivery_status);
                            $recipientName = $delivery->recipient_user_id
                                ? ($recipientNames->get((int) $delivery->recipient_user_id) ?: 'User #'.$delivery->recipient_user_id)
                                : 'External recipient';

                            $destination = trim((string) $delivery->address_snapshot);
                            $attemptedAt = $delivery->attempted_at ?? $delivery->created_at;
                            $providerResponse = trim((string) $delivery->provider_response);

                            $shortResponse = match (true) {
                                $statusCode === 'SENT' && $channelCode === 'SYSTEM' => 'Stored in the in-system notification record.',
                                $statusCode === 'SENT' && $channelCode === 'EMAIL' => 'Accepted by the configured mail transport.',
                                $statusCode === 'SENT' && $channelCode === 'SMS' => 'Accepted by the configured SMS provider.',
                                in_array($statusCode, ['FAILED', 'ERROR'], true) => 'Delivery attempt failed. Open details for the provider response.',
                                $providerResponse !== '' => \Illuminate\Support\Str::limit($providerResponse, 72),
                                default => 'No provider response recorded.',
                            };

                            $searchText = strtolower(implode(' ', array_filter([
                                $eventDisplay,
                                $eventCode,
                                $recipientName,
                                $destination,
                                $channelDisplay,
                                $channelCode,
                                $statusCode,
                                $providerResponse,
                            ])));
                            $deliveryDetailsId = 'delivery-details-'.$delivery->id;
                        @endphp

                        <tr
                            data-delivery-row
                            data-delivery-search="{{ $searchText }}"
                            data-delivery-date="{{ optional($attemptedAt)->format('Y-m-d') }}"
                            data-delivery-channel="{{ $channelCode }}"
                            data-delivery-status="{{ $statusCode }}"
                            data-delivery-event="{{ $eventCode }}"
                        >
                            <td class="notification-delivery-time">
                                @if($attemptedAt)
                                    <strong>{{ $attemptedAt->format('g:i A') }}</strong>
                                    <span>{{ $attemptedAt->format('M j, Y') }}</span>
                                @else
                                    <span>Not recorded</span>
                                @endif
                            </td>

                            <td>
                                <strong class="notification-delivery-event">{{ $eventDisplay }}</strong>
                            </td>

                            <td class="notification-delivery-recipient">
                                <strong>{{ $recipientName }}</strong>

                                @if($channelCode === 'SYSTEM')
                                    <span>In-system notification</span>
                                @elseif($destination !== '')
                                    <span>{{ $destination }}</span>
                                @else
                                    <span>No destination recorded</span>
                                @endif
                            </td>

                            <td>
                                <span class="notification-delivery-channel">{{ $channelDisplay }}</span>
                            </td>

                            <td>
                                <x-status-badge :status="$delivery->delivery_status" />
                            </td>

                            <td class="notification-delivery-details-cell">
                                <p>{{ $shortResponse }}</p>

                                <button
                                    type="button"
                                    class="notification-delivery-view-button"
                                    data-delivery-details-toggle
                                    aria-expanded="false"
                                    aria-controls="{{ $deliveryDetailsId }}"
                                >
                                    View details
                                    <span aria-hidden="true">⌄</span>
                                </button>
                            </td>
                        </tr>

                        <tr
                            id="{{ $deliveryDetailsId }}"
                            class="notification-delivery-detail-row"
                            data-delivery-details-row
                            hidden
                        >
                            <td colspan="6">
                                <div class="notification-delivery-details-panel">
                                    <div class="notification-delivery-details-header">
                                        <div>
                                            <p class="eyebrow">Delivery details</p>
                                            <h3>{{ $eventDisplay }}</h3>
                                        </div>
                                    </div>

                                    <dl class="notification-delivery-detail-grid">
                                        <div>
                                            <dt>Recipient</dt>
                                            <dd>{{ $recipientName }}</dd>
                                        </div>

                                        <div>
                                            <dt>Channel</dt>
                                            <dd>{{ $channelDisplay }}</dd>
                                        </div>

                                        <div>
                                            <dt>Destination</dt>
                                            <dd>
                                                @if($channelCode === 'SYSTEM')
                                                    In-system user #{{ $delivery->recipient_user_id ?: 'Not recorded' }}
                                                @else
                                                    {{ $destination !== '' ? $destination : 'Not recorded' }}
                                                @endif
                                            </dd>
                                        </div>

                                        <div>
                                            <dt>Status</dt>
                                            <dd>{{ ucwords(strtolower(str_replace('_', ' ', $statusCode))) }}</dd>
                                        </div>

                                        <div>
                                            <dt>Date &amp; Time</dt>
                                            <dd>{{ $attemptedAt ? $attemptedAt->format('F j, Y • g:i:s A') : 'Not recorded' }}</dd>
                                        </div>

                                        <div>
                                            <dt>Attempt</dt>
                                            <dd>#{{ $delivery->attempt_no ?: 1 }}</dd>
                                        </div>

                                        <div>
                                            <dt>Provider</dt>
                                            <dd>{{ filled($delivery->provider) ? $delivery->provider : 'Not recorded' }}</dd>
                                        </div>

                                        <div>
                                            <dt>Event Code</dt>
                                            <dd><code>{{ $eventCode !== '' ? $eventCode : 'Not recorded' }}</code></dd>
                                        </div>

                                        <div class="notification-delivery-detail-wide">
                                            <dt>Provider Response</dt>
                                            <dd>{{ $providerResponse !== '' ? $providerResponse : 'No provider response recorded.' }}</dd>
                                        </div>

                                        @if($delivery->event)
                                            <div>
                                                <dt>Source Record</dt>
                                                <dd>
                                                    {{ class_basename((string) $delivery->event->source_type) }}
                                                    #{{ $delivery->event->source_id }}
                                                </dd>
                                            </div>

                                            <div>
                                                <dt>Event Recorded</dt>
                                                <dd>{{ optional($delivery->event->occurred_at)->format('F j, Y • g:i:s A') ?: 'Not recorded' }}</dd>
                                            </div>
                                        @endif
                                    </dl>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="card empty-state notification-delivery-no-results" id="delivery-no-results" hidden>
            No delivery attempts match the selected filters.
        </div>
    @endif
</section>

<style>
.notification-delivery-heading {
    padding-bottom: 10px;
}

.notification-delivery-area {
    display: grid;
    gap: 16px;
}

.notification-delivery-toolbar {
    display: grid;
    grid-template-columns: minmax(280px, 1.6fr) repeat(4, minmax(135px, .7fr)) auto;
    gap: 12px;
    align-items: end;
    padding: 16px;
}

.notification-delivery-toolbar label {
    display: block;
    margin-bottom: 6px;
    color: var(--text-muted, #5c7088);
    font-size: .78rem;
    font-weight: 800;
}

.notification-delivery-toolbar input,
.notification-delivery-toolbar select {
    width: 100%;
    min-height: 44px;
}

.notification-delivery-reset {
    min-height: 44px;
    white-space: nowrap;
}

.notification-delivery-summary {
    color: var(--text-muted, #60738a);
    font-size: .86rem;
}

.notification-delivery-table {
    min-width: 1040px;
}

.notification-delivery-table th {
    white-space: nowrap;
}

.notification-delivery-table td {
    vertical-align: top;
}

.notification-delivery-time {
    width: 120px;
    white-space: nowrap;
}

.notification-delivery-time strong,
.notification-delivery-time span,
.notification-delivery-recipient strong,
.notification-delivery-recipient span {
    display: block;
}

.notification-delivery-time span,
.notification-delivery-recipient span {
    margin-top: 3px;
    color: var(--text-muted, #60738a);
    font-size: .8rem;
}

.notification-delivery-event {
    display: block;
    min-width: 150px;
    text-transform: none;
}

.notification-delivery-channel {
    white-space: nowrap;
}

.notification-delivery-details-cell {
    min-width: 260px;
}

.notification-delivery-details-cell > p {
    margin: 0;
    color: var(--text-muted, #5f738b);
    line-height: 1.45;
}

.notification-delivery-view-button {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 7px;
    padding: 0;
    border: 0;
    background: transparent;
    color: var(--interactive, #1769e0);
    font: inherit;
    font-size: .82rem;
    font-weight: 700;
    cursor: pointer;
}

.notification-delivery-view-button span {
    display: inline-block;
    transition: transform .15s ease;
}

.notification-delivery-view-button[aria-expanded="true"] span {
    transform: rotate(180deg);
}

.notification-delivery-detail-row > td {
    padding: 0 !important;
    border-top: 0 !important;
    background: var(--surface-subtle, #f7f9fc);
}

.notification-delivery-details-panel {
    width: 100%;
    box-sizing: border-box;
    padding: 18px 22px 20px;
    border-top: 1px solid var(--border-color, #d7e0ea);
    border-bottom: 1px solid var(--border-color, #d7e0ea);
    background: var(--surface-subtle, #f7f9fc);
}

.notification-delivery-details-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
}

.notification-delivery-details-header h3 {
    margin: 2px 0 0;
    font-size: 1rem;
}

.notification-delivery-detail-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px 22px;
    margin: 0;
}

.notification-delivery-detail-grid > div {
    min-width: 0;
}

.notification-delivery-detail-grid dt {
    margin-bottom: 4px;
    color: var(--text-muted, #60738a);
    font-size: .7rem;
    font-weight: 800;
    letter-spacing: .035em;
    text-transform: uppercase;
}

.notification-delivery-detail-grid dd {
    margin: 0;
    overflow-wrap: anywhere;
    word-break: break-word;
}

.notification-delivery-detail-grid code {
    white-space: normal;
    overflow-wrap: anywhere;
    font-size: .8rem;
}

.notification-delivery-detail-wide {
    grid-column: 1 / -1;
}

@media (max-width: 1050px) {
    .notification-delivery-detail-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 700px) {
    .notification-delivery-details-panel {
        padding: 16px;
    }

    .notification-delivery-detail-grid {
        grid-template-columns: 1fr;
    }

    .notification-delivery-detail-wide {
        grid-column: auto;
    }
}

.notification-delivery-no-results {
    text-align: center;
}

@media (max-width: 1200px) {
    .notification-delivery-toolbar {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .notification-delivery-search {
        grid-column: 1 / -1;
    }

    .notification-delivery-reset {
        width: 100%;
    }
}

@media (max-width: 700px) {
    .notification-delivery-toolbar {
        grid-template-columns: 1fr;
    }

    .notification-delivery-search {
        grid-column: auto;
    }

    .notification-delivery-detail-grid {
        grid-template-columns: 1fr;
    }

    .notification-delivery-detail-wide {
        grid-column: auto;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const search = document.getElementById('delivery-search');
    const dateFilter = document.getElementById('delivery-date-filter');
    const channelFilter = document.getElementById('delivery-channel-filter');
    const statusFilter = document.getElementById('delivery-status-filter');
    const eventFilter = document.getElementById('delivery-event-filter');
    const reset = document.getElementById('delivery-reset');
    const resultCount = document.getElementById('delivery-result-count');
    const noResults = document.getElementById('delivery-no-results');

    const rows = Array.from(document.querySelectorAll('[data-delivery-row]'));
    const detailRows = Array.from(document.querySelectorAll('[data-delivery-details-row]'));
    const detailToggles = Array.from(document.querySelectorAll('[data-delivery-details-toggle]'));

    detailToggles.forEach((button) => {
        button.addEventListener('click', () => {
            const targetId = button.getAttribute('aria-controls');
            const target = targetId ? document.getElementById(targetId) : null;
            if (!target) return;

            const willOpen = target.hidden;

            detailRows.forEach((row) => {
                row.hidden = true;
            });

            detailToggles.forEach((toggle) => {
                toggle.setAttribute('aria-expanded', 'false');
            });

            if (willOpen) {
                target.hidden = false;
                button.setAttribute('aria-expanded', 'true');
            }
        });
    });

    const today = () => {
        const value = new Date();
        value.setHours(0, 0, 0, 0);
        return value;
    };

    const dateMatches = (value, selected) => {
        if (selected === 'ALL') return true;
        if (!value) return false;

        const rowDate = new Date(`${value}T00:00:00`);
        const current = today();
        const diffDays = Math.floor((current - rowDate) / 86400000);

        if (selected === 'TODAY') return diffDays === 0;
        if (selected === 'YESTERDAY') return diffDays === 1;
        if (selected === '7_DAYS') return diffDays >= 0 && diffDays <= 6;
        if (selected === '30_DAYS') return diffDays >= 0 && diffDays <= 29;

        return true;
    };

    const applyFilters = () => {
        const query = (search?.value || '').trim().toLowerCase();
        const selectedDate = dateFilter?.value || 'ALL';
        const selectedChannel = channelFilter?.value || 'ALL';
        const selectedStatus = statusFilter?.value || 'ALL';
        const selectedEvent = eventFilter?.value || 'ALL';

        let visible = 0;

        rows.forEach((row) => {
            const matchesSearch =
                !query || (row.dataset.deliverySearch || '').includes(query);

            const matchesChannel =
                selectedChannel === 'ALL'
                || row.dataset.deliveryChannel === selectedChannel;

            const matchesStatus =
                selectedStatus === 'ALL'
                || row.dataset.deliveryStatus === selectedStatus;

            const matchesEvent =
                selectedEvent === 'ALL'
                || row.dataset.deliveryEvent === selectedEvent;

            const matchesDate = dateMatches(
                row.dataset.deliveryDate || '',
                selectedDate
            );

            const show =
                matchesSearch
                && matchesChannel
                && matchesStatus
                && matchesEvent
                && matchesDate;

            row.hidden = !show;

            if (!show) {
                const nextRow = row.nextElementSibling;
                if (nextRow?.matches('[data-delivery-details-row]')) {
                    nextRow.hidden = true;
                }

                const toggle = row.querySelector('[data-delivery-details-toggle]');
                toggle?.setAttribute('aria-expanded', 'false');
            }

            if (show) visible += 1;
        });

        if (resultCount) {
            resultCount.textContent =
                `${visible} delivery attempt${visible === 1 ? '' : 's'}`;
        }

        if (noResults) {
            noResults.hidden = visible !== 0;
        }
    };

    [search, dateFilter, channelFilter, statusFilter, eventFilter].forEach((control) => {
        control?.addEventListener(
            control === search ? 'input' : 'change',
            applyFilters
        );
    });

    reset?.addEventListener('click', () => {
        if (search) search.value = '';
        if (dateFilter) dateFilter.value = 'ALL';
        if (channelFilter) channelFilter.value = 'ALL';
        if (statusFilter) statusFilter.value = 'ALL';
        if (eventFilter) eventFilter.value = 'ALL';

        applyFilters();
        search?.focus();
    });

    applyFilters();
});
</script>

{{-- INLINE_DELIVERY_DETAILS_OVERRIDE --}}
<style>
/* Do not use the modal version. */
.notification-delivery-modal {
    display: none !important;
}

/* Keep View details as simple blue text. */
.notification-delivery-view-button {
    appearance: none !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 5px !important;
    margin-top: 7px !important;
    padding: 0 !important;
    border: 0 !important;
    background: transparent !important;
    box-shadow: none !important;
    color: var(--interactive, #1769e0) !important;
    font: inherit !important;
    font-size: .84rem !important;
    font-weight: 700 !important;
    cursor: pointer !important;
}

.notification-delivery-view-button:hover {
    text-decoration: underline;
}

.notification-delivery-view-button span {
    display: inline-block;
    transition: transform .15s ease;
}

.notification-delivery-view-button[aria-expanded="true"] span {
    transform: rotate(180deg);
}

/* Details open as one full-width row below the delivery. */
.notification-delivery-detail-row[hidden] {
    display: none !important;
}

.notification-delivery-detail-row:not([hidden]) {
    display: table-row !important;
}

.notification-delivery-detail-row > td {
    padding: 0 !important;
    border-top: 0 !important;
}

.notification-delivery-detail-row .notification-delivery-details-panel {
    width: 100% !important;
    max-width: none !important;
    box-sizing: border-box !important;
    padding: 20px 24px !important;
    border: 0 !important;
    border-top: 1px solid var(--border-color, #d7e0ea) !important;
    border-bottom: 1px solid var(--border-color, #d7e0ea) !important;
    border-radius: 0 !important;
    background: var(--surface-subtle, #f7f9fc) !important;
}

.notification-delivery-detail-row .notification-delivery-detail-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
}

.notification-delivery-detail-row .notification-delivery-detail-wide {
    grid-column: 1 / -1 !important;
}

@media (max-width: 1050px) {
    .notification-delivery-detail-row .notification-delivery-detail-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
}

@media (max-width: 650px) {
    .notification-delivery-detail-row .notification-delivery-detail-grid {
        grid-template-columns: 1fr !important;
    }

    .notification-delivery-detail-row .notification-delivery-detail-wide {
        grid-column: auto !important;
    }
}
</style>

<script>
document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-delivery-details-toggle]');
    if (!button) return;

    /*
     * Override the previous modal handler.
     * Details must expand inline under the selected row.
     */
    event.preventDefault();
    event.stopImmediatePropagation();

    const targetId = button.getAttribute('aria-controls');
    const target = targetId ? document.getElementById(targetId) : null;

    if (!target) return;

    const willOpen = target.hidden;

    document.querySelectorAll('[data-delivery-details-row]').forEach((row) => {
        row.hidden = true;
    });

    document.querySelectorAll('[data-delivery-details-toggle]').forEach((toggle) => {
        toggle.setAttribute('aria-expanded', 'false');
    });

    document.querySelectorAll('.notification-delivery-modal').forEach((modal) => {
        modal.hidden = true;
    });

    document.body.classList.remove('notification-delivery-modal-open');

    if (willOpen) {
        target.hidden = false;
        button.setAttribute('aria-expanded', 'true');
    }
}, true);
</script>


{{-- VIEW_DETAILS_FIXED_CHEVRON --}}
<style>
.notification-delivery-view-button {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: flex-start !important;
    gap: 7px !important;
    padding: 0 !important;
    border: 0 !important;
    background: transparent !important;
    box-shadow: none !important;
    color: var(--interactive, #1769e0) !important;
    font: inherit !important;
    font-size: .84rem !important;
    font-weight: 700 !important;
    line-height: 1.2 !important;
    cursor: pointer !important;
}

.notification-delivery-view-button span {
    position: relative !important;
    display: inline-block !important;
    width: 7px !important;
    height: 7px !important;
    margin: -3px 0 0 1px !important;
    font-size: 0 !important;
    line-height: 0 !important;
    border-right: 1.7px solid currentColor !important;
    border-bottom: 1.7px solid currentColor !important;
    transform: rotate(45deg) !important;
    transition: none !important;
}

/* Keep the chevron fixed even while details are open. */
.notification-delivery-view-button[aria-expanded="true"] span {
    transform: rotate(45deg) !important;
}

.notification-delivery-view-button:hover {
    text-decoration: underline;
}
</style>

@endsection
