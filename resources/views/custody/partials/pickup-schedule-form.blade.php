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

<div id="pickup-schedule" class="release-schedule-details">
    @if(!$pickupWasScheduled)
        <div class="release-schedule-detail-row">
            <span class="release-schedule-detail-label">Schedule status</span>
            <span class="release-schedule-detail-value">
                {{ $custody->scheduled_release_at && $custody->pickup_expires_at
                    ? 'Waiting for automatic schedule activation'
                    : 'SPMU follow-up required' }}
            </span>
        </div>
    @elseif(!$pickupMissed)
        <div class="release-schedule-detail-row">
            <span class="release-schedule-detail-label">Borrower notification</span>
            <span class="release-schedule-detail-value">
                @if($pickupScheduleNotification)
                    {{ $scheduleNotificationFailed ? 'Needs attention' : 'Sent' }}
                @else
                    Pending
                @endif
            </span>
        </div>

        @if($pickupScheduleNotification && $scheduleNotificationFailed)
            <p class="release-schedule-detail-help">
                At least one notification channel failed. Check Notification History before physical release.
            </p>
        @elseif(!$pickupScheduleNotification)
            <p class="release-schedule-detail-help">
                The pickup schedule is active, but no pickup notification record is available yet.
            </p>
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

    <div class="release-schedule-exception release-pickup-exception-actions">
        <div>
            <strong>{{ $pickupRescheduleRequested ? 'Reschedule requested' : 'Borrower options' }}</strong>

            @if($pickupRescheduleRequested)
                <p>The borrower requested a new pickup schedule.</p>
            @else
                <p>Waiting for borrower action: <strong>Request Reschedule</strong> or <strong>Cancel Request</strong>.</p>
            @endif

            @if($missedNotificationFailed)
                <p class="meta">Borrower notification needs attention.</p>
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
    <div class="release-schedule-exception release-pickup-exception-actions">
        <strong>Pickup schedule needs SPMU follow-up</strong>
        <p>
            The system could not keep a usable pickup window from the SPMU Operational Calendar. This is an SPMU schedule exception, not a borrower missed pickup.
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
            <div class="release-schedule-exception-note">
                <strong>No valid pickup window remains inside the approved period.</strong>
                <p>A new pickup cannot be scheduled on or after the Expected Return Date. Cancel the unreleased request or revise the borrowing dates for approval.</p>
            </div>
        @endif
    </div>
@endif
