@php
    $timezone = config('app.timezone') ?: 'Asia/Manila';
    $now = now($timezone);
    $systemPickupWindowExpired = $custody->pickup_expires_at
        && $now->gte($custody->pickup_expires_at);
    $pickupWasScheduled = (bool) $custody->pickup_scheduled_at;
    $pickupMissed = ! $custody->released_at
        && $pickupWasScheduled
        && ($custody->pickup_expired_at || $systemPickupWindowExpired);
    $scheduleException = ! $custody->released_at
        && ! $pickupWasScheduled
        && (
            ! $custody->scheduled_release_at
            || ! $custody->pickup_expires_at
            || $systemPickupWindowExpired
        );
    $scheduleDeliveries = collect($pickupScheduleNotification?->deliveries ?? [])->keyBy('channel');
    $missedDeliveries = collect($pickupMissedNotification?->deliveries ?? [])->keyBy('channel');

    $deliveryFailed = static function ($delivery): bool {
        return $delivery && strtoupper((string) $delivery->delivery_status) === 'FAILED';
    };

    $scheduleNotificationFailed = $deliveryFailed($scheduleDeliveries->get('SYSTEM'))
        || $deliveryFailed($scheduleDeliveries->get('EMAIL'));
    $missedNotificationFailed = $deliveryFailed($missedDeliveries->get('SYSTEM'))
        || $deliveryFailed($missedDeliveries->get('EMAIL'));
@endphp

<div id="pickup-schedule">
    @if(!$pickupWasScheduled)
        <div class="release-schedule-suggestion">
            <div>
                <strong>System Pickup &amp; Issuance Schedule</strong>
                <span>
                    @if($custody->scheduled_release_at && $custody->pickup_expires_at)
                        {{ $custody->scheduled_release_at->format('F j, Y') }} ·
                        {{ $custody->scheduled_release_at->format('g:i A') }} – {{ $custody->pickup_expires_at->format('g:i A') }}
                    @else
                        Schedule requires SPMU follow-up
                    @endif
                </span>
                <small>Automatically generated from the SPMU Operational Calendar. No Action Officer confirmation is required.</small>
            </div>
        </div>
    @elseif(!$pickupMissed)
        <div class="release-schedule-suggestion">
            <div>
                <strong>Pickup &amp; Issuance Schedule</strong>
                <span>
                    {{ $custody->scheduled_release_at?->format('F j, Y') }} ·
                    {{ $custody->scheduled_release_at?->format('g:i A') }} – {{ $custody->pickup_expires_at?->format('g:i A') }}
                </span>
                <small>Scheduled automatically from the SPMU Operational Calendar.</small>
            </div>
        </div>

        @if($pickupScheduleNotification)
            @if($scheduleNotificationFailed)
            <div class="notice warning compact">
                <strong>Borrower notification needs attention</strong>
                <p>The automatic pickup schedule is active, but at least one notification channel failed. Check the notification history before release.</p>
            </div>
        @else
            <div class="notice success compact">
                <strong>✓ Borrower notified</strong>
            </div>
            @endif
        @else
            <div class="notice warning compact">
                <strong>Borrower notification pending</strong>
                <p>The automatic schedule is active, but no pickup notification record is available yet.</p>
            </div>
        @endif
    @endif
</div>

@if($pickupMissed)
    <div class="notice warning compact release-pickup-exception-actions">
        <strong>Pickup schedule passed</strong>
        <p>
            The borrower did not complete pickup within the scheduled window. The approved request and reservation remain active. The borrower has been notified and must choose whether to request rescheduling or cancel the unreleased request.
        </p>

        @if($pickupMissedNotification)
            @if($missedNotificationFailed)
                <div class="notice warning compact">
                    <strong>Borrower notification needs attention</strong>
                    <p>The pickup-passed notice was recorded, but at least one notification channel failed.</p>
                </div>
            @else
                <p><strong>✓ Borrower notified that the pickup schedule passed.</strong></p>
            @endif
        @else
            <p><strong>Pickup-passed notification is pending.</strong></p>
        @endif

        @if($pickupRescheduleRequested)
            <div class="notice info compact">
                <strong>Borrower requested rescheduling.</strong>
                <p>
                    Keep the same approved request and reservation. SPMU may now move the pickup to the next valid operating window strictly before the approved Expected Return Date.
                </p>
            </div>

            @if($pickupRescheduleAvailable)
                <div class="release-form-actions">
                    <form method="post" action="{{ route('custody.reschedule-pickup', $custody) }}">
                        @csrf
                        <button class="button primary ui-pressable release-primary" type="submit">
                            Set Next Valid Pickup Schedule
                        </button>
                    </form>
                </div>
            @else
                <div class="notice warning compact">
                    <strong>No valid rescheduled pickup remains.</strong>
                    <p>
                        The next pickup cannot be on or after the approved Expected Return Date. The borrowing dates must be revised and approved, or the unreleased request must be cancelled.
                    </p>
                </div>
            @endif
        @else
            <div class="notice info compact">
                <strong>Waiting for borrower response</strong>
                <p>
                    Do not assign another pickup schedule yet. If the borrower still needs the items, they must select <strong>Request Reschedule</strong> in My Borrowings. If they no longer need the items, they may cancel the unreleased request.
                </p>
            </div>
        @endif
    </div>
@elseif($scheduleException)
    <div class="notice warning compact release-pickup-exception-actions">
        <strong>Pickup schedule needs SPMU follow-up</strong>
        <p>
            The system could not keep a usable pickup window from the SPMU Operational Calendar. This is an SPMU schedule exception, not a borrower missed pickup. Use the same approved request and move it to the next valid SPMU operating window when permitted.
        </p>
        @if($pickupRescheduleAvailable)
            <form method="post" action="{{ route('custody.reschedule-pickup', $custody) }}">
                @csrf
                <div class="release-form-actions">
                    <button class="button primary ui-pressable release-primary" type="submit">
                        Set Next Valid Pickup Schedule
                    </button>
                </div>
            </form>
        @else
            <div class="notice warning compact">
                <strong>No valid pickup window remains inside the approved period.</strong>
                <p>
                    A new pickup cannot be scheduled on or after the Expected Return Date.
                    Cancel the unreleased request or revise the borrowing dates for approval.
                </p>
            </div>
        @endif
    </div>
@endif
