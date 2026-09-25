@extends('layouts.app', ['title' => $isIctu ? 'System Configuration' : 'Operational Configuration'])
@section('content')
@php
    $humanizedKey = fn ($key) => ucwords(str_replace('_', ' ', (string) $key));

    $configurationSection = request()->query('section');
    $allowedConfigurationSections = [
        'late-return-fee',
    ];

    if (! in_array($configurationSection, $allowedConfigurationSections, true)) {
        $configurationSection = null;
    }

    $sectionMeta = [
        'late-return-fee' => ['Financial assessment', 'Late Return Fee', 'Define the daily late return rate and manage how the fee is applied.'],
    ];

    $activeSectionMeta = $configurationSection ? $sectionMeta[$configurationSection] : null;

    $lateReturnFeeSetting = $settings->firstWhere('setting_key', 'daily_overdue_tariff');

    $ictuSettings = $isIctu ? $settings->keyBy('setting_key') : collect();

    $googleConfigured = $isIctu
        && filled(config('services.google.client_id'))
        && filled(config('services.google.client_secret'))
        && filled(config('services.google.redirect'));

    $googleAllowedDomains = $isIctu
        ? trim((string) config('services.google.allowed_domains'))
        : '';

    $mailTransport = $isIctu
        ? trim((string) config('mail.default'))
        : '';

    $emailConfigured = $isIctu && $mailTransport !== '';

    $maxUploadSetting = $isIctu
        ? $ictuSettings->get('max_upload_mb')
        : null;

    $backupScheduleSetting = $isIctu
        ? $ictuSettings->get('backup_schedule')
        : null;
@endphp

<section class="page-heading settings-detail-heading">
    <div>
        @if($isIctu)
            <p class="eyebrow">ICTU technical administration</p>
            <h1>System Configuration</h1>
            <p>Manage technical services, security limits, and maintenance settings. SPMU business policies and controlled forms are managed by SPMU Admin/Head.</p>
        @else
            <p class="eyebrow">{{ $activeSectionMeta[0] ?? 'Effective operational configuration' }}</p>
            <h1>{{ $activeSectionMeta[1] ?? 'Operational Configuration' }}</h1>
            <p>{{ $activeSectionMeta[2] ?? 'Manage approved policy values. Every change is preserved in the audit trail.' }}</p>
        @endif
    </div>

    @if(!$isIctu && $configurationSection)
        <a class="button secondary ui-pressable config-back-button" href="{{ route('policies.index') }}">
            <x-icon name="arrow-left" size="17" />
            Back
        </a>
    @endif
</section>

@if(session('status'))
<section class="content-area">
    <div class="callout success">{{ session('status') }}</div>
</section>
@endif

@if($isIctu)
<section class="content-area ictu-system-config-area">
    <div class="ictu-config-section-heading">
        <div>
            <p class="eyebrow">Service status</p>
            <h2>Technical services</h2>
            <p>Configuration status is shown without exposing passwords, API tokens, or client secrets.</p>
        </div>
    </div>

    <div class="ictu-service-status-grid">
        <article class="card ictu-service-status-card">
            <div class="ictu-service-card-heading">
                <div>
                    <span class="badge">AUTHENTICATION</span>
                    <h3>Google Sign-In</h3>
                </div>
                <span class="ictu-config-state {{ $googleConfigured ? 'is-ready' : 'is-pending' }}">
                    {{ $googleConfigured ? 'Configured' : 'Not configured' }}
                </span>
            </div>
            <p>Identity provider used for institutional account sign-in.</p>
            <div class="ictu-readonly-value">
                <span>Allowed domain</span>
                <strong>{{ $googleAllowedDomains !== '' ? $googleAllowedDomains : 'No Google domain restriction configured' }}</strong>
            </div>
        </article>

        <article class="card ictu-service-status-card">
            <div class="ictu-service-card-heading">
                <div>
                    <span class="badge">EMAIL</span>
                    <h3>Email Delivery</h3>
                </div>
                <span class="ictu-config-state {{ $emailConfigured ? 'is-ready' : 'is-pending' }}">
                    {{ $emailConfigured ? 'Configured' : 'Not configured' }}
                </span>
            </div>
            <p>Application mail transport used for institutional email notifications.</p>
            <div class="ictu-readonly-value">
                <span>Mail transport</span>
                <strong>{{ $mailTransport !== '' ? strtoupper($mailTransport) : 'Not configured' }}</strong>
            </div>
        </article>
    </div>
</section>

<section class="content-area ictu-system-config-area">
    <div class="ictu-config-section-heading">
        <div>
            <p class="eyebrow">Technical configuration</p>
            <h2>System-managed settings</h2>
            <p>Only technical values owned by ICTU are editable here. Every saved change is recorded in the audit trail.</p>
        </div>
    </div>

    <div class="ictu-technical-settings-grid">
        @if($maxUploadSetting)
            <form
                id="setting-{{ $maxUploadSetting->setting_key }}"
                method="post"
                action="{{ route('administration.settings.update', $maxUploadSetting) }}"
                class="card form-grid ictu-technical-setting-card"
                data-settings-form
            >
                @csrf
                @method('PUT')

                <div class="ictu-setting-card-heading">
                    <div>
                        <span class="badge">FILE &amp; SECURITY</span>
                        <h3>Maximum Upload Size</h3>
                    </div>
                    <span class="ictu-config-state is-ready">Active</span>
                </div>

                <p>Maximum protected upload size used for supporting documents, evidence, and E-signature files.</p>

                <label>
                    Maximum size (MB)
                    <input
                        type="number"
                        name="value"
                        min="1"
                        step="1"
                        value="{{ $maxUploadSetting->value_json ?? '' }}"
                        placeholder="e.g. 10"
                    >
                </label>

                <label>
                    Reason for change
                    <textarea name="reason" required maxlength="1000" placeholder="Describe the configuration change..."></textarea>
                </label>

                <div class="settings-actions">
                    <button class="button primary ui-pressable" data-save-button type="submit" disabled>
                        Save change
                    </button>
                </div>
            </form>
        @endif

        @if($backupScheduleSetting)
            <form
                id="setting-{{ $backupScheduleSetting->setting_key }}"
                method="post"
                action="{{ route('administration.settings.update', $backupScheduleSetting) }}"
                class="card form-grid ictu-technical-setting-card"
                data-settings-form
            >
                @csrf
                @method('PUT')

                @php
                    $backupValue = $backupScheduleSetting->value_json;
                    $backupFinalized = filled($backupValue)
                        && strtoupper((string) $backupValue) !== 'NOT_FINALIZED';
                @endphp

                <div class="ictu-setting-card-heading">
                    <div>
                        <span class="badge">BACKUP &amp; MAINTENANCE</span>
                        <h3>Backup Schedule</h3>
                    </div>
                    <span class="ictu-config-state {{ $backupFinalized ? 'is-ready' : 'is-pending' }}">
                        {{ $backupFinalized ? 'Recorded' : 'Not finalized' }}
                    </span>
                </div>

                <p>Record the approved production backup schedule. This value documents the schedule; infrastructure credentials are never stored here.</p>

                <label>
                    Approved schedule
                    <input
                        type="text"
                        name="value"
                        value="{{ $backupValue ?? '' }}"
                        placeholder="e.g. Daily at 2:00 AM"
                        maxlength="2000"
                    >
                </label>

                <label>
                    Reason for change
                    <textarea name="reason" required maxlength="1000" placeholder="Describe the configuration change..."></textarea>
                </label>

                <div class="settings-actions">
                    <button class="button primary ui-pressable" data-save-button type="submit" disabled>
                        Save change
                    </button>
                </div>
            </form>
        @endif
    </div>

    <div class="callout compact ictu-config-boundary-note">
        <strong>SPMU-controlled settings are intentionally excluded.</strong>
        <span>Return rules, late fees, workflow deadlines, and sanctions remain under SPMU Admin/Head.</span>
    </div>
</section>
@else

@if(!$configurationSection)
<section class="content-area">
    <div class="card return-policy-card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Return deadline policy</p>
                <h2>Date-based return rule</h2>
            </div>
            <span class="badge">POLICY</span>
        </div>
        <div class="return-policy-grid">
            <div><span>Expected Return Date</span><strong>Return anytime on that calendar date</strong></div>
            <div><span>Reminder</span><strong>1 day before + on the due date</strong></div>
            <div><span>Overdue begins</span><strong>After the effective return date if items remain outstanding</strong></div>
            <div><span>Grace period</span><strong>None</strong></div>
        </div>
        <p class="meta">The Expected Return Date remains the audit date. If that date is closed through the Operational Calendar, the effective return deadline automatically moves to the next open SPMU return day. Late assessment starts only after that effective deadline.</p>
    </div>
</section>
@endif

@if($configurationSection === 'late-return-fee')
    @if($lateReturnFeeSetting)
        @include('administration.partials.late-return-fee', ['setting' => $lateReturnFeeSetting])
    @else
        <section class="content-area">
            <div class="callout">The <code>daily_overdue_tariff</code> setting is missing. Run the system setting seeder to restore it.</div>
        </section>
    @endif
@endif

@if(!$configurationSection)
<section class="content-area">
    <div class="section-heading">
        <div>
            <p class="eyebrow">Other configuration</p>
            <h2>Policy and system settings</h2>
        </div>
    </div>

    <div class="settings-grid admin-settings-grid">
        @foreach($settings as $setting)
            @php
                $dataType = strtoupper((string) ($setting->data_type ?: 'TEXT'));
                $value = $setting->value_json;
                $valueText = $value === null ? 'Not configured' : (
                    is_bool($value) ? ($value ? 'Enabled' : 'Disabled') : (string) $value
                );
                $displayKey = match ($setting->setting_key) {
                    'daily_overdue_tariff' => 'Late Return Daily Fee',
                    default => $humanizedKey($setting->setting_key),
                };
            @endphp

            <form id="setting-{{ $setting->setting_key }}" method="post" action="{{ route('administration.settings.update', $setting) }}" class="card form-grid settings-form" data-settings-form>
                @csrf
                @method('PUT')

                <div class="card-header settings-card-header">
                    <div>
                        <span class="badge">{{ $setting->group_code }}</span>
                        <h3>{{ $displayKey }}</h3>
                        <small class="setting-key">{{ $setting->setting_key }}</small>
                    </div>
                    <x-status-badge :status="$setting->status ?: 'NOT_CONFIGURED'" />
                </div>

                <div class="settings-summary">
                    <span>Current value</span>
                    <strong>{{ $valueText }}</strong>
                </div>

                @if(filled($setting->description))
                    <p class="settings-description">{{ $setting->description }}</p>
                @endif

                @if($dataType === 'BOOLEAN')
                    <div class="checkbox-field">
                        <label class="checkbox">
                            <input type="hidden" name="value" value="0">
                            <input type="checkbox" name="value" value="1" @checked((bool) $value)>
                            <span>Enabled</span>
                        </label>
                    </div>
                @elseif($dataType === 'INTEGER' || $dataType === 'MONEY')
                    <label>
                        Value
                        <input type="number" name="value" value="{{ $value === null ? '' : $value }}" step="{{ $dataType === 'MONEY' ? '0.01' : '1' }}" placeholder="Not configured">
                    </label>
                @else
                    <label>
                        Value
                        <input type="text" name="value" value="{{ $value === null ? '' : $value }}" placeholder="Not configured">
                    </label>
                @endif

                <label class="reason-field">
                    Reason for change
                    <textarea name="reason" required placeholder="Describe the update reason..."></textarea>
                </label>

                <div class="settings-actions">
                    <button class="button primary ui-pressable" data-save-button type="submit" disabled>Save change</button>
                    <a class="button secondary ui-pressable" href="{{ route('administration.index') }}"><x-icon name="arrow-left" size="17" /> Back</a>
                </div>

                <small class="audit-note">Changes are recorded in the audit trail.</small>
            </form>
        @endforeach
    </div>
</section>
@endif

@endif

<style>
.settings-detail-heading .button{flex:0 0 auto}.return-policy-grid>div{padding:13px 14px;border:1px solid var(--border,#d7e1eb);border-radius:10px;background:var(--surface-muted,#f7f9fb)}.return-policy-grid span{display:block;color:var(--muted,#62758a);font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em}.return-policy-grid strong{display:block;margin-top:4px}.return-policy-card{gap:14px}.return-policy-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.callout.compact{padding:11px 12px}.callout.compact strong,.callout.compact span{display:block}.callout.compact span{margin-top:3px}.callout code{font-size:.84em}@media(max-width:1100px){.return-policy-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:680px){.settings-detail-heading{align-items:flex-start}.return-policy-grid{grid-template-columns:1fr}}

.ictu-system-config-area{display:grid;gap:16px}.ictu-config-section-heading h2{margin:2px 0 4px}.ictu-config-section-heading p{margin:0;color:var(--muted,#62758a)}.ictu-service-status-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.ictu-service-status-card,.ictu-technical-setting-card{min-width:0}.ictu-service-card-heading,.ictu-setting-card-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.ictu-service-card-heading h3,.ictu-setting-card-heading h3{margin:7px 0 0}.ictu-service-status-card>p,.ictu-technical-setting-card>p{margin:12px 0;color:var(--muted,#62758a);line-height:1.5}.ictu-config-state{display:inline-flex;align-items:center;justify-content:center;min-height:26px;padding:4px 9px;border:1px solid var(--border,#d7e1eb);border-radius:999px;font-size:.74rem;font-weight:800;white-space:nowrap}.ictu-config-state.is-ready{border-color:#a7dfbf;background:#edf9f2;color:#14743a}.ictu-config-state.is-pending{border-color:#d4dde7;background:#f3f6f9;color:#5a6d82}.ictu-readonly-value{padding:12px 13px;border:1px solid var(--border,#d7e1eb);border-radius:10px;background:var(--surface-muted,#f7f9fb)}.ictu-readonly-value span{display:block;color:var(--muted,#62758a);font-size:.75rem;font-weight:800;text-transform:uppercase;letter-spacing:.03em}.ictu-readonly-value strong{display:block;margin-top:4px;overflow-wrap:anywhere}.ictu-technical-settings-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.ictu-technical-setting-card textarea{min-height:100px}.ictu-config-boundary-note{margin-top:2px}.ictu-config-boundary-note strong,.ictu-config-boundary-note span{display:block}.ictu-config-boundary-note span{margin-top:3px}@media(max-width:1100px){.ictu-service-status-grid,.ictu-technical-settings-grid{grid-template-columns:1fr}}@media(max-width:680px){.ictu-service-card-heading,.ictu-setting-card-heading{align-items:flex-start;flex-direction:column}}


</style>

<script>
    document.querySelectorAll('[data-settings-form]').forEach(function (form) {
        const submit = form.querySelector('[data-save-button]');
        if (!submit) return;
        const initialState = new FormData(form);

        const updateState = function () {
            const current = new FormData(form);
            const changed = Array.from(current.entries()).some(function ([key, value]) {
                return key !== '_token' && key !== '_method' && initialState.get(key) !== value;
            });
            submit.disabled = !changed;
        };

        form.querySelectorAll('input, textarea, select').forEach(function (field) {
            field.addEventListener('input', updateState);
            field.addEventListener('change', updateState);
        });
    });
</script>
@endsection
