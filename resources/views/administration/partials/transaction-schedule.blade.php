{{--
    Weekly Transaction Schedule.

    Every weekday posts through one form so the SPMU Head can review the whole
    week before committing. A row's own Save sets `only_day`, which limits
    persistence to that weekday and leaves unsaved edits elsewhere untouched.
--}}
<section class="content-area" id="transaction-schedule">
    <article class="card txn-schedule-card">
        <header class="txn-schedule-header">
            <span class="txn-schedule-header-icon" aria-hidden="true">
                <x-icon name="calendar" size="24" />
            </span>

            <div>
                <h2>Weekly Transaction Schedule</h2>
                <p>
                    Set which weekdays accept request submissions, pickup/release transactions, and physical returns.<br>
                    Saturday and Sunday are closed by default but may be opened when the institution declares a working day.
                </p>
            </div>
        </header>

        <div class="txn-schedule-note">
            <x-icon name="information" size="20" />

            <p>
                <strong>Return protection:</strong><br>
                If an Expected Return Date becomes a closed return day, the system keeps the original date for audit and
                automatically moves the effective return deadline to the next open SPMU return day.<br>
                The borrower is not marked late because of an approved closure.
            </p>
        </div>

        <form
            method="post"
            action="{{ route('policies.weekly-schedule.batch-update') }}"
            class="txn-schedule-form"
            data-weekly-schedule-form
        >
            @csrf
            @method('PUT')

            <input type="hidden" name="only_day" value="" data-weekly-only-day>

            <div class="txn-schedule-grid">
                <div class="txn-schedule-head">
                    <span>Day</span>
                    <span>Availability</span>
                    <span title="Optional. Leave both times blank for a date-based policy.">
                        Open time
                        <x-icon name="information" size="13" class="txn-head-hint" />
                    </span>
                    <span title="Must be later than the opening time.">
                        Close time
                        <x-icon name="information" size="13" class="txn-head-hint" />
                    </span>
                    <span class="txn-head-action">Action</span>
                </div>

                @foreach($weekdayLabels as $weekday => $weekdayLabel)
                    @php
                        $weekly = $weeklySchedules->get($weekday);

                        $isOpen = (bool) old("schedule.{$weekday}.is_open", $weekly?->is_open ?? ($weekday <= 5));
                        $acceptsRequests = (bool) old("schedule.{$weekday}.accepts_requests", $weekly?->accepts_requests ?? ($weekday <= 5));
                        $allowsPickup = (bool) old("schedule.{$weekday}.allows_pickup", $weekly?->allows_pickup ?? ($weekday <= 5));
                        $allowsReturn = (bool) old("schedule.{$weekday}.allows_return", $weekly?->allows_return ?? ($weekday <= 5));

                        $openTime = old("schedule.{$weekday}.open_time", $weekly?->open_time ? substr((string) $weekly->open_time, 0, 5) : '');
                        $closeTime = old("schedule.{$weekday}.close_time", $weekly?->close_time ? substr((string) $weekly->close_time, 0, 5) : '');
                    @endphp

                    <div
                        class="txn-schedule-row{{ $isOpen ? '' : ' is-closed' }}"
                        data-weekday-row
                    >
                        <div class="txn-day">
                            <strong>{{ $weekdayLabel }}</strong>
                            <span class="txn-day-status">
                                <i aria-hidden="true"></i>
                                <span data-weekday-status>{{ $isOpen ? 'Operational day' : 'Closed day' }}</span>
                            </span>
                        </div>

                        <div class="txn-availability">
                            <label class="txn-check">
                                <input type="hidden" name="schedule[{{ $weekday }}][is_open]" value="0">
                                <input
                                    type="checkbox"
                                    name="schedule[{{ $weekday }}][is_open]"
                                    value="1"
                                    data-weekday-open
                                    @checked($isOpen)
                                >
                                <span>Open</span>
                            </label>

                            <label class="txn-check">
                                <input type="hidden" name="schedule[{{ $weekday }}][accepts_requests]" value="0">
                                <input
                                    type="checkbox"
                                    name="schedule[{{ $weekday }}][accepts_requests]"
                                    value="1"
                                    data-weekday-capability
                                    @checked($acceptsRequests)
                                >
                                <span>Requests</span>
                            </label>

                            <label class="txn-check">
                                <input type="hidden" name="schedule[{{ $weekday }}][allows_pickup]" value="0">
                                <input
                                    type="checkbox"
                                    name="schedule[{{ $weekday }}][allows_pickup]"
                                    value="1"
                                    data-weekday-capability
                                    @checked($allowsPickup)
                                >
                                <span>Pickup / Release</span>
                            </label>

                            <label class="txn-check">
                                <input type="hidden" name="schedule[{{ $weekday }}][allows_return]" value="0">
                                <input
                                    type="checkbox"
                                    name="schedule[{{ $weekday }}][allows_return]"
                                    value="1"
                                    data-weekday-capability
                                    @checked($allowsReturn)
                                >
                                <span>Returns</span>
                            </label>
                        </div>

                        <span class="txn-time-field">
                            <label class="visually-hidden" for="open-time-{{ $weekday }}">{{ $weekdayLabel }} opening time</label>
                            <input
                                id="open-time-{{ $weekday }}"
                                type="time"
                                name="schedule[{{ $weekday }}][open_time]"
                                value="{{ $openTime }}"
                                data-weekday-time
                                @error("schedule.{$weekday}.open_time") aria-invalid="true" @enderror
                            >
                            <x-icon name="clock" size="16" />
                        </span>

                        <span class="txn-time-field">
                            <label class="visually-hidden" for="close-time-{{ $weekday }}">{{ $weekdayLabel }} closing time</label>
                            <input
                                id="close-time-{{ $weekday }}"
                                type="time"
                                name="schedule[{{ $weekday }}][close_time]"
                                value="{{ $closeTime }}"
                                data-weekday-time
                                @error("schedule.{$weekday}.close_time") aria-invalid="true" @enderror
                            >
                            <x-icon name="clock" size="16" />
                        </span>

                        <div class="txn-row-action">
                            <button
                                class="button secondary small ui-pressable txn-row-save"
                                type="submit"
                                data-weekday-save="{{ $weekday }}"
                                title="Save {{ $weekdayLabel }} only"
                            >
                                Save
                            </button>
                        </div>

                        @error("schedule.{$weekday}.close_time")
                            <p class="txn-row-error">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach
            </div>

            <p class="txn-schedule-footnote">
                <span class="txn-schedule-footnote-icon" aria-hidden="true">
                    <x-icon name="lightbulb" size="17" />
                </span>
                <span>
                    Opening and closing times are optional. Leave both blank when the policy is date-based only. When times are configured, physical pickup/release
                    and return submissions are accepted only inside that operational window.
                </span>
            </p>

            <div class="txn-schedule-actions">
                <button class="button secondary ui-pressable" type="reset" data-weekly-reset>
                    <x-icon name="cycle" size="17" />
                    Reset changes
                </button>

                <button class="button primary ui-pressable" type="submit" data-weekly-save-all>
                    <x-icon name="save" size="17" />
                    Save schedule
                </button>
            </div>
        </form>
    </article>
</section>

<style>
.txn-schedule-card {
    --txn-line: #e6ecf3;
    --txn-blue: #1a6fd4;
    --txn-open: #22a06b;
    --txn-closed: #98a4b3;

    padding: 0;
    overflow: hidden;
}

.txn-schedule-header {
    display: grid;
    grid-template-columns: 52px minmax(0, 1fr);
    gap: 18px;
    align-items: start;
    padding: 26px 28px 20px;
}

.txn-schedule-header-icon {
    display: grid;
    width: 52px;
    height: 52px;
    place-items: center;
    color: var(--txn-blue);
    background: #e8f1fd;
    border-radius: 14px;
}

.txn-schedule-header h2 {
    margin: 4px 0 8px;
    color: var(--heading);
    font-size: 20px;
    font-weight: 700;
}

.txn-schedule-header p {
    max-width: 900px;
    margin: 0;
    color: var(--text-secondary);
    font-size: 13px;
    line-height: 1.65;
}

.txn-schedule-note {
    display: grid;
    grid-template-columns: 20px minmax(0, 1fr);
    gap: 14px;
    align-items: start;
    margin: 0 28px 22px;
    padding: 16px 18px;
    border: 1px solid #c5ddf6;
    border-radius: 12px;
    background: #edf5fd;
}

.txn-schedule-note > .ui-icon {
    margin-top: 1px;
    color: var(--txn-blue);
}

.txn-schedule-note p {
    margin: 0;
    color: #2b4a6b;
    font-size: 12.5px;
    line-height: 1.65;
}

.txn-schedule-note strong {
    color: #1c3d5e;
    font-weight: 750;
}

.txn-schedule-grid {
    display: grid;
    padding: 0 28px;
}

.txn-schedule-head,
.txn-schedule-row {
    display: grid;
    grid-template-columns:
        minmax(140px, 1.05fr)
        minmax(430px, 3.1fr)
        minmax(120px, .85fr)
        minmax(120px, .85fr)
        minmax(78px, .5fr);
    gap: 16px;
    align-items: center;
}

.txn-schedule-head {
    padding: 0 0 12px;
    color: var(--text-muted);
    font-size: 12px;
    font-weight: 600;
}

.txn-schedule-head > span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.txn-head-hint {
    flex-shrink: 0;
    color: var(--text-soft);
}

.txn-head-action {
    justify-content: center;
}

.txn-schedule-row {
    padding: 13px 0;
    border-top: 1px solid var(--txn-line);
}

.txn-day {
    display: grid;
    gap: 4px;
    min-width: 0;
}

.txn-day strong {
    color: var(--heading);
    font-size: 14.5px;
    font-weight: 700;
}

.txn-day-status {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    color: var(--text-muted);
    font-size: 11.5px;
}

.txn-day-status > i {
    width: 7px;
    height: 7px;
    flex-shrink: 0;
    border-radius: 50%;
    background: var(--txn-open);
}

.txn-schedule-row.is-closed .txn-day-status > i {
    background: var(--txn-closed);
}

.txn-availability {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
    min-width: 0;
}

.txn-check {
    display: flex !important;
    align-items: center;
    gap: 9px !important;
    min-height: 44px;
    margin: 0 !important;
    padding: 8px 11px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--surface-elevated);
    color: var(--heading) !important;
    font-size: 12px !important;
    font-weight: 600;
    line-height: 1.25;
    cursor: pointer;
    transition: border-color var(--motion-fast) ease, background-color var(--motion-fast) ease;
}

.txn-check:hover {
    border-color: #a9c9ea;
}

.txn-check input[type="checkbox"] {
    width: 17px;
    height: 17px;
    min-height: 17px;
    flex-shrink: 0;
    margin: 0;
    accent-color: var(--txn-blue);
    cursor: pointer;
}

.txn-time-field {
    position: relative;
    display: block;
    min-width: 0;
}

.txn-time-field input {
    width: 100%;
    min-height: 44px;
    padding: 10px 34px 10px 12px;
    border-radius: 8px;
    font-size: 13px;
}

.txn-time-field > .ui-icon {
    position: absolute;
    top: 50%;
    right: 11px;
    color: var(--text-muted);
    pointer-events: none;
    transform: translateY(-50%);
}

/* Keep the native picker clickable while our own clock icon is the visible one. */
.txn-time-field input::-webkit-calendar-picker-indicator {
    position: absolute;
    top: 0;
    right: 0;
    width: 34px;
    height: 100%;
    margin: 0;
    padding: 0;
    opacity: 0;
    cursor: pointer;
}

.txn-row-action {
    display: flex;
    justify-content: center;
    min-width: 0;
}

.txn-schedule-card .button.txn-row-save {
    width: 100%;
    min-height: 40px;
    padding: 9px 12px;
    border-color: #bcd8f5;
    color: var(--txn-blue);
    font-size: 12.5px;
    font-weight: 700;
}

.txn-schedule-card .button.txn-row-save:hover,
.txn-schedule-card .button.txn-row-save:focus-visible {
    border-color: var(--txn-blue);
    background: #eff6fe;
}

.txn-row-error {
    grid-column: 1 / -1;
    margin: 2px 0 0;
    color: var(--danger);
    font-size: 11.5px;
    font-weight: 600;
}

.txn-schedule-row.is-closed .txn-availability,
.txn-schedule-row.is-closed .txn-time-field {
    opacity: .72;
}

.txn-schedule-footnote {
    display: grid;
    grid-template-columns: 34px minmax(0, 1fr);
    gap: 14px;
    align-items: start;
    margin: 0;
    padding: 20px 28px 22px;
    border-top: 1px solid var(--txn-line);
    color: var(--text-muted);
    font-size: 12px;
    line-height: 1.65;
}

.txn-schedule-footnote-icon {
    display: grid;
    width: 34px;
    height: 34px;
    place-items: center;
    color: #b78a12;
    background: #fdf4dd;
    border-radius: 50%;
}

.txn-schedule-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
    padding: 18px 28px 24px;
    border-top: 1px solid var(--txn-line);
    background: var(--surface-subtle);
}

.txn-schedule-actions .button {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    min-height: 44px;
    padding: 11px 20px;
    font-size: 13px;
    font-weight: 700;
}

html[data-theme="dark"] .txn-schedule-card {
    --txn-line: var(--row-border);
}

html[data-theme="dark"] .txn-schedule-header-icon {
    color: var(--interactive);
    background: rgba(114, 183, 244, .14);
}

html[data-theme="dark"] .txn-schedule-note {
    border-color: var(--info-border);
    background: var(--info-bg);
}

html[data-theme="dark"] .txn-schedule-note p,
html[data-theme="dark"] .txn-schedule-note strong {
    color: var(--text-secondary);
}

html[data-theme="dark"] .txn-schedule-note > .ui-icon {
    color: var(--interactive);
}

html[data-theme="dark"] .txn-schedule-card .button.txn-row-save {
    border-color: var(--border-strong);
    color: var(--interactive);
}

html[data-theme="dark"] .txn-schedule-card .button.txn-row-save:hover,
html[data-theme="dark"] .txn-schedule-card .button.txn-row-save:focus-visible {
    border-color: var(--interactive);
    background: var(--surface-hover);
}

html[data-theme="dark"] .txn-schedule-footnote-icon {
    color: var(--warning);
    background: var(--warning-bg);
}

@media (max-width: 1240px) {
    .txn-schedule-head {
        display: none;
    }

    .txn-schedule-row {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) 96px;
        row-gap: 12px;
        padding: 16px 0;
    }

    .txn-day,
    .txn-availability {
        grid-column: 1 / -1;
    }

    .txn-availability {
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    }
}

@media (max-width: 700px) {
    .txn-schedule-header,
    .txn-schedule-grid,
    .txn-schedule-footnote,
    .txn-schedule-actions {
        padding-inline: 18px;
    }

    .txn-schedule-note {
        margin-inline: 18px;
    }

    .txn-schedule-row {
        grid-template-columns: minmax(0, 1fr);
    }

    .txn-schedule-actions {
        flex-direction: column-reverse;
        align-items: stretch;
    }

    .txn-schedule-actions .button {
        justify-content: center;
    }
}
</style>

<script>
(() => {
    const form = document.querySelector('[data-weekly-schedule-form]');

    if (!form) {
        return;
    }

    const onlyDay = form.querySelector('[data-weekly-only-day]');
    const rows = Array.from(form.querySelectorAll('[data-weekday-row]'));

    const applyOnlyDay = (submitter) => {
        if (onlyDay) {
            onlyDay.value = submitter?.dataset?.weekdaySave ?? '';
        }
    };

    const syncRow = (row) => {
        const open = row.querySelector('[data-weekday-open]');

        if (!open) {
            return;
        }

        const status = row.querySelector('[data-weekday-status]');

        row.classList.toggle('is-closed', !open.checked);

        if (status) {
            status.textContent = open.checked
                ? 'Operational day'
                : 'Closed day';
        }
    };

    /*
     * Closing a day clears its capabilities and hours server-side, so the grid
     * mirrors that immediately instead of showing values that will be dropped.
     */
    const clearRow = (row) => {
        row.querySelectorAll('[data-weekday-capability]').forEach((input) => {
            input.checked = false;
        });

        row.querySelectorAll('[data-weekday-time]').forEach((input) => {
            input.value = '';
        });
    };

    rows.forEach((row) => {
        const open = row.querySelector('[data-weekday-open]');

        open?.addEventListener('change', () => {
            if (!open.checked) {
                clearRow(row);
            }

            syncRow(row);
        });

        row.querySelector('[data-weekday-save]')?.addEventListener('click', (event) => {
            applyOnlyDay(event.currentTarget);
        });
    });

    form.querySelector('[data-weekly-save-all]')?.addEventListener('click', () => {
        applyOnlyDay(null);
    });

    /*
     * Pressing Enter inside a field submits through the first Save button, so
     * the real submitter decides the scope rather than whichever button was
     * last clicked.
     */
    form.addEventListener('submit', (event) => {
        if (event.submitter) {
            applyOnlyDay(event.submitter);
        }
    });

    form.addEventListener('reset', () => {
        window.setTimeout(() => {
            applyOnlyDay(null);
            rows.forEach(syncRow);
        }, 0);
    });

    rows.forEach(syncRow);
})();
</script>
