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
    <form
        id="setting-{{ $setting->setting_key }}"
        method="post"
        action="{{ route('administration.settings.update', $setting) }}"
        class="card late-fee-form"
        data-settings-form
    >
        @csrf
        @method('PUT')

        <div class="late-fee-form-header">
            <div>
                <span class="badge">{{ $setting->group_code }}</span>
                <h2>Late Return Daily Fee</h2>
                <p>Applied per calendar day late after the effective return deadline.</p>
            </div>
            <div class="late-fee-current-inline">
                <span>Current</span>
                <strong>{{ $valueText }}</strong>
            </div>
        </div>

        <div class="late-fee-body">

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
                <small>Amount charged per calendar day late. The effective due date already follows the Operational Calendar.</small>
            </label>

            <label class="late-fee-field">
                Reason for change <span class="late-fee-optional">(optional)</span>
                <textarea
                    name="reason"
                    rows="3"
                    maxlength="1000"
                    placeholder="Optional note for the audit trail"
                ></textarea>
                <small>Saved with the configuration change for audit reference.</small>
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

    <p class="late-fee-footnote">
        <x-icon name="information" size="16" />
        <span>Existing late-return cases keep the tariff captured when they were created; later policy changes affect new cases only.</span>
    </p>
</section>

<style>
.late-fee-page {
    display: grid;
    gap: 12px;
}

.late-fee-form {
    padding: 0;
    overflow: hidden;
}

.late-fee-form-header {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
    padding:18px 22px;
    border-bottom:1px solid var(--border);
}

.late-fee-form-header > div:first-child {
    display:grid;
    gap:4px;
}

.late-fee-form-header h2 {
    margin:0;
    color:var(--heading);
    font-size:18px;
    font-weight:750;
}

.late-fee-form-header p {
    margin:0;
    color:var(--text-muted);
    font-size:12px;
    line-height:1.45;
}

.late-fee-current-inline {
    display:grid;
    justify-items:end;
    gap:2px;
    flex:0 0 auto;
}

.late-fee-current-inline span {
    color:var(--text-muted);
    font-size:10.5px;
    font-weight:750;
    letter-spacing:.05em;
    text-transform:uppercase;
}

.late-fee-current-inline strong {
    color:var(--heading);
    font-size:16px;
}

.late-fee-body {
    display:grid;
    gap:16px;
    padding:20px 22px 22px;
}

.late-fee-page label.late-fee-field {
    display:grid;
    gap:7px;
    margin:0;
    color:var(--heading);
    font-size:12.5px;
    font-weight:700;
}

.late-fee-optional {
    color:var(--text-muted);
    font-weight:600;
}

.late-fee-field input,
.late-fee-field textarea {
    min-height:44px;
    padding:11px 13px;
    border-radius:8px;
    font-size:13.5px;
}

.late-fee-field textarea {
    min-height:78px;
}

.late-fee-field small {
    color:var(--text-muted);
    font-size:11.5px;
    font-weight:500;
    line-height:1.45;
}

.late-fee-actions {
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    margin-top:0;
}

.late-fee-actions .button {
    display:inline-flex;
    align-items:center;
    gap:8px;
    min-height:42px;
    padding:9px 18px;
    font-size:13px;
    font-weight:700;
}

.late-fee-footnote {
    display:flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    margin:0;
    color:var(--text-muted);
    font-size:11.5px;
    line-height:1.45;
    text-align:center;
}

.late-fee-footnote > .ui-icon {
    flex:0 0 auto;
}

@media (max-width: 700px) {
    .late-fee-form-header {
        align-items:flex-start;
        flex-direction:column;
    }

    .late-fee-current-inline {
        justify-items:start;
    }

    .late-fee-actions .button {
        flex:1 1 auto;
        justify-content:center;
    }
}
</style>
