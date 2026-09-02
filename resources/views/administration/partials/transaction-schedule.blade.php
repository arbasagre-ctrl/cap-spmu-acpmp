{{--
    Weekly Transaction Schedule.

    All seven weekdays post through one form, so the SPMU Head reviews the whole
    week and commits it with Save Changes. Reset Changes is a native form reset
    back to the last saved values.
--}}
<section class="content-area" id="transaction-schedule">
    <form
        method="post"
        action="{{ route('policies.weekly-schedule.batch-update') }}"
        class="txn-schedule-form"
        data-weekly-schedule-form
    >
        @csrf
        @method('PUT')

        <article class="card txn-schedule-card">
            <div class="txn-schedule-grid">
                <div class="txn-schedule-head">
                    <span>Day</span>
                    <span>Office State</span>
                    <span>Allowed Transactions</span>
                    <span title="Optional. Leave both times blank for a date-based policy.">Open Time</span>
                    <span title="Must be later than the opening time.">Close Time</span>
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

                        <label class="txn-state-toggle" data-weekday-state>
                            <input type="hidden" name="schedule[{{ $weekday }}][is_open]" value="0">
                            <input
                                type="checkbox"
                                name="schedule[{{ $weekday }}][is_open]"
                                value="1"
                                class="visually-hidden"
                                data-weekday-open
                                @checked($isOpen)
                            >
                            <span class="txn-state-track" aria-hidden="true"><i></i></span>
                            <span class="txn-state-pill" data-weekday-state-label>{{ $isOpen ? 'OPEN' : 'CLOSED' }}</span>
                        </label>

                        <div class="txn-availability">
                            <span class="txn-availability-title">Allowed Transactions</span>

                            <label class="txn-check">
                                <input type="hidden" name="schedule[{{ $weekday }}][accepts_requests]" value="0">
                                <input
                                    type="checkbox"
                                    name="schedule[{{ $weekday }}][accepts_requests]"
                                    value="1"
                                    data-weekday-capability
                                    @checked($isOpen && $acceptsRequests)
                                    @disabled(!$isOpen)
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
                                    @checked($isOpen && $allowsPickup)
                                    @disabled(!$isOpen)
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
                                    @checked($isOpen && $allowsReturn)
                                    @disabled(!$isOpen)
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
                                value="{{ $isOpen ? $openTime : '' }}"
                                data-weekday-time
                                @disabled(!$isOpen)
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
                                value="{{ $isOpen ? $closeTime : '' }}"
                                data-weekday-time
                                @disabled(!$isOpen)
                                @error("schedule.{$weekday}.close_time") aria-invalid="true" @enderror
                            >
                            <x-icon name="clock" size="16" />
                        </span>

                        @error("schedule.{$weekday}.close_time")
                            <p class="txn-row-error">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach
            </div>
        </article>

        <div class="txn-schedule-toolbar">
            <button class="button secondary ui-pressable" type="reset" data-weekly-reset>
                <x-icon name="cycle" size="17" />
                Reset Changes
            </button>

            <button class="button primary ui-pressable" type="submit" data-weekly-save-all>
                <x-icon name="save" size="17" />
                Save Changes
            </button>
        </div>
    </form>
</section>

<style>
.txn-schedule-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 14px;
}

.txn-schedule-toolbar .button {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    min-height: 44px;
    padding: 11px 20px;
    font-size: 13px;
    font-weight: 700;
    white-space: nowrap;
}

.txn-schedule-card {
    --txn-line: #e6ecf3;
    --txn-blue: #1a6fd4;
    --txn-open: #22a06b;
    --txn-closed: #98a4b3;

    padding: 0;
}

.txn-schedule-grid {
    display: grid;
    padding: 24px 28px 26px;
}

/*
 * Minimums total 732px, plus 4 x 14px gaps and the grid's 56px side padding =
 * 844px of card width. The stacked layout below takes over before that runs out.
 */
.txn-schedule-head,
.txn-schedule-row {
    display: grid;
    grid-template-columns:
        minmax(112px, .95fr)
        minmax(100px, .62fr)
        minmax(300px, 2.6fr)
        minmax(110px, .8fr)
        minmax(110px, .8fr);
    gap: 14px;
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
    grid-template-columns: repeat(3, minmax(0, 1fr));
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

.txn-row-error {
    grid-column: 1 / -1;
    margin: 2px 0 0;
    color: var(--danger);
    font-size: 11.5px;
    font-weight: 600;
}

/*
 * The caption repeats the column header, so it only appears once the header
 * row is dropped on narrow screens.
 */
.txn-availability-title {
    display: none;
    grid-column: 1 / -1;
    margin-bottom: 2px;
    color: var(--text-muted);
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .06em;
    text-transform: uppercase;
}

.txn-state-toggle {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    margin: 0;
    cursor: pointer;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .04em;
}

.txn-state-track {
    position: relative;
    display: inline-block;
    width: 42px;
    height: 23px;
    flex-shrink: 0;
    border-radius: 999px;
    background: var(--txn-closed, #94a3b8);
    transition: background-color var(--motion) ease;
}

.txn-state-track > i {
    position: absolute;
    top: 3px;
    left: 3px;
    width: 17px;
    height: 17px;
    border-radius: 50%;
    background: #fff;
    transition: transform var(--motion) ease;
}

.txn-state-toggle input:checked + .txn-state-track {
    background: var(--txn-open, #16a34a);
}

.txn-state-toggle input:checked + .txn-state-track > i {
    transform: translateX(19px);
}

.txn-state-toggle input:focus-visible + .txn-state-track {
    box-shadow: var(--focus-ring);
}

.txn-state-pill {
    min-width: 62px;
    padding: 4px 9px;
    border-radius: 999px;
    background: var(--surface-muted);
    color: var(--text-muted);
    font-size: 10.5px;
    text-align: center;
    transition: color var(--motion-fast) ease, background-color var(--motion-fast) ease;
}

.txn-schedule-row:not(.is-closed) .txn-state-pill {
    background: var(--success-bg);
    color: var(--success);
}

/* A closed day's permissions and hours are locked, not merely dimmed. */
.txn-schedule-row.is-closed .txn-check,
.txn-schedule-row.is-closed .txn-time-field input {
    color: var(--text-soft) !important;
    background: var(--surface-subtle);
    cursor: not-allowed;
}

.txn-schedule-row.is-closed .txn-check {
    border-color: var(--border);
}

.txn-schedule-row.is-closed .txn-check:hover {
    border-color: var(--border);
}

.txn-schedule-row.is-closed .txn-check input[type="checkbox"],
.txn-schedule-row.is-closed .txn-time-field input,
.txn-schedule-row.is-closed .txn-time-field input::-webkit-calendar-picker-indicator {
    cursor: not-allowed;
}

.txn-schedule-row.is-closed .txn-time-field > .ui-icon {
    color: var(--text-soft);
}

@media (prefers-reduced-motion: reduce) {
    .txn-state-track, .txn-state-track > i { transition: none; }
}

html[data-theme="dark"] .txn-schedule-card {
    --txn-line: var(--row-border);
}

@media (max-width: 1160px) {
    .txn-schedule-head {
        display: none;
    }

    .txn-availability-title {
        display: block;
    }

    .txn-schedule-row {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        row-gap: 12px;
        padding: 16px 0;
    }

    .txn-day,
    .txn-state-toggle,
    .txn-availability {
        grid-column: 1 / -1;
    }

    .txn-availability {
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    }
}

@media (max-width: 700px) {
    .txn-schedule-grid {
        padding-inline: 18px;
    }

    .txn-schedule-row {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>

<script>
(() => {
    const form = document.querySelector('[data-weekly-schedule-form]');

    if (!form) {
        return;
    }

    const rows = Array.from(form.querySelectorAll('[data-weekday-row]'));

    const lockedFields = (row) => [
        ...row.querySelectorAll('[data-weekday-capability]'),
        ...row.querySelectorAll('[data-weekday-time]'),
    ];

    const syncRow = (row) => {
        const open = row.querySelector('[data-weekday-open]');

        if (!open) {
            return;
        }

        const status = row.querySelector('[data-weekday-status]');
        const stateLabel = row.querySelector('[data-weekday-state-label]');

        row.classList.toggle('is-closed', !open.checked);

        if (status) {
            status.textContent = open.checked
                ? 'Operational day'
                : 'Closed day';
        }

        if (stateLabel) {
            stateLabel.textContent = open.checked ? 'OPEN' : 'CLOSED';
        }

        /*
         * A closed day accepts no transaction and keeps no hours, so its
         * controls are locked. The paired hidden inputs stay enabled, which is
         * what posts the withdrawn permissions as 0.
         */
        lockedFields(row).forEach((field) => {
            field.disabled = !open.checked;
        });
    };

    /* Closing a day withdraws what it had allowed, matching what is stored. */
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
    });

    /*
     * The native reset restores the server-rendered values; re-syncing on the
     * next tick restores what those values imply — the closed styling, the
     * status text, the OPEN/CLOSED pill and the locked controls.
     */
    form.addEventListener('reset', () => {
        window.setTimeout(() => rows.forEach(syncRow), 0);
    });

    rows.forEach(syncRow);
})();
</script>
