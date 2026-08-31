@php
    /*
    |--------------------------------------------------------------------------
    | Audit history
    |--------------------------------------------------------------------------
    |
    | Collapsed by default so the record's own review content stays above the
    | fold; the latest status change is surfaced on the summary line so the
    | section still answers "what happened last?" without being opened.
    |
    | Shared by the SPMU review layout and the operational layout so both read
    | the same audit trail with the same controls.
    |
    */
    $latestHistory = $borrowingRequest->statusHistory->sortByDesc('changed_at')->first();

    $latestHistoryLabel = match ($latestHistory?->to_status) {
        'APPROVED_READY_FOR_RELEASE', 'FINAL_APPROVED_AWAITING_DOWNLOAD' => 'Approved',
        'UNDER_SPMU' => 'Submitted',
        default => $latestHistory
            ? str($latestHistory->to_status)->replace('_', ' ')->lower()->ucfirst()
            : null,
    };
@endphp

<details class="card request-activity-history">
    <summary class="request-activity-summary">
        <span class="request-activity-heading">
            <span class="request-section-title">
                <x-icon name="clock" size="18" />
                <span>Audit history</span>
            </span>

            <span class="request-activity-latest">
                @if($latestHistory)
                    Latest status change:
                    {{ $latestHistoryLabel }}
                    by {{ $latestHistory->actor?->full_name ?: 'System' }}
                    on {{ optional($latestHistory->changed_at)->format('d M Y, g:i A') }}
                @else
                    No status history yet.
                @endif
            </span>
        </span>

        <span class="request-activity-toggle">
            <span class="request-history-show">Show history</span>
            <span class="request-history-hide">Hide history</span>
            <x-icon name="chevron-down" size="17" />
        </span>
    </summary>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>When</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Actor</th>
                    <th>Reason</th>
                </tr>
            </thead>
            <tbody>
            @forelse($borrowingRequest->statusHistory as $history)
                @php
                    $fromStatus = match($history->from_status) {
                        'UNDER_GSU', 'UNDER_VPAF' => 'LEGACY_REVIEW',
                        default => $history->from_status,
                    };
                    $toStatus = match($history->to_status) {
                        'UNDER_GSU', 'UNDER_VPAF' => 'LEGACY_REVIEW',
                        default => $history->to_status,
                    };
                @endphp
                <tr>
                    <td>{{ optional($history->changed_at)->format('d M Y, g:i A') }}</td>
                    <td>{{ $fromStatus ?: '—' }}</td>
                    <td>{{ $toStatus }}</td>
                    <td>{{ $history->actor?->full_name ?: 'System' }}</td>
                    <td>{{ $history->reason ?: '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">No status history.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</details>

@once
<style>
    /*
     * <details> gives the accordion its semantics, keyboard handling and
     * aria-expanded for free, so no script is needed here.
     */
    .request-activity-history { padding: 0; }

    .request-activity-summary {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 15px 18px;
        cursor: pointer;
        list-style: none;
    }

    .request-activity-summary::-webkit-details-marker { display: none; }

    .request-activity-summary:focus-visible {
        outline: 0;
        border-radius: var(--radius);
        box-shadow: var(--focus-ring);
    }

    .request-activity-heading { display: grid; gap: 5px; min-width: 0; }

    .request-section-title {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        color: var(--heading);
        font-size: 15px;
        font-weight: 700;
    }

    .request-section-title > .ui-icon { color: var(--text-soft); }

    .request-activity-latest { color: var(--text-muted); font-size: 12px; }

    .request-activity-toggle {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        flex: 0 0 auto;
        color: var(--interactive);
        font-size: 12px;
        font-weight: 700;
    }

    .request-activity-toggle .ui-icon { transition: transform 160ms ease; }

    .request-activity-history .request-history-hide,
    .request-activity-history[open] .request-history-show { display: none; }
    .request-activity-history[open] .request-history-hide { display: inline; }
    .request-activity-history[open] .request-activity-toggle .ui-icon { transform: rotate(180deg); }

    .request-activity-history > .table-wrap {
        margin: 0 18px 18px;
        border-top: 1px solid var(--border);
    }

    .request-activity-history > .table-wrap table { min-width: 700px; }

    @media (max-width: 760px) {
        .request-activity-summary { align-items: flex-start; flex-direction: column; gap: 10px; }
    }
</style>
@endonce
