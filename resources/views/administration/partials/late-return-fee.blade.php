{{--
    Late Return Fee detail view.

    Expects $setting — the `daily_overdue_tariff` SystemSetting row.
--}}
@php
    $value = $setting->value_json;

    $valueText = $value === null
        ? 'Not configured'
        : (is_bool($value) ? ($value ? 'Enabled' : 'Disabled') : (string) $value);

    $dataType = strtoupper((string) ($setting->data_type ?: 'TEXT'));
@endphp

<section class="content-area late-fee-page">
    <article class="card late-fee-identity">
        <span class="late-fee-identity-icon" aria-hidden="true">
            <x-icon name="banknote" size="24" />
        </span>

        <div class="late-fee-identity-copy">
            <span class="badge">{{ $setting->group_code }}</span>
            <h2>Late Return Daily Fee</h2>
            <small class="setting-key">{{ strtoupper($setting->setting_key) }}</small>
        </div>

        <x-status-badge :status="$setting->status ?: 'NOT_CONFIGURED'" />
    </article>

    <form
        id="setting-{{ $setting->setting_key }}"
        method="post"
        action="{{ route('administration.settings.update', $setting) }}"
        class="card late-fee-form"
        data-settings-form
    >
        @csrf
        @method('PUT')

        <div class="late-fee-current">
            <span>Current value</span>
            <strong>{{ $valueText }}</strong>
        </div>

        <div class="late-fee-body">
            @if(filled($setting->description))
                <div class="late-fee-note">
                    <x-icon name="information" size="20" />

                    <p>
                        <strong>{{ $setting->description }}</strong>
                        Set the daily late return fee to be used for all late or overdue returns.
                    </p>
                </div>
            @endif

            <label class="late-fee-field">
                Value
                <input
                    type="number"
                    name="value"
                    value="{{ $value === null ? '' : $value }}"
                    step="{{ $dataType === 'MONEY' ? '0.01' : '1' }}"
                    min="0"
                    inputmode="decimal"
                    placeholder="Not configured"
                >
                <small>Enter the daily fee amount (e.g., 15.00) that will be charged per item per day.</small>
            </label>

            <label class="late-fee-field">
                Reason for change <span class="late-fee-optional">(optional)</span>
                <textarea
                    name="reason"
                    maxlength="1000"
                    placeholder="Describe the update reason..."
                ></textarea>
                <small>Provide context for this change. This will be logged in the system.</small>
            </label>

            <div class="late-fee-actions">
                <button class="button primary ui-pressable" type="submit" data-save-button disabled>
                    <x-icon name="save" size="17" />
                    Save change
                </button>

                <a class="button secondary ui-pressable" href="{{ route('policies.index') }}">Back</a>
            </div>
        </div>
    </form>

    <aside class="late-fee-about">
        <span class="late-fee-about-icon" aria-hidden="true">
            <x-icon name="information" size="20" />
        </span>

        <div>
            <h3>About Late Return Fee</h3>
            <p>
                This fee is applied per overdue item per day starting from the effective return deadline until the item is returned. The rate
                set here will be used across all borrowing transactions.
            </p>
        </div>

        <a
            class="late-fee-learn-more"
            href="{{ route('policies.index', ['section' => 'transaction-schedule']) }}"
            target="_blank"
            rel="noopener"
        >
            <x-icon name="external-link" size="15" />
            Learn more
        </a>
    </aside>
</section>

<style>
.late-fee-page {
    --late-fee-line: #e6ecf3;
    --late-fee-blue: #1a6fd4;

    display: grid;
    gap: 18px;
}

.late-fee-identity {
    display: grid;
    grid-template-columns: 56px minmax(0, 1fr) auto;
    gap: 20px;
    align-items: center;
    padding: 22px 26px;
}

.late-fee-identity-icon {
    display: grid;
    width: 56px;
    height: 56px;
    place-items: center;
    color: var(--late-fee-blue);
    background: #e8f1fd;
    border-radius: 50%;
}

.late-fee-identity-copy {
    display: grid;
    gap: 6px;
    justify-items: start;
    min-width: 0;
}

.late-fee-identity-copy h2 {
    margin: 0;
    color: var(--heading);
    font-size: 20px;
    font-weight: 750;
}

.late-fee-identity-copy .setting-key {
    color: var(--text-muted);
    font-size: 12px;
    letter-spacing: .02em;
}

.late-fee-form {
    padding: 0;
    overflow: hidden;
}

.late-fee-current {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 18px 26px;
    border-bottom: 1px solid var(--late-fee-line);
}

.late-fee-current > span {
    color: var(--text-muted);
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
}

.late-fee-current > strong {
    color: var(--heading);
    font-size: 14px;
    font-weight: 750;
}

.late-fee-body {
    display: grid;
    gap: 20px;
    padding: 22px 26px 26px;
}

.late-fee-note {
    display: grid;
    grid-template-columns: 20px minmax(0, 1fr);
    gap: 14px;
    align-items: start;
    padding: 15px 17px;
    border: 1px solid #c5ddf6;
    border-radius: 10px;
    background: #edf5fd;
}

.late-fee-note > .ui-icon {
    margin-top: 1px;
    color: var(--late-fee-blue);
}

.late-fee-note p {
    margin: 0;
    color: #2b4a6b;
    font-size: 12.5px;
    line-height: 1.6;
}

.late-fee-note strong {
    display: block;
    margin-bottom: 3px;
    color: #12314f;
    font-size: 13.5px;
    font-weight: 750;
}

.late-fee-page label.late-fee-field {
    display: grid;
    gap: 9px;
    margin: 0;
    color: var(--heading);
    font-size: 13px;
    font-weight: 700;
}

.late-fee-optional {
    color: var(--text-muted);
    font-weight: 600;
}

.late-fee-field input,
.late-fee-field textarea {
    min-height: 48px;
    padding: 12px 15px;
    border-radius: 9px;
    font-size: 13.5px;
}

.late-fee-field textarea {
    min-height: 108px;
}

.late-fee-field small {
    color: var(--text-muted);
    font-size: 11.5px;
    font-weight: 500;
    line-height: 1.5;
}

.late-fee-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 2px;
}

.late-fee-actions .button {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    min-height: 46px;
    padding: 12px 22px;
    font-size: 13.5px;
    font-weight: 700;
}

.late-fee-about {
    display: grid;
    grid-template-columns: 40px minmax(0, 1fr) auto;
    gap: 16px;
    align-items: start;
    padding: 20px 24px;
    border: 1px solid #d6e6f7;
    border-radius: var(--radius);
    background: #f2f8fe;
}

.late-fee-about-icon {
    display: grid;
    width: 40px;
    height: 40px;
    place-items: center;
    color: var(--late-fee-blue);
    background: #e2eefb;
    border-radius: 50%;
}

.late-fee-about h3 {
    margin: 4px 0 6px;
    color: var(--heading);
    font-size: 15px;
    font-weight: 750;
}

.late-fee-about p {
    max-width: 860px;
    margin: 0;
    color: var(--text-secondary);
    font-size: 12.5px;
    line-height: 1.65;
}

.late-fee-learn-more {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-top: 4px;
    color: var(--late-fee-blue);
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    white-space: nowrap;
}

.late-fee-learn-more:hover,
.late-fee-learn-more:focus-visible {
    text-decoration: underline;
}

html[data-theme="dark"] .late-fee-page {
    --late-fee-line: var(--row-border);
    --late-fee-blue: var(--interactive);
}

html[data-theme="dark"] .late-fee-identity-icon,
html[data-theme="dark"] .late-fee-about-icon {
    background: rgba(114, 183, 244, .14);
}

html[data-theme="dark"] .late-fee-note,
html[data-theme="dark"] .late-fee-about {
    border-color: var(--info-border);
    background: var(--info-bg);
}

html[data-theme="dark"] .late-fee-note p,
html[data-theme="dark"] .late-fee-note strong {
    color: var(--text-secondary);
}

@media (max-width: 780px) {
    .late-fee-identity {
        grid-template-columns: 56px minmax(0, 1fr);
        row-gap: 14px;
    }

    .late-fee-identity > .status-badge {
        grid-column: 2;
        justify-self: start;
    }

    .late-fee-about {
        grid-template-columns: 40px minmax(0, 1fr);
    }

    .late-fee-learn-more {
        grid-column: 2;
    }

    .late-fee-actions .button {
        flex: 1 1 auto;
        justify-content: center;
    }
}
</style>
