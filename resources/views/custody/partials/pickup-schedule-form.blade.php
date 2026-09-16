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
    $missedDeliveryRows = collect($pickupMissedDeliveries ?? $pickupMissedNotification?->deliveries ?? []);

    $deliveryFailed = static function ($delivery): bool {
        return $delivery && strtoupper((string) $delivery->delivery_status) === 'FAILED';
    };

    $scheduleNotificationFailed = $deliveryFailed($scheduleDeliveries->get('SYSTEM'))
        || $deliveryFailed($scheduleDeliveries->get('EMAIL'));
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
    @php
        $missedNotificationFailed = $missedDeliveryRows->contains(
            fn ($delivery) => strtoupper((string) $delivery->delivery_status) === 'FAILED'
        );
        $missedNotificationSent = $missedDeliveryRows->contains(
            fn ($delivery) => strtoupper((string) $delivery->delivery_status) === 'SENT'
        );
    @endphp

    <div class="notice warning compact release-pickup-exception-actions">
        <div>
            <strong>{{ $pickupRescheduleRequested ? 'Reschedule requested' : 'Pickup missed' }}</strong>

            @if($pickupRescheduleRequested)
                <p>The borrower requested a new pickup schedule.</p>
            @else
                <p>Waiting for borrower action: <strong>Request Reschedule</strong> or <strong>Cancel Request</strong>.</p>
            @endif

            @if($missedNotificationFailed)
                <p class="meta">Notification needs attention.</p>
            @elseif($missedNotificationSent)
                <p class="meta">Borrower notified.</p>
            @endif

            @if($pickupRescheduleRequested)
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
                    <p class="meta">No valid pickup window remains before the Expected Return Date.</p>
                @endif
            @elseif(!$pickupRescheduleAvailable)
                <p class="meta">No valid pickup window remains before the Expected Return Date.</p>
            @endif
        </div>
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
