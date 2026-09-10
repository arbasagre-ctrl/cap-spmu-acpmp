@php
    /*
    |--------------------------------------------------------------------------
    | Transaction history
    |--------------------------------------------------------------------------
    |
    | This view intentionally builds a readable lifecycle timeline from the
    | records already loaded for the request detail page. It does not create or
    | modify audit/business records. The goal is to show significant events
    | beyond the request-status transitions (pickup, release, return, etc.)
    | without exposing raw internal status codes to the user.
    |
    */
    $historyEvents = collect();

    $addHistoryEvent = function (
        $when,
        string $stage,
        string $event,
        string $actor,
        ?string $details = null,
        ?string $key = null
    ) use (&$historyEvents): void {
        if (! $when) {
            return;
        }

        $historyEvents->push([
            'when' => $when,
            'stage' => $stage,
            'event' => $event,
            'actor' => $actor,
            'details' => filled($details) ? $details : '—',
            'key' => $key ?: $stage.'|'.$event.'|'.$when->format('Y-m-d H:i:s'),
        ]);
    };

    // 1) Request preparation / creation.
    $addHistoryEvent(
        $borrowingRequest->created_at,
        'Request',
        'Request prepared',
        $borrowingRequest->borrower?->full_name ?: 'Borrower',
        'Borrowing request record created.',
        'request-created'
    );

    // 2) Request status changes, translated into user-facing lifecycle events.
    foreach ($borrowingRequest->statusHistory as $history) {
        $rawToStatus = $history->to_status instanceof \BackedEnum
            ? $history->to_status->value
            : (string) $history->to_status;

        [$eventLabel, $defaultDetails] = match ($rawToStatus) {
            'UNDER_SPMU' => [
                'Request submitted',
                'Request submitted to SPMU for the applicable verification/review process.',
            ],
            'RETURNED_FOR_REVISION' => [
                'Returned for revision',
                'The request was returned for borrower correction.',
            ],
            'REJECTED' => [
                'Request rejected',
                'The request did not proceed to physical issuance.',
            ],
            'APPROVED_READY_FOR_RELEASE', 'FINAL_APPROVED_AWAITING_DOWNLOAD' => [
                'Request approved',
                'Final SPMU approval recorded; the transaction may proceed to pickup and issuance processing.',
            ],
            'CANCELLED' => [
                'Request cancelled',
                'The request was cancelled before completion of the borrowing lifecycle.',
            ],
            'DRAFT' => [
                'Request saved as draft',
                'Draft request retained for further preparation.',
            ],
            default => [
                str($rawToStatus)->replace('_', ' ')->lower()->title()->toString(),
                'Request status updated.',
            ],
        };

        $addHistoryEvent(
            $history->changed_at,
            'Request',
            $eventLabel,
            $history->actor?->full_name ?: 'System',
            $history->reason ?: $defaultDetails,
            'request-status-'.$history->id
        );
    }

    // 3) AO verification is a significant review event but may not change the
    // top-level request status, so surface it from the approval step itself.
    $verificationStep = $borrowingRequest->currentVersion?->approvalSteps
        ?->first(fn ($step) => (int) $step->sequence_no === 1 && strtoupper((string) $step->decision) === 'VERIFIED');

    if ($verificationStep) {
        $addHistoryEvent(
            $verificationStep->decided_at,
            'Review',
            'Action Officer verification completed',
            $verificationStep->approver?->full_name ?: 'SPMU Action Officer',
            $verificationStep->remarks ?: 'Request and supporting documents verified for Head/Admin review.',
            'verification-'.$verificationStep->id
        );
    }

    $custody = $borrowingRequest->custody;

    if ($custody) {
        // 4) Pickup / release preparation.
        $addHistoryEvent(
            $custody->pickup_scheduled_at ?: $custody->scheduled_release_at,
            'Pickup',
            'Pickup schedule activated',
            'System',
            $custody->scheduled_release_at
                ? 'Pickup/issuance scheduled for '.$custody->scheduled_release_at->format('d M Y, g:i A').'.'
                : 'Pickup/issuance schedule recorded.',
            'pickup-scheduled'
        );

        $addHistoryEvent(
            $custody->prepared_at,
            'Release',
            'Items prepared for release',
            'SPMU Action Officer',
            'Approved quantities were prepared for physical issuance.',
            'release-prepared'
        );

        $addHistoryEvent(
            $custody->released_at,
            'Release',
            'Items physically released',
            'SPMU Action Officer',
            'Physical issuance recorded and custody began for the released items.',
            'items-released'
        );

        // 5) Applicable Gate Pass evidence.
        $gatePass = $custody->gatePass;
        if ($gatePass) {
            $gatePassRecordedAt = $gatePass->verified_at ?: $gatePass->uploaded_at;

            $addHistoryEvent(
                $gatePassRecordedAt,
                'Gate Pass',
                'Accomplished Gate Pass recorded',
                'SPMU Action Officer',
                'Signed/accomplished Gate Pass received and recorded by SPMU.',
                'gate-pass-recorded'
            );
        }

        // 6) Applicable Laundry evidence / completion.
        $laundryJob = $custody->laundryJob;
        if ($laundryJob) {
            $addHistoryEvent(
                $laundryJob->form_verified_at,
                'Laundry',
                'Accomplished Laundry Form recorded',
                'SPMU Action Officer',
                'Physical Laundry Form evidence was received and verified by SPMU.',
                'laundry-form-recorded'
            );

            $addHistoryEvent(
                $laundryJob->completed_at,
                'Laundry',
                'Laundry processing completed',
                'System',
                'Applicable serviceable linen processing was completed.',
                'laundry-completed'
            );
        }

        // 7) Physical receipt and condition inspection are separate events.
        // For legacy rows that predate the split, keep the original one-step
        // inspection event at received_at so historical records remain readable.
        foreach ($custody->returns->sortBy('received_at') as $return) {
            $hasSplitReceipt = ! empty($return->receipt_quantities)
                || $return->status === 'RECEIVED'
                || $return->inspected_at;

            if ($hasSplitReceipt) {
                $isLinenOnlyReturn = $return->lines->isNotEmpty()
                    && $return->lines->every(
                        fn ($line) => (bool) $line->custodyLine?->requestItem?->inventoryItem?->laundry_required
                    );

                $addHistoryEvent(
                    $return->received_at,
                    'Return',
                    $isLinenOnlyReturn
                        ? 'Physical return date recorded from Laundry Form'
                        : 'Returned items received by SPMU',
                    $isLinenOnlyReturn ? 'Laundry / SPMU record' : 'SPMU Action Officer',
                    $isLinenOnlyReturn
                        ? 'Borrower timeliness uses the Laundry Form RECEIVED BY date.'
                        : 'The physical handover date/time was locked before condition inspection.',
                    'return-received-'.$return->id
                );

                $addHistoryEvent(
                    $return->inspected_at,
                    'Return',
                    strtoupper((string) $return->return_type) === 'EARLY'
                        ? 'Early return inspection recorded'
                        : 'Return inspection recorded',
                    'SPMU Action Officer',
                    $return->remarks ?: 'Returned quantities and condition/accountability findings were recorded.',
                    'return-inspected-'.$return->id
                );

                continue;
            }

            $addHistoryEvent(
                $return->received_at ?: $return->confirmed_at,
                'Return',
                strtoupper((string) $return->return_type) === 'EARLY'
                    ? 'Early return inspection recorded'
                    : 'Physical return inspection recorded',
                'SPMU Action Officer',
                $return->remarks ?: 'Returned quantities and condition findings were recorded.',
                'return-'.$return->id
            );
        }

        // 8) Accountability continues the SAME borrowing transaction after the
        // physical return. It must remain visible for Borrower, Action Officer,
        // and Head/Admin until every linked obligation is resolved.
        $borrowerHistoryView = strtoupper((string) session('active_workspace')) === 'BORROWER';

        foreach (($accountabilityHistory ?? collect()) as $accountabilityEvent) {
            $addHistoryEvent(
                $accountabilityEvent['when'],
                $accountabilityEvent['stage'],
                $borrowerHistoryView
                    ? $accountabilityEvent['event_borrower']
                    : $accountabilityEvent['event_spmu'],
                $accountabilityEvent['actor'],
                $borrowerHistoryView
                    ? $accountabilityEvent['details_borrower']
                    : $accountabilityEvent['details_spmu'],
                $accountabilityEvent['key']
            );
        }

        // 9) Final completion is shown only when the custody closure gate has
        // cleared return/documentation AND all linked accountability records.
        $addHistoryEvent(
            $custody->closed_at,
            'Completion',
            'Transaction completed',
            'System',
            'Custody was closed after the applicable return, documentation, inventory, and accountability checks were completed.',
            'custody-closed'
        );
    }

    $historyEvents = $historyEvents
        ->filter(fn ($item) => $item['when'])
        ->unique('key')
        ->sortByDesc(fn ($item) => $item['when']->getTimestamp())
        ->values();

    $latestHistory = $historyEvents->first();
@endphp

<details class="card request-activity-history">
    <summary class="request-activity-summary">
        <span class="request-activity-heading">
            <span class="request-section-title">
                <x-icon name="clock" size="18" />
                <span>Transaction history</span>
            </span>

            <span class="request-activity-latest">
                @if($latestHistory)
                    Latest recorded event:
                    <strong>{{ $latestHistory['event'] }}</strong>
                    · {{ $latestHistory['when']->format('d M Y, g:i A') }}
                @else
                    No transaction history yet.
                @endif
            </span>
        </span>

        <span class="request-activity-toggle">
            <span class="request-history-show">Show history</span>
            <span class="request-history-hide">Hide history</span>
            <x-icon name="chevron-down" size="17" />
        </span>
    </summary>

    <div class="request-history-intro">
        Significant recorded events from request preparation through pickup, release, return, accountability, resolution, and final completion.
    </div>

    <div class="table-wrap request-history-table-wrap">
        <table class="request-history-table">
            <colgroup>
                <col class="request-history-col-when">
                <col class="request-history-col-stage">
                <col class="request-history-col-event">
                <col class="request-history-col-actor">
                <col class="request-history-col-details">
            </colgroup>
            <thead>
                <tr>
                    <th>When</th>
                    <th>Stage</th>
                    <th>Event</th>
                    <th>Actor</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
            @forelse($historyEvents as $history)
                <tr>
                    <td data-label="When">{{ $history['when']->format('d M Y, g:i A') }}</td>
                    <td data-label="Stage">
                        <span class="request-history-stage">{{ $history['stage'] }}</span>
                    </td>
                    <td data-label="Event" class="request-history-event">{{ $history['event'] }}</td>
                    <td data-label="Actor">{{ $history['actor'] }}</td>
                    <td data-label="Details" class="request-history-details">{{ $history['details'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="request-history-empty">No transaction history has been recorded yet.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</details>

@once
<style>
    /*
     * <details> supplies native accordion semantics and keyboard handling.
     * The card itself clips accidental visual overflow; the table wrapper owns
     * horizontal scrolling only when the viewport genuinely becomes narrow.
     */
    .request-activity-history {
        padding: 0;
        overflow: hidden;
    }

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

    .request-activity-heading {
        display: grid;
        gap: 5px;
        min-width: 0;
    }

    .request-section-title {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        color: var(--heading);
        font-size: 15px;
        font-weight: 700;
    }

    .request-section-title > .ui-icon { color: var(--text-soft); }

    .request-activity-latest {
        color: var(--text-muted);
        font-size: 12px;
        line-height: 1.45;
        overflow-wrap: anywhere;
    }

    .request-activity-latest strong {
        color: var(--text);
        font-weight: 700;
    }

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

    .request-history-intro {
        margin: 0 18px;
        padding: 12px 0 11px;
        border-top: 1px solid var(--border);
        color: var(--text-muted);
        font-size: 12px;
        line-height: 1.5;
    }

    .request-activity-history > .request-history-table-wrap {
        box-sizing: border-box;
        width: calc(100% - 36px);
        max-width: calc(100% - 36px);
        margin: 0 18px 18px;
        overflow-x: auto;
        overscroll-behavior-inline: contain;
        border: 1px solid var(--border);
        border-radius: calc(var(--radius) - 2px);
    }

    .request-history-table {
        width: 100%;
        min-width: 820px;
        table-layout: fixed;
        margin: 0;
    }

    .request-history-col-when { width: 150px; }
    .request-history-col-stage { width: 105px; }
    .request-history-col-event { width: 205px; }
    .request-history-col-actor { width: 165px; }
    .request-history-col-details { width: auto; }

    .request-history-table th,
    .request-history-table td {
        vertical-align: top;
        white-space: normal;
        overflow-wrap: anywhere;
        word-break: normal;
    }

    .request-history-table td {
        line-height: 1.45;
    }

    .request-history-stage {
        display: inline-flex;
        align-items: center;
        min-height: 24px;
        padding: 3px 8px;
        border: 1px solid color-mix(in srgb, var(--interactive) 22%, var(--border));
        border-radius: 999px;
        background: color-mix(in srgb, var(--interactive) 7%, var(--surface));
        color: var(--interactive);
        font-size: 11px;
        font-weight: 700;
        line-height: 1.2;
    }

    .request-history-event {
        color: var(--heading);
        font-weight: 700;
    }

    .request-history-details {
        color: var(--text-muted);
    }

    .request-history-empty {
        padding: 20px !important;
        text-align: center;
        color: var(--text-muted);
    }

    @media (max-width: 760px) {
        .request-activity-summary {
            align-items: flex-start;
            flex-direction: column;
            gap: 10px;
        }

        .request-activity-toggle {
            align-self: flex-end;
        }

        .request-history-table {
            min-width: 760px;
        }
    }
</style>
@endonce
