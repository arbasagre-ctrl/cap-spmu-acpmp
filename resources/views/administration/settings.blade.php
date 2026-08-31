@extends('layouts.app', ['title' => 'Configuration'])
@section('content')
@php
    $humanizedKey = fn ($key) => ucwords(str_replace('_', ' ', (string) $key));

    $configurationSection = request()->query('section');
    $allowedConfigurationSections = [
        'late-return-fee',
        'template-billing-statement',
        'template-laundry-form',
        'template-gate-pass',
    ];

    if (! in_array($configurationSection, $allowedConfigurationSections, true)) {
        $configurationSection = null;
    }

    $sectionMeta = [
        'late-return-fee' => ['Financial assessment', 'Late Return Fee', 'Define the daily late return rate and manage how the fee is applied.'],
        'template-billing-statement' => ['Controlled Documents', 'Billing Statement Template', 'Upload, version, and activate the approved Billing Statement template.'],
        'template-laundry-form' => ['Controlled Documents', 'Laundry Form Template', 'Upload, version, and activate the approved Laundry Form template.'],
        'template-gate-pass' => ['Controlled Documents', 'Gate Pass Template', 'Upload, version, and activate the approved Gate Pass template.'],
    ];

    $activeSectionMeta = $configurationSection ? $sectionMeta[$configurationSection] : null;

    $templateSectionType = match($configurationSection) {
        'template-billing-statement' => 'BILLING_STATEMENT',
        'template-laundry-form' => 'LAUNDRY_FORM',
        'template-gate-pass' => 'GATE_PASS',
        default => null,
    };

    $visibleTemplateTypes = $templateSectionType
        ? array_filter($templateTypes, fn ($label, $type) => $type === $templateSectionType, ARRAY_FILTER_USE_BOTH)
        : $templateTypes;

    $lateReturnFeeSetting = $settings->firstWhere('setting_key', 'daily_overdue_tariff');
@endphp

<section class="page-heading settings-detail-heading">
    <div>
        <p class="eyebrow">{{ $activeSectionMeta[0] ?? 'Effective operational configuration' }}</p>
        <h1>{{ $activeSectionMeta[1] ?? 'Operational Configuration' }}</h1>
        <p>{{ $activeSectionMeta[2] ?? 'Manage approved policy values and controlled document templates. Every change is preserved in the audit trail.' }}</p>
    </div>

    @if($configurationSection)
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
            <p>Upload a new approved template version without replacing historical versions. New generated documents are linked to the active version.</p>
        </div>
    </div>

    <div class="template-config-grid {{ $templateSectionType ? 'template-config-grid-single' : '' }}">
        @foreach($visibleTemplateTypes as $type => $label)
            @php
                $history = $documentTemplates->get($type, collect());
                $activeTemplate = $history->firstWhere('status', 'ACTIVE');
                $activeVersion = $activeTemplate?->version_label ?: ($activeTemplate ? 'v'.$activeTemplate->template_version.'.0' : 'v1.0');
                $sourceMode = $activeTemplate?->source_mode ?: 'BUILT_IN';
                $sourceLabel = match($sourceMode) {
                    'HTML_PLACEHOLDER' => 'Uploaded auto-fill HTML',
                    'REFERENCE_ONLY' => 'Controlled reference file',
                    default => 'Built-in auto-fill layout',
                };
            @endphp

            <article class="card template-config-card" id="template-{{ strtolower(str_replace('_', '-', $type)) }}">
                <div class="card-header settings-card-header">
                    <div>
                        <span class="badge">DOCUMENT</span>
                        <h3>{{ $label }}</h3>
                        <small>{{ $type }}</small>
                    </div>
                    <x-status-badge :status="$activeTemplate?->status ?: 'ACTIVE'" />
                </div>

                <div class="template-current-summary">
                    <div>
                        <span>Current version</span>
                        <strong>{{ $activeVersion }}</strong>
                    </div>
                    <div>
                        <span>Generation source</span>
                        <strong>{{ $sourceLabel }}</strong>
                    </div>
                </div>

                <div class="template-source-row">
                    <div>
                        <small>Current source file</small>
                        <strong>{{ $activeTemplate?->file?->original_name ?: 'Built-in system template' }}</strong>
                    </div>
                    @if($activeTemplate?->file)
                        <a class="button secondary small ui-pressable" href="{{ route('files.show', $activeTemplate->file) }}" target="_blank" rel="noopener">View / Download</a>
                    @endif
                </div>

                @if($sourceMode === 'REFERENCE_ONLY')
                    <div class="callout compact">
                        <strong>Reference-only source.</strong>
                        <span>The PDF/DOCX is preserved as the official source, but the safe built-in auto-fill layout remains in use. Upload HTML containing <code>@{{generated_content}}</code> when the uploaded layout itself must drive newly generated borrower documents.</span>
                    </div>
                @elseif($sourceMode === 'HTML_PLACEHOLDER')
                    <div class="callout compact success">
                        <strong>Auto-fill enabled.</strong>
                        <span>New applicable documents generated after activation use this uploaded HTML template shell. Existing generated documents remain unchanged.</span>
                    </div>
                @else
                    <div class="callout compact">
                        <strong>Built-in version.</strong>
                        <span>Upload a new approved version below. HTML with <code>@{{generated_content}}</code> automatically applies to future generated documents.</span>
                    </div>
                @endif

                <form method="post" action="{{ route('administration.document-templates.store', ['type' => strtolower(str_replace('_', '-', $type))]) }}" enctype="multipart/form-data" class="form-grid template-upload-form">
                    @csrf

                    <label>
                        New version
                        <input type="text" name="version_label" value="{{ old('version_label') }}" placeholder="e.g. v1.1" required>
                    </label>

                    <label>
                        Template file
                        <input type="file" name="template_file" accept=".html,.htm,.pdf,.doc,.docx,text/html,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>
                        <small>HTML = automatic generated layout. PDF/DOCX = controlled reference only. Maximum 10 MB.</small>
                    </label>

                    <label>
                        Reason for change
                        <textarea name="reason" required maxlength="1000" placeholder="Example: Updated signatory section and revised official form layout.">{{ old('reason') }}</textarea>
                    </label>

                    <div class="settings-actions">
                        <button class="button primary ui-pressable" type="submit">Upload &amp; Activate</button>
                    </div>
                </form>

                @if($history->count() > 1)
                    <details class="template-history">
                        <summary>Version history ({{ $history->count() }})</summary>
                        <div class="template-history-list">
                            @foreach($history as $template)
                                <div>
                                    <span><strong>{{ $template->version_label ?: 'v'.$template->template_version.'.0' }}</strong> · {{ str($template->status)->replace('_', ' ')->title() }}</span>
                                    <small>{{ $template->file?->original_name ?: 'Built-in' }}{{ $template->activated_at ? ' · '.$template->activated_at->format('d M Y') : '' }}</small>
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endif
            </article>
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

<style>
.settings-detail-heading{align-items:flex-end}.settings-detail-heading .button{flex:0 0 auto}.template-config-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}.template-config-grid-single{grid-template-columns:minmax(0,760px)}.template-config-card{display:flex;flex-direction:column;gap:16px;min-width:0}.template-current-summary{display:grid;grid-template-columns:1fr 1fr;gap:10px}.template-current-summary>div,.return-policy-grid>div{padding:13px 14px;border:1px solid var(--border,#d7e1eb);border-radius:10px;background:var(--surface-muted,#f7f9fb)}.template-current-summary span,.return-policy-grid span,.template-source-row small{display:block;color:var(--muted,#62758a);font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em}.template-current-summary strong,.return-policy-grid strong,.template-source-row strong{display:block;margin-top:4px}.template-source-row{display:flex;align-items:center;justify-content:space-between;gap:12px}.template-upload-form{padding-top:14px;border-top:1px solid var(--border,#d7e1eb)}.template-upload-form textarea{min-height:92px}.template-history summary{cursor:pointer;font-weight:700}.template-history-list{display:grid;gap:8px;margin-top:10px}.template-history-list>div{display:flex;justify-content:space-between;gap:10px;padding:9px 0;border-top:1px solid var(--border,#d7e1eb)}.template-history-list small{color:var(--muted,#62758a);text-align:right}.return-policy-card{gap:14px}.return-policy-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.callout.compact{padding:11px 12px}.callout.compact strong,.callout.compact span{display:block}.callout.compact span{margin-top:3px}.callout code{font-size:.84em}@media(max-width:1100px){.template-config-grid{grid-template-columns:1fr}.return-policy-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:680px){.settings-detail-heading{align-items:flex-start}.template-current-summary,.return-policy-grid{grid-template-columns:1fr}.template-source-row,.template-history-list>div{align-items:flex-start;flex-direction:column}.template-history-list small{text-align:left}}
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
