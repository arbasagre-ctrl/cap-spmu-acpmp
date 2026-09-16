<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\DocumentTemplate;
use App\Models\EvidenceSubmission;
use App\Models\GeneratedDocument;
use App\Models\RequestSupportingDocument;
use App\Models\SignatureSnapshot;
use App\Models\StoredFile;
use App\Models\SystemSetting;
use App\Models\UserSignature;
use App\Services\AuditService;
use App\Services\DocumentTemplateLayoutService;
use App\Services\DocumentTemplateRenderer;
use App\Services\OfficeDraftTemplateRenderer;
use App\Services\OfficialFormRuntimeContextService;
use App\Services\ProtectedFileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

class DocumentTemplateController extends Controller
{
    private const TYPES = [
        'BORROWER_SLIP' => ['name' => "Borrower's Slip", 'setting_key' => 'borrower_slip_template_version'],
        'LAUNDRY_FORM' => ['name' => 'Laundry Form', 'setting_key' => 'laundry_form_template_version'],
        'GATE_PASS' => ['name' => 'Gate Pass', 'setting_key' => 'gate_pass_template_version'],
        'BILLING_STATEMENT' => ['name' => 'Billing Statement', 'setting_key' => 'billing_statement_template_version'],
        'ACCOUNTABILITY_COMPLIANCE_NOTICE' => ['name' => 'Accountability / Compliance Notice', 'setting_key' => 'accountability_compliance_notice_template_version'],
        'ADMINISTRATIVE_SANCTION_NOTICE' => ['name' => 'Administrative Sanction Notice', 'setting_key' => 'administrative_sanction_notice_template_version'],
        'RSLDDP' => ['name' => 'RSLDDP', 'setting_key' => 'rslddp_template_version'],
    ];

    public function storeDraft(
        Request $request,
        string $type,
        ProtectedFileService $files,
        DocumentTemplateLayoutService $layouts,
        DocumentTemplateRenderer $renderer,
        AuditService $audit
    ): RedirectResponse {
        $this->authorizeConfiguration($request);
        $type = $this->normalizeType($type);
        $this->assertKnownType($type);

        $data = $request->validate([
            'version_label' => ['required', 'string', 'max:30', 'regex:/^v?\d+(?:\.\d+){0,2}$/i'],
            'template_file' => ['required', 'file', 'max:10240'],
            'reason' => ['required', 'string', 'max:1000'],
        ], ['version_label.regex' => 'Use a version such as v1.1, v2.0, or 2.1.']);

        $versionLabel = $this->normalizedVersionLabel($data['version_label']);
        $this->assertVersionIsAvailable($type, $versionLabel);
        $inspection = $layouts->inspectUpload($request->file('template_file'));
        // PDF is retired as an editable official template source. Generated
        // PDF output is unaffected; only uploading a new PDF *source* layout
        // is blocked. DOCX/XLSX remain the only accepted upload formats.
        if ($inspection['format'] === 'PDF') {
            throw ValidationException::withMessages([
                'template_file' => 'PDF is no longer accepted as an official template source. Upload a DOCX or XLSX layout instead.',
            ]);
        }
        $renderRepresentation = $renderer->renderRepresentation($request->file('template_file'), $inspection['format']);
        $initialAnalysis = $layouts->analysis($type, []);
        $storedFile = $files->storeUpload(
            $request->file('template_file'),
            'document-templates/'.strtolower($type),
            'DOCUMENT_TEMPLATE_SOURCE'
        );
        $dynamicSchema = $layouts->schemaForStoredSource(
            $inspection['format'],
            $inspection['review'],
            $storedFile->sha256,
            $storedFile->id,
        );
        $renderFile = $renderRepresentation['representation'] === 'DIRECT_PDF'
            ? $storedFile
            : $files->storeBytes(
                $renderRepresentation['bytes'],
                'document-template-renders/'.strtolower($type),
                pathinfo($storedFile->original_name, PATHINFO_FILENAME).'-render.pdf',
                'application/pdf',
                'pdf',
                'DOCUMENT_TEMPLATE_RENDER'
            );

        $template = DB::transaction(function () use ($request, $type, $versionLabel, $data, $inspection, $initialAnalysis, $renderRepresentation, $storedFile, $renderFile, $dynamicSchema, $audit): DocumentTemplate {
            $template = DocumentTemplate::query()->create([
                'document_type' => $type,
                'template_version' => $this->nextVersion($type),
                'version_label' => $versionLabel,
                'template_name' => self::TYPES[$type]['name'].' '.$versionLabel,
                'content_template' => json_encode([
                    'format' => $inspection['format'],
                    'render_representation' => $renderRepresentation['representation'],
                    'review' => $this->persistedReview($inspection['review']),
                    'mappings' => [],
                    'table_layouts' => [],
                    'analysis' => $initialAnalysis,
                    'preparation' => ['state' => 'PENDING'],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'dynamic_schema' => $dynamicSchema,
                'stored_file_id' => $storedFile->id,
                'render_stored_file_id' => $renderFile->id,
                'source_mode' => 'OFFICIAL_LAYOUT',
                'change_reason' => trim($data['reason']),
                'status' => 'NEEDS_PREPARATION',
                'configured_by_user_id' => $request->user()->id,
                'activated_at' => null,
                'superseded_at' => null,
            ]);

            $audit->record('DOCUMENT_TEMPLATE_LAYOUT_REGISTERED', $template, reason: $template->change_reason, after: [
                'document_type' => $type,
                'version_label' => $versionLabel,
                'format' => $inspection['format'],
                'source_file' => $storedFile->original_name,
                'render_file' => $renderFile->original_name,
                'render_representation' => $renderRepresentation['representation'],
                'dynamic_schema_version' => $dynamicSchema['schema_version'] ?? null,
                'preparation' => 'PENDING',
            ]);

            return $template;
        }, 3);

        return $this->redirectToTemplate($type)->with(
            'status',
            "{$template->version_label} was uploaded successfully. Prepare it automatically before reviewing the generated sample. The active layout has not changed."
        );
    }

    public function prepare(
        Request $request,
        string $type,
        DocumentTemplate $template,
        ProtectedFileService $files,
        DocumentTemplateLayoutService $layouts,
        DocumentTemplateRenderer $renderer,
        OfficeDraftTemplateRenderer $officeDrafts,
        OfficialFormRuntimeContextService $runtimeContexts,
        AuditService $audit
    ): RedirectResponse {
        $this->authorizeConfiguration($request);
        $type = $this->normalizeType($type);
        $this->assertTemplateType($type, $template);
        abort_unless($template->source_mode === 'OFFICIAL_LAYOUT' && $template->file && $template->renderFile, 422, 'Only an uploaded draft can be prepared.');
        abort_if(in_array($template->status, ['ACTIVE', 'HISTORICAL', 'FINALIZED'], true), 422, 'An active or historical layout cannot be prepared again. Register a new version instead.');

        if ($officeDrafts->isOfficeDraft($template)) {
            return $this->prepareOfficeDraft($request, $type, $template, $layouts, $renderer, $officeDrafts, $runtimeContexts, $audit);
        }

        // PDF official layouts are retired: DOCX/XLSX are the only formats
        // storeDraft() accepts going forward, so the only way this branch is
        // still reached is a PDF draft uploaded before retirement (never
        // Office). It can no longer be (re-)prepared - the auto-mapping
        // engine this used to call is retired code, not just gated here.
        throw ValidationException::withMessages([
            'template' => 'PDF official layouts are retired and can no longer be prepared. Upload a DOCX or XLSX layout instead.',
        ]);
    }

    /**
     * Phase 3's Office path is intentionally Draft-only. It validates the
     * same temporary-clone compiler used by the generated sample, records
     * only resolver diagnostics, and leaves the legacy PDF mapping/renderer
     * and activation path untouched.
     */
    private function prepareOfficeDraft(
        Request $request,
        string $type,
        DocumentTemplate $template,
        DocumentTemplateLayoutService $layouts,
        DocumentTemplateRenderer $renderer,
        OfficeDraftTemplateRenderer $officeDrafts,
        OfficialFormRuntimeContextService $runtimeContexts,
        AuditService $audit,
    ): RedirectResponse {
        $configuration = $layouts->configuration($template);
        $before = $template->content_template;
        $runtimeContext = $runtimeContexts->forOfficeDraft($template);
        abort_unless($runtimeContext !== null, 422, 'The Office Draft runtime context is unavailable.');
        $officeContext = $runtimeContexts->withSyntheticDemo($runtimeContext, $renderer->sampleData($type));

        try {
            $prepared = $officeDrafts->prepare($template, $officeContext);
            $schema = is_array($template->dynamic_schema) ? $template->dynamic_schema : [];
            $schema['resolver'] = $prepared['resolver'];
            $schema['compatibility_warnings'] = $prepared['compatibility_warnings'];
            $preparation = [
                'state' => 'READY',
                'compiler' => 'OFFICE_DRAFT',
                'page_count' => $prepared['page_count'],
            ];
            $status = 'READY_FOR_PREVIEW';
        } catch (ValidationException $exception) {
            $message = $this->officeDraftFailureMessage($exception);
            $schema = is_array($template->dynamic_schema) ? $template->dynamic_schema : [];
            $warnings = array_values(array_filter($schema['compatibility_warnings'] ?? [], 'is_array'));
            $warnings[] = [
                'severity' => 'BLOCKING',
                'code' => 'OFFICE_DRAFT_RENDER_FAILED',
                'message' => $message,
            ];
            $schema['compatibility_warnings'] = $warnings;
            $preparation = ['state' => 'FAILED', 'compiler' => 'OFFICE_DRAFT', 'message' => $message];
            $status = 'NEEDS_PREPARATION';
        }

        $template->update([
            'content_template' => json_encode([
                'format' => $configuration['format'],
                'render_representation' => $configuration['render_representation'],
                'review' => $configuration['review'],
                // Office Drafts retain no PDF coordinate mappings. Their
                // explicit Office identities live only in dynamic_schema.
                'mappings' => [],
                'table_layouts' => [],
                'analysis' => $configuration['analysis'],
                'preparation' => $preparation,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'dynamic_schema' => $schema,
            'status' => $status,
            'configured_by_user_id' => $request->user()->id,
        ]);

        $audit->record('DOCUMENT_TEMPLATE_LAYOUT_PREPARED', $template, reason: $template->change_reason, before: [
            'configuration' => $before,
        ], after: [
            'format' => $configuration['format'],
            'compiler' => 'OFFICE_DRAFT',
            'preparation' => $preparation,
            'resolved_regions' => count($schema['resolver']['bindings'] ?? []),
        ]);

        $redirect = $this->redirectToTemplate($type);
        if ($status !== 'READY_FOR_PREVIEW') {
            return $redirect;
        }

        return $redirect->with('status', "{$template->version_label} was prepared with the Office Draft compiler and is ready for sample preview.");
    }

    private function officeDraftFailureMessage(ValidationException $exception): string
    {
        foreach ($exception->errors() as $messages) {
            if (is_array($messages) && isset($messages[0]) && is_string($messages[0])) {
                return $messages[0];
            }
        }

        return 'This Office Draft could not be rendered safely.';
    }

    /**
     * Phase 4: minor presentation editing.
     *
     * Shows only safe static wording an Admin may retouch - never a raw tag,
     * part path, coordinate, or content-control identity - so the form stays
     * usable without any Office background.
     *
     * Clicking Edit Template on the Active layout never edits it in place:
     * this redirects to a Draft copy of it instead, creating one only the
     * first time and reopening the same Draft on every later visit, so a
     * continued edit session never accumulates a new version per click.
     */
    public function editMinorPresentation(
        Request $request,
        string $type,
        DocumentTemplate $template,
        OfficeDraftTemplateRenderer $officeDrafts,
        AuditService $audit,
    ): View|RedirectResponse {
        $this->authorizeConfiguration($request);
        $type = $this->normalizeType($type);
        $this->assertTemplateType($type, $template);

        if ($template->status === 'ACTIVE') {
            $draft = $this->officeMinorEditDraftFor($template, $type, $request, $audit);
            abort_unless($draft !== null, 404, 'Minor presentation editing is only available for an uploaded DOCX or XLSX official layout.');

            return redirect()->route('administration.document-templates.minor-edit', ['type' => $this->routeType($type), 'template' => $draft]);
        }

        abort_unless($officeDrafts->isOfficeDraft($template), 404, 'Minor presentation editing is only available for an uploaded DOCX or XLSX Office Draft that has not been activated.');

        return view('administration.document-template-minor-edit', [
            'template' => $template,
            'type' => $type,
            'label' => self::TYPES[$type]['name'],
            'sections' => $officeDrafts->editableSections($template),
            'cancelRoute' => $this->redirectToTemplate($type)->getTargetUrl(),
        ]);
    }

    /**
     * Finds the Draft already cloned from this Active layout for minor
     * presentation editing, or creates one. The clone reuses the Active
     * layout's own stored file and validated schema untouched - same bytes,
     * same bindings, same page count - it only starts with an empty
     * minor_edits list and a marker linking it back to the Active row it was
     * copied from, which is how a later visit finds it again instead of
     * creating another Draft.
     */
    private function officeMinorEditDraftFor(DocumentTemplate $active, string $type, Request $request, AuditService $audit): ?DocumentTemplate
    {
        $format = strtoupper((string) data_get($active->dynamic_schema, 'source.format', ''));
        if ($active->source_mode !== 'OFFICIAL_LAYOUT' || ! in_array($format, ['DOCX', 'XLSX'], true) || ! $active->stored_file_id) {
            return null;
        }

        $existing = DocumentTemplate::query()
            ->where('document_type', $type)
            ->whereIn('status', ['NEEDS_PREPARATION', 'READY_FOR_PREVIEW'])
            ->get()
            ->first(fn (DocumentTemplate $draft): bool => (int) data_get($draft->dynamic_schema, 'minor_edit_source_id', 0) === $active->id);

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($active, $type, $request, $audit): DocumentTemplate {
            $versionLabel = $this->minorEditCloneVersionLabel($type, $active);
            $schema = is_array($active->dynamic_schema) ? $active->dynamic_schema : [];
            $schema['minor_edit_source_id'] = $active->id;
            $schema['minor_edits'] = [];

            $draft = DocumentTemplate::query()->create([
                'document_type' => $type,
                'template_version' => $this->nextVersion($type),
                'version_label' => $versionLabel,
                'template_name' => self::TYPES[$type]['name'].' '.$versionLabel,
                // The Active layout was already prepared and activated on
                // this exact source, so its configuration carries over as-is
                // rather than being rebuilt from scratch.
                'content_template' => $active->content_template,
                'dynamic_schema' => $schema,
                'stored_file_id' => $active->stored_file_id,
                'render_stored_file_id' => $active->render_stored_file_id,
                'source_mode' => 'OFFICIAL_LAYOUT',
                'change_reason' => "Presentation edit based on the active {$active->version_label}.",
                'status' => 'READY_FOR_PREVIEW',
                'configured_by_user_id' => $request->user()->id,
                'activated_at' => null,
                'superseded_at' => null,
            ]);

            $audit->record('DOCUMENT_TEMPLATE_MINOR_EDIT_DRAFT_CREATED', $draft, reason: $draft->change_reason, before: [
                'active_template_id' => $active->id,
                'active_version_label' => $active->version_label,
            ], after: [
                'document_type' => $type,
                'version_label' => $versionLabel,
                'source_file' => $active->file?->original_name,
            ]);

            return $draft;
        }, 3);
    }

    private function minorEditCloneVersionLabel(string $type, DocumentTemplate $active): string
    {
        $base = ($active->version_label ?: 'v'.$active->template_version.'.0').'-edit';
        $label = $base;
        $suffix = 1;
        while (DocumentTemplate::query()->where('document_type', $type)->whereRaw('LOWER(version_label) = ?', [strtolower($label)])->exists()) {
            $suffix++;
            $label = $base.'-'.$suffix;
        }

        return $label;
    }

    /**
     * Saves the Admin's edits onto this Draft only - the immutable uploaded
     * source is never touched - then immediately re-runs the same Office
     * Draft compile used for the generated sample, so Preview reflects the
     * change right away without a separate manual "Prepare" step.
     */
    public function updateMinorPresentation(
        Request $request,
        string $type,
        DocumentTemplate $template,
        OfficeDraftTemplateRenderer $officeDrafts,
        OfficialFormRuntimeContextService $runtimeContexts,
        DocumentTemplateRenderer $renderer,
        DocumentTemplateLayoutService $layouts,
        AuditService $audit,
    ): RedirectResponse {
        $this->authorizeConfiguration($request);
        $type = $this->normalizeType($type);
        $this->assertTemplateType($type, $template);
        abort_unless($officeDrafts->isOfficeDraft($template), 404, 'Minor presentation editing is only available for an uploaded DOCX or XLSX Office Draft that has not been activated.');

        $data = $request->validate([
            'edits' => ['array'],
            'edits.*.key' => ['required', 'string', 'max:300'],
            'edits.*.text' => ['nullable', 'string', 'max:500'],
            'edits.*.alignment' => ['nullable', 'string', 'in:left,center,right,justify'],
        ]);

        $before = is_array($template->dynamic_schema) ? count($template->dynamic_schema['minor_edits'] ?? []) : 0;
        $edits = $officeDrafts->validateMinorEdits($template, $data['edits'] ?? []);

        $schema = is_array($template->dynamic_schema) ? $template->dynamic_schema : [];
        $schema['minor_edits'] = $edits;
        $template->update([
            'dynamic_schema' => $schema,
            'configured_by_user_id' => $request->user()->id,
        ]);

        $audit->record('DOCUMENT_TEMPLATE_MINOR_EDIT_SAVED', $template, reason: $template->change_reason, before: [
            'minor_edits_count' => $before,
        ], after: [
            'document_type' => $type,
            'version_label' => $template->version_label,
            'minor_edits_count' => count($edits),
        ]);

        $template = $template->fresh();

        // Re-validate through the exact same Draft-only compiler the sample
        // preview uses, so the edit is confirmed safe and Preview is ready
        // immediately - this never activates anything. The edit above is
        // already saved regardless of what happens here: if no runtime
        // context is available yet to compile a sample against, the save
        // still succeeds and simply asks for a manual Prepare afterward,
        // rather than losing the edit behind a failed request.
        if ($runtimeContexts->forOfficeDraft($template) === null) {
            return $this->redirectToTemplate($type)->with(
                'status',
                "{$template->version_label} presentation text was updated. Prepare it again before previewing."
            );
        }

        return $this->prepareOfficeDraft($request, $type, $template, $layouts, $renderer, $officeDrafts, $runtimeContexts, $audit)
            ->with('status', "{$template->version_label} presentation text was updated. Preview the sample to confirm before activating.");
    }

    public function preview(
        Request $request,
        string $type,
        DocumentTemplate $template,
        DocumentTemplateLayoutService $layouts,
        DocumentTemplateRenderer $renderer,
        OfficialFormRuntimeContextService $runtimeContexts,
        OfficeDraftTemplateRenderer $officeDrafts,
    ): View {
        $this->authorizeConfiguration($request);
        $type = $this->normalizeType($type);
        $this->assertTemplateType($type, $template);
        abort_unless($template->source_mode === 'OFFICIAL_LAYOUT' && $template->file && $template->renderFile, 404, 'A preserved production source is unavailable for this legacy version.');

        $configuration = $layouts->configuration($template);
        $isOfficeDraft = $officeDrafts->isOfficeDraft($template);
        $analysis = $layouts->analysis(
            $type,
            $configuration['mappings'],
            $configuration['analysis']['suggestions'] ?? [],
            $configuration['table_layouts'],
        );
        abort_unless(
            $template->status === 'READY_FOR_PREVIEW'
                && ($isOfficeDraft
                    ? (($configuration['preparation']['state'] ?? null) === 'READY')
                    : $analysis['ready']),
            422,
            'Prepare this uploaded layout automatically before previewing a generated sample.'
        );
        $runtimeContext = $runtimeContexts->forOfficeDraft($template);
        $officeContext = $runtimeContext
            ? $runtimeContexts->withSyntheticDemo($runtimeContext, $renderer->sampleData($type))
            : null;
        $sampleValues = $runtimeContext
            ? $runtimeContexts->mergeWithSyntheticSample($renderer->sampleData($type), $runtimeContext)
            : $layouts->sampleValues($type);

        return view('administration.document-template-preview', [
            'template' => $template,
            'type' => $type,
            'label' => self::TYPES[$type]['name'],
            'format' => $configuration['format'],
            'review' => $configuration['review'],
            'mappings' => $configuration['mappings'],
            'tableLayouts' => $configuration['table_layouts'],
            'analysis' => $analysis,
            'fields' => $layouts->fields($type),
            'sampleValues' => $sampleValues,
            'runtimeContext' => $runtimeContext,
            'renderRoute' => route('administration.document-templates.render', ['type' => $this->routeType($type), 'template' => $template]),
            'sampleRoute' => ($isOfficeDraft || $renderer->canRender($template))
                ? route('administration.document-templates.sample', ['type' => $this->routeType($type), 'template' => $template])
                : null,
            'pageCount' => $isOfficeDraft
                ? (int) ($configuration['preparation']['page_count'] ?? 1)
                : $renderer->pageCount($template),
            'officeContext' => $officeContext,
        ]);
    }

    public function sample(
        Request $request,
        string $type,
        DocumentTemplate $template,
        DocumentTemplateRenderer $renderer,
        DocumentTemplateLayoutService $layouts,
        OfficialFormRuntimeContextService $runtimeContexts,
        OfficeDraftTemplateRenderer $officeDrafts,
    ): StreamedResponse {
        $this->authorizeConfiguration($request);
        $type = $this->normalizeType($type);
        $this->assertTemplateType($type, $template);
        $configuration = $layouts->configuration($template);
        $isOfficeDraft = $officeDrafts->isOfficeDraft($template);
        $analysis = $layouts->analysis($type, $configuration['mappings'], $configuration['analysis']['suggestions'] ?? [], $configuration['table_layouts']);
        abort_unless(
            $template->status === 'READY_FOR_PREVIEW'
                && ($isOfficeDraft
                    ? (($configuration['preparation']['state'] ?? null) === 'READY')
                    : $analysis['ready']),
            422,
            'Prepare this uploaded layout automatically before previewing a generated sample.'
        );
        $runtimeContext = $runtimeContexts->forOfficeDraft($template);
        if ($isOfficeDraft) {
            abort_unless($runtimeContext !== null, 422, 'The Office Draft runtime context is unavailable.');
            $officeContext = $runtimeContexts->withSyntheticDemo($runtimeContext, $renderer->sampleData($type));

            return response()->stream(
                fn () => print $officeDrafts->render($template, $officeContext),
                200,
                ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="template-sample.pdf"', 'Cache-Control' => 'private, no-store, max-age=0']
            );
        }
        $renderData = $runtimeContext
            ? $runtimeContexts->mergeWithSyntheticSample($renderer->sampleData($type), $runtimeContext)
            : $renderer->sampleData($type);

        return response()->stream(
            fn () => print $renderer->render($template, $renderData),
            200,
            ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="template-sample.pdf"', 'Cache-Control' => 'private, no-store, max-age=0']
        );
    }

    public function download(
        Request $request,
        string $type,
        DocumentTemplate $template,
        ProtectedFileService $files
    ): StreamedResponse {
        $this->authorizeConfiguration($request);
        $type = $this->normalizeType($type);
        $this->assertTemplateType($type, $template);
        abort_unless($template->file, 404, 'This template version has no uploaded source.');

        return response()->streamDownload(
            fn () => print $files->bytes($template->file),
            $template->file->original_name,
            ['Content-Type' => $template->file->mime_type ?: 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff']
        );
    }

    public function review(
        Request $request,
        string $type,
        DocumentTemplate $template,
        ProtectedFileService $files
    ): StreamedResponse {
        $this->authorizeConfiguration($request);
        $type = $this->normalizeType($type);
        $this->assertTemplateType($type, $template);
        abort_unless($template->file, 404, 'This template version has no uploaded source.');

        $name = str_replace(['"', "\r", "\n"], '', $template->file->original_name ?: 'official-layout');

        return response()->stream(
            fn () => print $files->bytes($template->file),
            200,
            [
                'Content-Type' => $template->file->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="'.$name.'"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store, max-age=0',
            ]
        );
    }

    public function render(
        Request $request,
        string $type,
        DocumentTemplate $template,
        ProtectedFileService $files
    ): StreamedResponse {
        $this->authorizeConfiguration($request);
        $type = $this->normalizeType($type);
        $this->assertTemplateType($type, $template);
        abort_unless($template->renderFile, 404, 'This template version has no production render representation.');

        return response()->stream(
            fn () => print $files->bytes($template->renderFile),
            200,
            ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="production-layout.pdf"', 'Cache-Control' => 'private, no-store, max-age=0']
        );
    }

    public function activate(
        Request $request,
        string $type,
        DocumentTemplate $template,
        DocumentTemplateRenderer $renderer,
        OfficeDraftTemplateRenderer $officeDrafts,
        OfficialFormRuntimeContextService $runtimeContexts,
        AuditService $audit
    ): RedirectResponse {
        $this->authorizeConfiguration($request);
        $type = $this->normalizeType($type);
        $this->assertTemplateType($type, $template);
        $request->validate(['preview_confirmed' => ['accepted']]);

        if ($officeDrafts->isOfficeFormat($template)) {
            $this->assertOfficeDraftReadyToActivate($template, $type, $officeDrafts, $runtimeContexts, $renderer);
        } else {
            // PDF official layouts are retired: DOCX/XLSX are the only
            // formats storeDraft() accepts going forward, and a PDF draft
            // can no longer reach READY_FOR_PREVIEW (prepare() now refuses
            // it), so this branch only ever sees a pre-retirement PDF row.
            throw ValidationException::withMessages([
                'template' => 'PDF official layouts are retired and can no longer be activated. Upload a DOCX or XLSX layout instead.',
            ]);
        }

        DB::transaction(function () use ($request, $type, $template, $audit): void {
            $active = DocumentTemplate::query()
                ->where('document_type', $type)
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->get();
            $before = $active->map(fn (DocumentTemplate $item) => [
                'id' => $item->id,
                'version' => $item->version_label ?: 'v'.$item->template_version.'.0',
                'source_mode' => $item->source_mode,
            ])->values()->all();

            DocumentTemplate::query()->where('document_type', $type)->where('status', 'ACTIVE')->update([
                'status' => 'HISTORICAL', 'superseded_at' => now(), 'updated_at' => now(),
            ]);
            $template->refresh();
            $template->update([
                'status' => 'ACTIVE',
                'activated_at' => now(),
                'superseded_at' => null,
                'configured_by_user_id' => $request->user()->id,
            ]);

            SystemSetting::query()->where('setting_key', self::TYPES[$type]['setting_key'])->update([
                'value_json' => $template->version_label,
                'updated_by_user_id' => $request->user()->id,
                'updated_at' => now(),
            ]);

            $audit->record('DOCUMENT_TEMPLATE_ACTIVATED', $template, reason: $template->change_reason, before: [
                'active_templates' => $before,
            ], after: [
                'document_type' => $type,
                'version_label' => $template->version_label,
                'source_mode' => 'OFFICIAL_LAYOUT',
                'source_file' => $template->file->original_name,
                'sample_preview_confirmed' => true,
            ]);
        }, 3);

        return $this->redirectToTemplate($type)->with(
            'status',
            "{$template->version_label} is active. It is now the controlled layout for future documents; historical generated documents remain unchanged."
        );
    }

    /**
     * The Office Draft equivalent of the PDF path's pre-activation safety
     * check just above: no BLOCKING compatibility issue, and a live
     * render through the exact same Draft-only compiler Preview already
     * used - the same OfficeDraftTemplateRenderer::render(), the same
     * OfficialFormRuntimeContextService context. Nothing here writes
     * anything; DB::transaction() below is still the only place status
     * actually changes.
     */
    private function assertOfficeDraftReadyToActivate(
        DocumentTemplate $template,
        string $type,
        OfficeDraftTemplateRenderer $officeDrafts,
        OfficialFormRuntimeContextService $runtimeContexts,
        DocumentTemplateRenderer $renderer,
    ): void {
        abort_unless(
            $template->source_mode === 'OFFICIAL_LAYOUT' && $template->file && $template->status === 'READY_FOR_PREVIEW',
            422,
            'This layout cannot be activated because it has not been prepared and previewed successfully.'
        );

        $warnings = is_array($template->dynamic_schema) ? ($template->dynamic_schema['compatibility_warnings'] ?? []) : [];
        $blocking = collect($warnings)->first(
            fn ($warning): bool => is_array($warning) && ($warning['severity'] ?? null) === 'BLOCKING'
        );
        abort_if(
            $blocking !== null,
            422,
            'This layout cannot be activated because it has a blocking compatibility issue: '.($blocking['message'] ?? 'an unresolved issue.')
        );

        $runtimeContext = $runtimeContexts->forOfficeDraft($template);
        abort_unless($runtimeContext !== null, 422, 'This layout cannot be activated because its runtime context is unavailable.');
        $officeContext = $runtimeContexts->withSyntheticDemo($runtimeContext, $renderer->sampleData($type));

        try {
            $officeDrafts->render($template, $officeContext);
        } catch (\Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'template' => 'This layout cannot be activated because a production-ready document could not be generated.',
            ]);
        }
    }

    public function destroyDraft(
        Request $request,
        string $type,
        DocumentTemplate $template,
        AuditService $audit
    ): RedirectResponse {
        $this->authorizeConfiguration($request);
        $type = $this->normalizeType($type);
        $this->assertTemplateType($type, $template);

        $cleanupFileIds = DB::transaction(function () use ($template, $type, $audit): array {
            $draft = DocumentTemplate::query()
                ->with(['file', 'renderFile'])
                ->lockForUpdate()
                ->findOrFail($template->getKey());

            abort_unless($this->isDiscardableDraft($draft), 422, 'Only an unactivated uploaded template draft can be discarded.');

            $sourceFile = $draft->file;
            $templateFiles = collect([$draft->file, $draft->renderFile])
                ->filter()
                ->unique('id')
                ->values();
            $cleanupFileIds = $templateFiles
                ->filter(fn (StoredFile $file): bool => $this->isExclusiveTemplateSource($file, $draft))
                ->pluck('id')
                ->all();
            $versionLabel = $draft->version_label ?: 'v'.$draft->template_version.'.0';

            $audit->record('DOCUMENT_TEMPLATE_DRAFT_DISCARDED', $draft, reason: $draft->change_reason, before: [
                'document_type' => $type,
                'version_label' => $versionLabel,
                'source_file' => $sourceFile?->original_name,
                'status' => $draft->status,
            ], after: [
                'document_type' => $type,
                'version_label' => $versionLabel,
                'source_file_cleanup_eligible' => in_array($sourceFile?->id, $cleanupFileIds, true),
                'render_file_cleanup_eligible' => in_array($draft->renderFile?->id, $cleanupFileIds, true),
            ]);

            $draft->delete();

            return $cleanupFileIds;
        }, 3);

        foreach ($cleanupFileIds as $fileId) {
            try {
                $this->deleteExclusiveTemplateSource($fileId);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        $versionLabel = $template->version_label ?: 'v'.$template->template_version.'.0';

        return $this->redirectToTemplate($type)->with(
            'status',
            self::TYPES[$type]['name']." {$versionLabel} draft was discarded. The current official layout was not changed."
        );
    }

    private function redirectToTemplate(string $type): RedirectResponse
    {
        return redirect()->route('administration.settings.index', [
            'section' => 'template-'.strtolower(str_replace('_', '-', $type)),
        ]);
    }

    private function nextVersion(string $type): int
    {
        return max(1, ((int) DocumentTemplate::query()->where('document_type', $type)->max('template_version')) + 1);
    }

    /**
     * Keep only bounded, non-content diagnostics with the template version.
     * The full Poppler/OCR result is used in-memory to build mappings, but
     * persisting raw PDF strings and every word can exceed this legacy TEXT
     * column and may include document content that configuration screens do
     * not need to display.
     *
     * @param array<string,mixed> $review
     * @return array<string,mixed>
     */
    private function persistedReview(array $review): array
    {
        return [
            'pages' => (int) ($review['pages'] ?? 0),
            'render_pages' => (int) ($review['render_pages'] ?? 0),
            'scanned' => (bool) ($review['scanned'] ?? false),
            'layout_source' => (string) ($review['layout_source'] ?? 'NOT_REQUESTED'),
            'layout_word_count' => is_array($review['layout_words'] ?? null) ? count($review['layout_words']) : 0,
            'layout_line_count' => is_array($review['layout_lines'] ?? null) ? count($review['layout_lines']) : 0,
            'layout_table_count' => is_array($review['layout_model']['tables'] ?? null) ? count($review['layout_model']['tables']) : 0,
            'layout_writable_region_count' => is_array($review['layout_model']['writable_regions'] ?? null) ? count($review['layout_model']['writable_regions']) : 0,
            'fillable_field_count' => is_array($review['fillable_fields'] ?? null) ? count($review['fillable_fields']) : 0,
        ];
    }

    private function normalizedVersionLabel(string $version): string
    {
        $version = strtolower(trim($version));

        return str_starts_with($version, 'v') ? $version : 'v'.$version;
    }

    private function routeType(string $type): string
    {
        return strtolower(str_replace('_', '-', $type));
    }

    private function assertVersionIsAvailable(string $type, string $versionLabel): void
    {
        if (DocumentTemplate::query()->where('document_type', $type)->whereRaw('LOWER(version_label) = ?', [strtolower($versionLabel)])->exists()) {
            throw ValidationException::withMessages(['version_label' => 'That version already exists for this document type. Use a new version label.']);
        }
    }

    private function assertKnownType(string $type): void
    {
        abort_unless(isset(self::TYPES[$type]), 404);
    }

    private function assertTemplateType(string $type, DocumentTemplate $template): void
    {
        abort_unless(isset(self::TYPES[$type]) && $template->document_type === $type, 404);
    }

    private function isDiscardableDraft(DocumentTemplate $template): bool
    {
        return $template->source_mode === 'OFFICIAL_LAYOUT'
            && $template->stored_file_id !== null
            && $template->activated_at === null
            && $template->superseded_at === null
            && ! in_array($template->status, ['ACTIVE', 'HISTORICAL', 'FINALIZED'], true)
            && ! $template->generatedDocuments()->exists();
    }

    private function isExclusiveTemplateSource(StoredFile $file, ?DocumentTemplate $draft = null): bool
    {
        if (! in_array($file->classification, ['DOCUMENT_TEMPLATE_SOURCE', 'DOCUMENT_TEMPLATE_RENDER'], true)) {
            return false;
        }

        $templateReferences = DocumentTemplate::query()->where(function ($query) use ($file): void {
            $query->where('stored_file_id', $file->id)
                ->orWhere('render_stored_file_id', $file->id);
        });
        if ($draft) {
            $templateReferences->whereKeyNot($draft->id);
        }

        return ! $templateReferences->exists()
            && ! GeneratedDocument::query()->where('stored_file_id', $file->id)->exists()
            && ! EvidenceSubmission::query()->where('stored_file_id', $file->id)->exists()
            && ! RequestSupportingDocument::query()->where('stored_file_id', $file->id)->exists()
            && ! UserSignature::query()->where('stored_file_id', $file->id)->exists()
            && ! SignatureSnapshot::query()->where('snapshot_file_id', $file->id)->exists();
    }

    private function deleteExclusiveTemplateSource(int $fileId): void
    {
        DB::transaction(function () use ($fileId): void {
            $file = StoredFile::query()->lockForUpdate()->find($fileId);

            if (! $file || ! $this->isExclusiveTemplateSource($file)) {
                return;
            }

            $disk = Storage::disk($file->disk);
            if ($disk->exists($file->storage_path) && ! $disk->delete($file->storage_path)) {
                return;
            }

            $file->delete();
        }, 3);
    }

    private function authorizeConfiguration(Request $request): void
    {
        abort_unless($request->user()?->access_classification === AccessClassification::SpmuHead, 403, 'Only SPMU Admin/Head may manage controlled document templates.');
    }

    private function normalizeType(string $type): string
    {
        return strtoupper(str_replace('-', '_', trim($type)));
    }
}
