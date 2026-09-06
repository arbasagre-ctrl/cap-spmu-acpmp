@php
    $routeType = strtolower(str_replace('_', '-', $type));
    $drafts = $history->filter(fn ($template) => $template->source_mode === 'OFFICIAL_LAYOUT'
        && $template->stored_file_id !== null
        && $template->activated_at === null
        && $template->superseded_at === null
        && !in_array($template->status, ['ACTIVE', 'HISTORICAL', 'FINALIZED'], true)
        && !$template->generatedDocuments()->exists()
    )->values();
    $historical = $history->whereIn('status', ['ACTIVE', 'HISTORICAL'])->values();
    $registerId = 'template-register-'.$routeType;
@endphp

<article class="card official-template-card" id="template-{{ $routeType }}">
    <div class="official-template-heading">
        <div>
            <p class="eyebrow">Controlled document template</p>
            <h3>{{ $label }}</h3>
            <p>Upload the approved official form. The system prepares supported layouts automatically; you review the generated sample before activation.</p>
        </div>
        <x-status-badge :status="$activeTemplate?->status ?: 'ACTIVE'" />
    </div>

    <div class="official-template-current">
        <div><span>Current official layout</span><strong>{{ $activeVersion }}</strong></div>
        <div><span>Approved source</span><strong>{{ $activeTemplate?->file?->original_name ?: 'Built-in system layout' }}</strong></div>
        <div><span>Activation</span><strong>Always requires sample review</strong></div>
    </div>

    @if($activeTemplate?->file)
        <div class="official-template-actions">
            <a class="button secondary small ui-pressable" href="{{ route('administration.document-templates.review', ['type' => $routeType, 'template' => $activeTemplate]) }}" target="_blank" rel="noopener">Review Current Layout</a>
            <a class="button secondary small ui-pressable" href="{{ route('administration.document-templates.download', ['type' => $routeType, 'template' => $activeTemplate]) }}">Download</a>
        </div>
    @endif

    <details id="{{ $registerId }}" class="official-template-register" data-template-register @if($drafts->isEmpty()) open @endif>
        <summary>Upload Newly Approved Official Layout</summary>
        <form method="post" action="{{ route('administration.document-templates.draft.store', ['type' => $routeType]) }}" enctype="multipart/form-data" class="official-template-form">
            @csrf
            <label>Version
                <input type="text" name="version_label" value="{{ old('version_label') }}" placeholder="e.g. v2.0" required>
            </label>
            <label>Approved source file
                <input type="file" name="template_file" data-template-file accept=".pdf,.docx,.xlsx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                <small>PDF, DOCX, or XLSX up to 10 MB. The approved source is preserved exactly as uploaded.</small>
            </label>
            <label class="official-template-wide">Reason for revision
                <textarea name="reason" maxlength="1000" required placeholder="Example: Approved form revision signed by CSPC/SPMU.">{{ old('reason') }}</textarea>
            </label>
            <div class="official-template-wide"><button class="button primary ui-pressable" type="submit">Upload Approved Official Form</button></div>
        </form>
    </details>

    @foreach($drafts as $draft)
        @php
            $configuration = json_decode((string) $draft->content_template, true) ?: [];
            $format = $configuration['format'] ?? 'SOURCE';
            $analysis = is_array($configuration['analysis'] ?? null) ? $configuration['analysis'] : [];
            $preparation = is_array($configuration['preparation'] ?? null) ? $configuration['preparation'] : [];
            $preparationState = $preparation['state'] ?? ($draft->status === 'NEEDS_SYSTEM_READY_TEMPLATE' ? 'FAILED' : 'PENDING');
            $ready = $draft->status === 'READY_FOR_PREVIEW' && (bool) ($analysis['ready'] ?? false);
        @endphp
        <section class="official-template-draft">
            <div class="official-template-draft-heading">
                <div>
                    <span class="badge">{{ $format }}</span>
                    <h4>{{ $draft->version_label ?: 'v'.$draft->template_version.'.0' }}</h4>
                    <p>{{ $draft->file?->original_name }} · {{ $draft->change_reason }}</p>
                </div>
                <div class="official-template-actions">
                    <a class="button secondary small ui-pressable" href="{{ route('administration.document-templates.review', ['type' => $routeType, 'template' => $draft]) }}" target="_blank" rel="noopener">Review Approved Source</a>
                    @if($ready)
                        <button class="button small ui-pressable template-discard-trigger" type="button" data-discard-open="discard-draft-{{ $draft->id }}">Discard Draft</button>
                    @endif
                </div>
            </div>

            <dialog id="discard-draft-{{ $draft->id }}" class="template-discard-dialog" aria-labelledby="discard-draft-title-{{ $draft->id }}">
                <div class="template-discard-dialog__content">
                    <h3 id="discard-draft-title-{{ $draft->id }}">Discard this draft layout?</h3>
                    <p>{{ $label }} {{ $draft->version_label ?: 'v'.$draft->template_version.'.0' }} has not been activated. Discarding it removes this draft preparation only; the current official layout remains unchanged.</p>
                    <div class="template-discard-dialog__actions">
                        <form method="dialog"><button class="button secondary ui-pressable" type="submit">Keep Draft</button></form>
                        <form method="post" action="{{ route('administration.document-templates.draft.destroy', ['type' => $routeType, 'template' => $draft]) }}">
                            @csrf @method('DELETE')
                            <button class="button ui-pressable template-discard-confirm" type="submit">Discard Draft</button>
                        </form>
                    </div>
                </div>
            </dialog>

            @if($ready)
                <div class="template-preparation-result is-ready">
                    <strong>Layout prepared</strong>
                    <span>✓ Approved source preserved</span>
                    <span>✓ Layout detected</span>
                    <span>✓ System fields connected</span>
                    <span>✓ Generated sample ready</span>
                </div>
                <div class="official-template-actions">
                    <a class="button secondary ui-pressable" href="{{ route('administration.document-templates.preview', ['type' => $routeType, 'template' => $draft]) }}" target="_blank" rel="noopener">Preview Generated Sample</a>
                </div>
                <form method="post" action="{{ route('administration.document-templates.activate', ['type' => $routeType, 'template' => $draft]) }}" class="official-template-activate" onsubmit="return confirm('Activate {{ $label }} {{ $draft->version_label }}? Future documents will use this official layout. Previously generated documents will not change.');">
                    @csrf
                    <label class="template-preview-confirm"><input type="checkbox" name="preview_confirmed" value="1" required> I reviewed the generated sample and approve this official layout.</label>
                    <button class="button primary ui-pressable" type="submit">Activate as Official Layout</button>
                </form>
            @else
                <div class="template-preparation-result">
                    @if($preparationState === 'FAILED')
                        <strong>We couldn't automatically identify enough of this form to generate documents reliably.</strong>
                    @else
                        <strong>Template needs preparation</strong>
                        <p>This approved form was uploaded successfully. The system needs to prepare it before it can generate documents.</p>
                    @endif
                </div>
                <div class="official-template-actions">
                    <form method="post" action="{{ route('administration.document-templates.prepare', ['type' => $routeType, 'template' => $draft]) }}">
                        @csrf
                        <button class="button primary ui-pressable" type="submit">{{ $preparationState === 'FAILED' ? 'Try Again' : 'Prepare Automatically' }}</button>
                    </form>
                    <button class="button secondary ui-pressable" type="button" data-template-replace="{{ $registerId }}">Replace File</button>
                    <button class="button ui-pressable template-discard-trigger" type="button" data-discard-open="discard-draft-{{ $draft->id }}">Discard Draft</button>
                </div>
            @endif
        </section>
    @endforeach

    @if($historical->count() > 1)
        <details class="template-history official-template-history">
            <summary>Version History ({{ $historical->count() }})</summary>
            <div class="template-history-list">
                @foreach($historical as $version)
                    <div><span><strong>{{ $version->version_label ?: 'v'.$version->template_version.'.0' }}</strong> · {{ str($version->status)->replace('_', ' ')->title() }}</span><small>{{ $version->file?->original_name ?: 'Built-in system layout' }}{{ $version->activated_at ? ' · '.$version->activated_at->format('d M Y') : '' }}</small></div>
                @endforeach
            </div>
        </details>
    @endif
</article>

<style>
.official-template-card,.official-template-draft,.official-template-activate{display:grid;gap:14px}.official-template-heading,.official-template-draft-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:14px}.official-template-heading h3,.official-template-draft-heading h4{margin:3px 0 5px}.official-template-heading p,.official-template-draft-heading p,.template-preparation-result p{margin:0;color:var(--muted,#62758a);line-height:1.5}.official-template-current{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.official-template-current>div,.official-template-register,.official-template-draft{padding:14px;border:1px solid var(--border,#d7e1eb);border-radius:10px;background:var(--surface-muted,#f7f9fb)}.official-template-current span{display:block;font-size:.72rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--muted,#62758a)}.official-template-current strong{display:block;margin-top:4px;overflow-wrap:anywhere}.official-template-actions{display:flex;gap:8px;flex-wrap:wrap}.official-template-actions form{margin:0}.official-template-register summary{cursor:pointer;font-weight:800}.official-template-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:14px}.official-template-wide{grid-column:1/-1}.official-template-form textarea{min-height:80px}.template-preparation-result{display:grid;gap:5px;padding:13px 14px;border-left:4px solid var(--warning-border,#d89d2a);border-radius:7px;background:var(--warning-bg,#fff8e4);color:var(--text-secondary)}.template-preparation-result.is-ready{border-color:var(--success,#25855a);background:var(--success-bg,#eaf7ef)}.template-preparation-result strong{margin-bottom:2px}.template-preview-confirm{display:flex;align-items:center;gap:8px;font-size:.86rem;font-weight:700}.official-template-activate{padding-top:2px}.template-discard-trigger{border-color:#b42318!important;background:#fff!important;color:#b42318!important}.template-discard-trigger:hover,.template-discard-trigger:focus-visible{background:#b42318!important;color:#fff!important}.template-discard-dialog{width:min(100% - 32px,520px);padding:0;border:0;border-radius:12px;color:var(--text,#1b2b3d);box-shadow:0 24px 60px rgba(15,35,55,.28)}.template-discard-dialog::backdrop{background:rgba(10,27,45,.52)}.template-discard-dialog__content{display:grid;gap:14px;padding:24px}.template-discard-dialog__content h3,.template-discard-dialog__content p{margin:0}.template-discard-dialog__content p{color:var(--muted,#62758a);line-height:1.55}.template-discard-dialog__actions{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap}.template-discard-dialog__actions form{margin:0}.template-discard-confirm{border-color:#b42318!important;background:#b42318!important;color:#fff!important}.template-discard-confirm:hover,.template-discard-confirm:focus-visible{background:#8d1c13!important;border-color:#8d1c13!important}@media(max-width:800px){.official-template-current,.official-template-form{grid-template-columns:1fr}.official-template-heading,.official-template-draft-heading{flex-direction:column}.template-discard-dialog__actions{justify-content:stretch}.template-discard-dialog__actions form{flex:1}.template-discard-dialog__actions .button{width:100%;justify-content:center}}
</style>

<script>
if (!window.templateDraftPreparationControlsReady) {
    window.templateDraftPreparationControlsReady = true;
    document.addEventListener('click', (event) => {
        const discard = event.target.closest('[data-discard-open]');
        if (discard) document.getElementById(discard.dataset.discardOpen)?.showModal();

        const replace = event.target.closest('[data-template-replace]');
        if (replace) {
            const register = document.getElementById(replace.dataset.templateReplace);
            register?.setAttribute('open', 'open');
            register?.querySelector('[data-template-file]')?.focus();
        }
    });
}
</script>
