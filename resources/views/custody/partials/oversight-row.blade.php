@php
    $version = $custody->request?->currentVersion;
    $scheduleDateValue = $version?->schedule_date ?: $version?->needed_from;
    $returnDateValue = $version?->return_date ?: $version?->return_due_at ?: $custody->due_at;

    $scheduleDate = $scheduleDateValue ? \Illuminate\Support\Carbon::parse($scheduleDateValue) : null;
    $returnDate = $returnDateValue ? \Illuminate\Support\Carbon::parse($returnDateValue) : null;

    $hasActivePickupSchedule = (bool) $custody->scheduled_release_at
        && (bool) $custody->pickup_expires_at
        && ! $custody->pickup_expired_at;

    $isCompleted = $custody->status === 'CLOSED' || $custody->closed_at !== null;

    /*
     * Borrower Cleared vs. Completed (see custody/show.blade.php for the full
     * rule): Completed requires, for linen, that internal Laundry processing
     * has finished AND the Laundry Form has been archived - not archival alone.
     */
    $rowHasLaundryItem = $custody->lines->contains(
        fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
    );
    $rowLaundryJob = $custody->relationLoaded('laundryJob') ? $custody->laundryJob : null;
    $isFullyComplete = $isCompleted
        && (
            ! $rowHasLaundryItem
            || ($rowLaundryJob?->status === 'LAUNDRY_COMPLETED' && $rowLaundryJob?->latestEvidence?->file)
        );

    // Detailed operational stage, kept as the badge tooltip so the oversight
    // list stays readable without losing the precise workflow position.
    $operationalLabel = match (true) {
        $isCompleted => $isFullyComplete ? 'Completed' : 'Borrower Cleared',
        $custody->status === 'OBLIGATION_OPEN' => 'Obligation Open',
        $custody->status === 'INCIDENT_OPEN' => 'Incident Open',
        in_array($custody->status, ['RETURN_PROCESSING', 'PARTIALLY_RETURNED'], true) => 'Return Processing',
        $custody->status === 'OVERDUE' => 'Overdue',
        (bool) $custody->released_at => 'Items Released / On Custody',
        (bool) $custody->prepared_at && $hasActivePickupSchedule => 'Ready for Release',
        $hasActivePickupSchedule => 'Pickup Scheduled / For Item Preparation',
        $custody->status === 'PREPARING_RELEASE' => 'For Pickup Scheduling',
        default => null,
    };

    $group = $groupForCustody($custody);
    $priority = $priorityForCustody($custody);

    $groupLabel = $oversightTabs[$group] ?? 'Active';
    $badgeStatus = match ($group) {
        'attention' => 'OVERDUE',
        'return' => 'RETURN_PROCESSING',
        'custody' => 'BORROWED',
        'release' => 'PREPARING_RELEASE',
        'completed' => 'COMPLETED',
        default => 'ACTIVE',
    };

    $borrowerName = $custody->borrower?->full_name ?: 'Borrower';
    $borrowerUnit = $custody->borrower?->organizationalUnit?->unit_name;

    $initials = \Illuminate\Support\Str::of($borrowerName)
        ->squish()
        ->explode(' ')
        ->filter()
        ->take(3)
        ->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))
        ->implode('');
    $initials = $initials !== '' ? $initials : 'B';

    // Stable per-borrower avatar colour so the same person reads the same way
    // across pages of the oversight list. It stays behind an uploaded photo
    // and shows through again if the picture cannot be loaded.
    $avatarTone = (crc32($borrowerName) % 6) + 1;

    $borrowerPhotoUrl = filled($custody->borrower?->profile_picture_path)
        && Route::has('users.picture.show')
            ? route('users.picture.show', $custody->borrower)
                .'?v='.($custody->borrower->updated_at?->timestamp ?? 0)
            : null;

    $searchText = strtolower(trim(
        $borrowerName.' '.
        ($custody->request?->request_no ?? '').' '.
        ($custody->custody_no ?? '').' '.
        ($borrowerUnit ?? '').' '.
        ($version?->purpose_event ?? '')
    ));
@endphp

<a
    class="custody-oversight-row ui-pressable"
    href="{{ route('custody.show', $custody) }}"
    data-custody-record
    data-custody-group="{{ $group }}"
    data-custody-priority="{{ $priority }}"
    data-created="{{ optional($custody->created_at)->timestamp ?? 0 }}"
    data-search="{{ $searchText }}"
    data-schedule="{{ optional($scheduleDate)->format('Y-m-d') }}"
    data-return="{{ optional($returnDate)->format('Y-m-d') }}"
    data-pickup="{{ optional($custody->scheduled_release_at)->format('Y-m-d') ?: optional($scheduleDate)->format('Y-m-d') }}"
    data-dates="{{ collect([
        optional($scheduleDate)->format('Y-m-d'),
        optional($returnDate)->format('Y-m-d'),
        optional($custody->scheduled_release_at)->format('Y-m-d'),
        optional($custody->released_at)->format('Y-m-d'),
        optional($custody->closed_at)->format('Y-m-d'),
    ])->filter()->unique()->implode(',') }}"
    aria-label="View release and return details for {{ $custody->custody_no ?: $borrowerName }}"
>
    <div class="custody-oversight-borrower">
        <span class="custody-oversight-avatar" data-avatar-tone="{{ $avatarTone }}" aria-hidden="true">
            {{ $initials }}

            @if($borrowerPhotoUrl)
                <img
                    class="custody-oversight-avatar-photo"
                    src="{{ $borrowerPhotoUrl }}"
                    alt=""
                    loading="lazy"
                    onerror="this.remove()"
                >
            @endif
        </span>

        <span class="custody-oversight-identity">
            <span class="custody-oversight-name">
                <span>{{ $borrowerName }}</span>

                @if($borrowerUnit)
                    <span class="custody-oversight-unit" title="{{ $borrowerUnit }}">
                        <x-icon name="information" size="13" />
                        <span class="visually-hidden">{{ $borrowerUnit }}</span>
                    </span>
                @endif
            </span>

            <span class="custody-oversight-request">{{ $custody->request?->request_no ?: 'Request not linked' }}</span>

            <span class="custody-oversight-schedule">
                Schedule: {{ optional($scheduleDate)->format('d M Y') ?: 'Not set' }}
                – Return: {{ optional($returnDate)->format('d M Y') ?: 'Not set' }}
            </span>
        </span>
    </div>

    <div class="custody-oversight-facts">
        <span class="custody-oversight-fact">
            <small>Pickup</small>
            <strong>{{ optional($custody->scheduled_release_at)->format('d M Y, h:i A') ?: 'Not scheduled' }}</strong>
        </span>

        <span class="custody-oversight-fact">
            <small>Issued</small>
            <strong>{{ optional($custody->released_at)->format('d M Y, h:i A') ?: 'Not yet' }}</strong>
        </span>

        <span class="custody-oversight-fact">
            <small>Custody No.</small>
            <strong>{{ $custody->custody_no ?: '—' }}</strong>
        </span>

        <span class="custody-oversight-fact">
            <small>Status</small>
            <x-status-badge
                :status="$badgeStatus"
                :label="$groupLabel"
                :title="$operationalLabel ?: $groupLabel"
            />
        </span>
    </div>

</a>

