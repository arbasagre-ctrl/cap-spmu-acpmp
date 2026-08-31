<form method="post" action="{{ route('custody.schedule-pickup', $custody) }}" class="form-grid release-schedule-form">
    @csrf
    <div class="content-grid two release-schedule-fields">
        <label>
            Pickup Date &amp; Time
            <input
                type="datetime-local"
                name="pickup_at"
                value="{{ old('pickup_at', optional($custody->scheduled_release_at)->format('Y-m-d\\TH:i')) }}"
                required
            >
        </label>

        <label>
            Claim Until
            <input
                type="datetime-local"
                name="pickup_expires_at"
                value="{{ old('pickup_expires_at', optional($custody->pickup_expires_at)->format('Y-m-d\\TH:i')) }}"
                required
            >
        </label>
    </div>

    <div class="release-form-actions">
        @if($hasPickupSchedule)
            <button class="button secondary ui-pressable release-outline" type="button" data-release-schedule-cancel>Cancel</button>
        @endif
        <button class="button primary ui-pressable release-primary" type="submit">
            {{ $hasPickupSchedule ? 'Update Pickup Schedule' : 'Schedule Pickup' }}
        </button>
    </div>
</form>
