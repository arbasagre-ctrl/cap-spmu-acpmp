@extends('layouts.app', ['title' => $isIctu ? 'System Configuration' : 'Operational Configuration'])
@section('content')
@php
    $humanizedKey = fn ($key) => ucwords(str_replace('_', ' ', (string) $key));

    $configurationSection = request()->query('section');
    $allowedConfigurationSections = [
        'late-return-fee',
        'template-borrower-slip',
        'template-laundry-form',
        'template-gate-pass',
        'template-billing-statement',
        'template-rslddp',
    ];

    if (! in_array($configurationSection, $allowedConfigurationSections, true)) {
        $configurationSection = null;
    }

    $sectionMeta = [
        'late-return-fee' => ['Financial assessment', 'Late Return Fee', 'Define the daily late return rate and manage how the fee is applied.'],
        'template-borrower-slip' => ['Controlled Documents', "Borrower's Slip Template", "Maintain the approved Borrower's Slip source file and activate new versions."],
        'template-laundry-form' => ['Controlled Documents', 'Laundry Form Template', 'Maintain the approved Laundry Form source file and activate new versions.'],
        'template-gate-pass' => ['Controlled Documents', 'Gate Pass Template', 'Maintain the approved Gate Pass source file and activate new versions.'],
        'template-billing-statement' => ['Controlled Documents', 'Billing Statement Template', 'Maintain the approved Billing Statement source file and activate new versions.'],
        'template-rslddp' => ['Controlled Documents', 'RSLDDP Template', 'Maintain the approved RSLDDP source file and activate new versions.'],
    ];

    $activeSectionMeta = $configurationSection ? $sectionMeta[$configurationSection] : null;

    $templateSectionType = match($configurationSection) {
        'template-borrower-slip' => 'BORROWER_SLIP',
        'template-laundry-form' => 'LAUNDRY_FORM',
        'template-gate-pass' => 'GATE_PASS',
        'template-billing-statement' => 'BILLING_STATEMENT',
        'template-rslddp' => 'RSLDDP',
        default => null,
    };

    $visibleTemplateTypes = $templateSectionType
        ? array_filter($templateTypes, fn ($label, $type) => $type === $templateSectionType, ARRAY_FILTER_USE_BOTH)
        : $templateTypes;

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

    $smsProviderSetting = $isIctu
        ? $ictuSettings->get('sms_provider')
        : null;

    $effectiveSmsProvider = $isIctu
        ? (
            filled($smsProviderSetting?->value_json)
                ? (string) $smsProviderSetting->value_json
                : trim((string) config('services.sms.provider'))
        )
        : '';

    $smsEndpointConfigured = $isIctu
        && filled(config('services.sms.webhook_url'));

    $smsReady = $isIctu
        && filled($effectiveSmsProvider)
        && $smsEndpointConfigured;

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
            <p>{{ $activeSectionMeta[2] ?? 'Manage approved policy values and controlled document templates. Every change is preserved in the audit trail.' }}</p>
        @endif
    </div>

    @if(!$isIctu && $configurationSection)
        <a class="button secondary ui-pressable config-back-button" href="{{ route('policies.index') }}">
            <x-icon name="arrow-left" size="17" />
            Back to Operational Configuration
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

        <article class="card ictu-service-status-card">
            <div class="ictu-service-card-heading">
                <div>
                    <span class="badge">SMS</span>
                    <h3>SMS Delivery</h3>
                </div>
                <span class="ictu-config-state {{ $smsReady ? 'is-ready' : 'is-pending' }}">
                    {{ $smsReady ? 'Ready' : 'Not configured' }}
                </span>
            </div>
            <p>SMS delivery requires both a provider name and the protected webhook configuration.</p>
            <div class="ictu-readonly-value">
                <span>Effective provider</span>
                <strong>{{ filled($effectiveSmsProvider) ? $effectiveSmsProvider : 'Not configured' }}</strong>
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
        @if($smsProviderSetting)
            <form
                id="setting-{{ $smsProviderSetting->setting_key }}"
                method="post"
                action="{{ route('administration.settings.update', $smsProviderSetting) }}"
                class="card form-grid ictu-technical-setting-card"
                data-settings-form
            >
                @csrf
                @method('PUT')

                <div class="ictu-setting-card-heading">
                    <div>
                        <span class="badge">NOTIFICATION</span>
                        <h3>SMS Provider</h3>
                    </div>
                    <span class="ictu-config-state {{ $smsReady ? 'is-ready' : 'is-pending' }}">
                        {{ $smsReady ? 'Ready' : 'Not configured' }}
                    </span>
                </div>

                <p>Record the approved SMS provider name. Webhook URL and API credentials remain protected in the environment configuration.</p>

                <label>
                    Provider name
                    <input
                        type="text"
                        name="value"
                        value="{{ $smsProviderSetting->value_json ?? '' }}"
                        placeholder="Enter approved SMS provider"
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
        <span>Return rules, late fees, workflow deadlines, sanctions, and controlled document templates remain under SPMU Admin/Head.</span>
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

@if(!$configurationSection || $templateSectionType)
<section class="content-area" id="document-templates">
    <div class="section-heading">
        <div>
            <p class="eyebrow">Controlled document management</p>
            <h2>{{ $templateSectionType ? ($visibleTemplateTypes[$templateSectionType] ?? 'Document Template') : 'Document templates' }}</h2>
            <p>Register an approved PDF, DOCX, or XLSX source; map validated system fields; review the sample; and explicitly activate the new controlled version. Uploading never replaces the active layout, and prior versions remain preserved.</p>
        </div>
    </div>

    <div class="template-config-grid {{ $templateSectionType ? 'template-config-grid-single' : '' }}">
        @foreach($visibleTemplateTypes as $type => $label)
            @php
                $history = $documentTemplates->get($type, collect());
                $activeTemplate = $history->firstWhere('status', 'ACTIVE');
                $activeVersion = $activeTemplate?->version_label ?: ($activeTemplate ? 'v'.$activeTemplate->template_version.'.0' : 'v1.0');
            @endphp

            @include('administration.partials.document-template-excel-manager', [
                'type' => $type,
                'label' => $label,
                'history' => $history,
                'activeTemplate' => $activeTemplate,
                'activeVersion' => $activeVersion,
            ])
        @endforeach
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
                    <a class="button secondary ui-pressable" href="{{ route('administration.index') }}">Back</a>
                </div>

                <small class="audit-note">Changes are recorded in the audit trail.</small>
            </form>
        @endforeach
    </div>
</section>
@endif

@endif

<style>
.settings-detail-heading{align-items:flex-end}.settings-detail-heading .button{flex:0 0 auto}.template-config-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}.template-config-grid-single{grid-template-columns:minmax(0,1fr)}.template-config-card{display:flex;flex-direction:column;gap:16px;min-width:0}.template-current-summary{display:grid;grid-template-columns:1fr 1fr;gap:10px}.template-current-summary>div,.return-policy-grid>div{padding:13px 14px;border:1px solid var(--border,#d7e1eb);border-radius:10px;background:var(--surface-muted,#f7f9fb)}.template-current-summary span,.return-policy-grid span,.template-source-row small{display:block;color:var(--muted,#62758a);font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em}.template-current-summary strong,.return-policy-grid strong,.template-source-row strong{display:block;margin-top:4px}.template-source-row{display:flex;align-items:center;justify-content:space-between;gap:12px}.template-source-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.template-upload-form{padding-top:14px;border-top:1px solid var(--border,#d7e1eb)}.template-upload-form textarea{min-height:92px}.template-history summary{cursor:pointer;font-weight:700}.template-history-list{display:grid;gap:8px;margin-top:10px}.template-history-list>div{display:flex;justify-content:space-between;gap:10px;padding:9px 0;border-top:1px solid var(--border,#d7e1eb)}.template-history-list small{color:var(--muted,#62758a);text-align:right}.return-policy-card{gap:14px}.return-policy-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.callout.compact{padding:11px 12px}.callout.compact strong,.callout.compact span{display:block}.callout.compact span{margin-top:3px}.callout code{font-size:.84em}@media(max-width:1100px){.template-config-grid{grid-template-columns:1fr}.return-policy-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:680px){.settings-detail-heading{align-items:flex-start}.template-current-summary,.return-policy-grid{grid-template-columns:1fr}.template-source-row,.template-history-list>div{align-items:flex-start;flex-direction:column}.template-history-list small{text-align:left}}

.ictu-system-config-area{display:grid;gap:16px}.ictu-config-section-heading h2{margin:2px 0 4px}.ictu-config-section-heading p{margin:0;color:var(--muted,#62758a)}.ictu-service-status-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.ictu-service-status-card,.ictu-technical-setting-card{min-width:0}.ictu-service-card-heading,.ictu-setting-card-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.ictu-service-card-heading h3,.ictu-setting-card-heading h3{margin:7px 0 0}.ictu-service-status-card>p,.ictu-technical-setting-card>p{margin:12px 0;color:var(--muted,#62758a);line-height:1.5}.ictu-config-state{display:inline-flex;align-items:center;justify-content:center;min-height:26px;padding:4px 9px;border:1px solid var(--border,#d7e1eb);border-radius:999px;font-size:.74rem;font-weight:800;white-space:nowrap}.ictu-config-state.is-ready{border-color:#a7dfbf;background:#edf9f2;color:#14743a}.ictu-config-state.is-pending{border-color:#d4dde7;background:#f3f6f9;color:#5a6d82}.ictu-readonly-value{padding:12px 13px;border:1px solid var(--border,#d7e1eb);border-radius:10px;background:var(--surface-muted,#f7f9fb)}.ictu-readonly-value span{display:block;color:var(--muted,#62758a);font-size:.75rem;font-weight:800;text-transform:uppercase;letter-spacing:.03em}.ictu-readonly-value strong{display:block;margin-top:4px;overflow-wrap:anywhere}.ictu-technical-settings-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.ictu-technical-setting-card textarea{min-height:100px}.ictu-config-boundary-note{margin-top:2px}.ictu-config-boundary-note strong,.ictu-config-boundary-note span{display:block}.ictu-config-boundary-note span{margin-top:3px}@media(max-width:1100px){.ictu-service-status-grid,.ictu-technical-settings-grid{grid-template-columns:1fr}}@media(max-width:680px){.ictu-service-card-heading,.ictu-setting-card-heading{align-items:flex-start;flex-direction:column}}


.live-template-card{display:grid;gap:16px;max-width:1280px}.live-template-toolbar{display:flex;align-items:flex-start;justify-content:space-between;gap:18px}.live-template-toolbar h3{margin:2px 0 4px}.live-template-toolbar p{margin:0;color:var(--muted,#62758a)}.live-template-toolbar-meta{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.live-template-canvas-wrap{padding:18px;border:1px solid var(--border,#d7e1eb);border-radius:12px;background:#e9edf2;overflow:auto}.live-template-sheet{width:min(100%,980px);min-height:690px;margin:0 auto;padding:34px 42px;background:#fff;color:#111;border:1px solid #cfd7df;box-sizing:border-box;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.25}.template-doc-header{display:grid;grid-template-columns:62px 1fr auto;gap:12px;align-items:center}.template-doc-header.compact{grid-template-columns:56px 1fr auto}.template-logo-mark{width:48px;height:48px;border:2px solid #173e77;border-radius:50%;display:grid;place-items:center;color:#173e77;font-size:9px;font-weight:800}.template-school-copy{display:grid;gap:1px}.template-school-copy strong{font-size:14px}.template-form-code{align-self:start;font-weight:700;font-size:11px}.template-rule{height:1px;background:#222;margin:11px 0 7px}.template-doc-title{text-align:center;margin:7px 0 8px;font-size:21px}.template-reference-line{text-align:center;font-size:12px;font-weight:600;margin-bottom:27px}.template-editable{outline:1px dashed rgba(23,105,224,.45);outline-offset:2px;border-radius:2px;cursor:text}.template-editable:focus{outline:2px solid var(--interactive,#1769e0);background:#eef5ff}.template-editable:hover{background:#f4f8ff}.template-dynamic{color:#65768a;background:#f1f4f7;border-radius:3px;padding:1px 3px;font-style:italic}.template-line-value{display:inline-block;min-width:105px;border-bottom:1px solid #222;border-radius:0;text-align:center;background:transparent}.borrower-template-addressee{display:grid;grid-template-columns:1fr auto;gap:20px;margin:0 0 20px}.borrower-template-addressee>div:first-child{display:grid;gap:3px}.template-date-line{white-space:nowrap}.borrower-salutation{width:max-content;margin:0 0 8px}.borrower-template-message{text-indent:34px;text-align:justify;line-height:1.45;margin:0 0 18px}.template-grid-table{width:100%;border-collapse:collapse;table-layout:fixed}.template-grid-table th,.template-grid-table td{border:1px solid #222;padding:6px;text-align:center;vertical-align:middle}.template-grid-table th{font-size:12px}.template-left{text-align:left!important}.borrower-items-preview th:nth-child(1){width:10%}.borrower-items-preview th:nth-child(2){width:10%}.borrower-items-preview th:nth-child(3){width:38%}.borrower-items-preview th:nth-child(4){width:42%}.borrower-items-preview td{height:28px}.template-items-placeholder{height:48px!important}.borrower-template-lower{display:grid;grid-template-columns:1fr 1fr;gap:44px;margin-top:28px}.template-equals{font-family:monospace;margin-bottom:10px}.template-writing-line{height:20px;border-bottom:1px solid #222}.template-writing-line.inline{display:inline-block;width:110px;height:12px}.borrower-signature-preview{text-align:center}.borrower-signature-preview>p{text-align:left;width:max-content;margin-left:16px}.template-signature-box{height:28px;display:grid;place-items:center;margin-top:10px}.template-sign-line{height:1px;background:#222;margin:0 auto 4px;width:88%}.template-sign-line.short{width:72%}.template-space{height:20px}.borrower-signature-preview small,.gatepass-signatories small{display:block}.borrower-approval-preview{width:43%;margin:34px auto 0;text-align:center;display:grid;gap:4px}.template-doc-footer{display:grid;grid-template-columns:1fr 1fr 1fr;align-items:center;border-top:1px solid #222;margin-top:42px;padding-top:7px;font-size:10px}.template-doc-footer>*:nth-child(2){text-align:center}.template-doc-footer>*:nth-child(3){text-align:right}.laundry-request-line{display:grid;grid-template-columns:1fr 1fr;gap:28px;margin:16px 0 8px}.laundry-items-preview td{height:160px;vertical-align:top;padding-top:16px}.laundry-items-preview th:nth-child(1){width:11%}.laundry-items-preview th:nth-child(2){width:9%}.laundry-items-preview th:nth-child(3){width:41%}.laundry-items-preview th:nth-child(4){width:19%}.laundry-items-preview th:nth-child(5){width:20%}.laundry-signature-preview{margin-top:30px;font-size:11px}.laundry-signature-preview th,.laundry-signature-preview td{height:24px;padding:4px}.gatepass-number-block{width:34%;margin-left:auto;display:grid;gap:4px;font-size:12px}.gatepass-to-line{display:grid;grid-template-columns:48px 1fr;gap:6px;margin:18px 0 10px}.gatepass-intro{margin-left:48px;line-height:1.45}.gatepass-items-preview{margin-top:12px}.gatepass-items-preview td{height:27px}.gatepass-bearer-block{width:48%;margin-top:22px;text-align:center;display:grid;gap:4px}.gatepass-bearer-block>strong:first-child{text-align:left}.gatepass-signatories{display:grid;grid-template-columns:1fr 1fr;gap:54px;margin-top:32px}.gatepass-signatories>div{text-align:center;display:grid;gap:4px}.gatepass-signatories>div>strong:first-child{text-align:left}.gatepass-release-block{width:48%;display:grid;gap:7px;margin-top:30px}.template-sign-line.guard{width:100%;height:24px;background:transparent;border-bottom:1px solid #222}.live-template-save-panel{display:grid;grid-template-columns:minmax(140px,190px) minmax(260px,1fr) auto auto;gap:12px;align-items:end;margin-top:16px;padding-top:16px;border-top:1px solid var(--border,#d7e1eb)}.live-template-save-panel label{margin:0}.live-template-reason{min-width:0}.live-template-note{margin:10px 0 0;color:var(--muted,#62758a);font-size:.86rem}.live-template-history{margin-top:2px}@media(max-width:1050px){.live-template-save-panel{grid-template-columns:1fr 1fr}.live-template-sheet{min-width:820px}}@media(max-width:680px){.live-template-toolbar{flex-direction:column}.live-template-toolbar-meta{justify-content:flex-start}.live-template-save-panel{grid-template-columns:1fr}.live-template-canvas-wrap{padding:10px}}


.excel-template-card{display:grid;gap:16px;max-width:1280px}.excel-template-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:18px}.excel-template-heading h3{margin:2px 0 4px}.excel-template-heading p{margin:0;color:var(--muted,#62758a)}.excel-template-badges{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.excel-template-fixed-note strong,.excel-template-fixed-note span{display:block}.excel-template-fixed-note span{margin-top:3px}.excel-template-current{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.excel-template-current>div{padding:12px 14px;border:1px solid var(--border,#d7e1eb);border-radius:10px;background:var(--surface-muted,#f7f9fb)}.excel-template-current span{display:block;color:var(--muted,#62758a);font-size:.75rem;font-weight:800;text-transform:uppercase;letter-spacing:.03em}.excel-template-current strong{display:block;margin-top:4px;overflow-wrap:anywhere}.excel-template-actions{display:flex;gap:10px;flex-wrap:wrap}.excel-template-upload-section,.excel-template-drafts{padding-top:16px;border-top:1px solid var(--border,#d7e1eb)}.compact-section-heading{margin-bottom:12px}.compact-section-heading h4{margin:2px 0 3px}.compact-section-heading p{margin:0;color:var(--muted,#62758a)}.excel-template-upload-form{grid-template-columns:minmax(140px,180px) minmax(280px,1fr);gap:12px 16px}.excel-template-upload-form label:nth-child(3),.excel-template-upload-form .settings-actions{grid-column:1/-1}.excel-template-draft-list{display:grid;gap:10px}.excel-template-draft-row{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:12px 14px;border:1px solid var(--border,#d7e1eb);border-radius:10px;background:var(--surface-muted,#f7f9fb)}.excel-template-draft-row>div:first-child{min-width:0}.excel-template-draft-row strong,.excel-template-draft-row span,.excel-template-draft-row small{display:block}.excel-template-draft-row span{margin-top:2px;color:var(--muted,#62758a);overflow-wrap:anywhere}.excel-template-draft-row small{margin-top:4px;color:var(--muted,#62758a)}.excel-template-draft-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}.excel-template-draft-actions form{margin:0}.excel-template-history{margin-top:2px}@media(max-width:900px){.excel-template-heading,.excel-template-draft-row{align-items:flex-start;flex-direction:column}.excel-template-badges,.excel-template-draft-actions{justify-content:flex-start}.excel-template-current,.excel-template-upload-form{grid-template-columns:1fr}.excel-template-upload-form label:nth-child(3),.excel-template-upload-form .settings-actions{grid-column:auto}}

</style>

<script>
    document.querySelectorAll('[data-template-editor]').forEach(function (editor) {
        const form = editor.querySelector('[data-template-editor-form]');
        const configInput = editor.querySelector('[data-template-config-input]');
        const initialNode = editor.querySelector('[data-template-initial]');
        const saveButton = editor.querySelector('[data-template-save]');
        const resetButton = editor.querySelector('[data-template-reset]');
        const versionInput = form?.querySelector('[name="version_label"]');
        const reasonInput = form?.querySelector('[name="reason"]');
        const editableFields = Array.from(editor.querySelectorAll('[data-template-field]'));

        if (!form || !configInput || !initialNode || !saveButton) return;

        let initialConfig = {};
        try {
            initialConfig = JSON.parse(initialNode.textContent || '{}');
        } catch (error) {
            initialConfig = {};
        }

        const cleanText = (value) => (value || '').replace(/\s+/g, ' ').trim();

        const collectConfig = () => {
            const config = {};
            editableFields.forEach((field) => {
                config[field.dataset.templateField] = cleanText(field.textContent);
            });
            return config;
        };

        const sync = () => {
            const config = collectConfig();
            configInput.value = JSON.stringify(config);

            const textChanged = Object.keys(initialConfig).some(
                (key) => cleanText(config[key]) !== cleanText(initialConfig[key])
            );
            const hasVersion = cleanText(versionInput?.value) !== '';
            const hasReason = cleanText(reasonInput?.value) !== '';
            const hasBlankField = editableFields.some((field) => cleanText(field.textContent) === '');

            saveButton.disabled = !(textChanged && hasVersion && hasReason && !hasBlankField);
        };

        editableFields.forEach((field) => {
            field.addEventListener('input', sync);
            field.addEventListener('paste', (event) => {
                event.preventDefault();
                const text = event.clipboardData?.getData('text/plain') || '';
                document.execCommand('insertText', false, text);
            });
            field.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') event.preventDefault();
            });
        });

        versionInput?.addEventListener('input', sync);
        reasonInput?.addEventListener('input', sync);

        resetButton?.addEventListener('click', () => {
            editableFields.forEach((field) => {
                const key = field.dataset.templateField;
                field.textContent = initialConfig[key] ?? '';
            });
            if (versionInput) versionInput.value = '';
            if (reasonInput) reasonInput.value = '';
            sync();
        });

        form.addEventListener('submit', () => {
            sync();
        });

        sync();
    });

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
