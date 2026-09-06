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
        $renderRepresentation = $renderer->renderRepresentation($request->file('template_file'), $inspection['format']);
        $initialAnalysis = $layouts->analysis($type, []);
        $storedFile = $files->storeUpload(
            $request->file('template_file'),
            'document-templates/'.strtolower($type),
            'DOCUMENT_TEMPLATE_SOURCE'
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

        $template = DB::transaction(function () use ($request, $type, $versionLabel, $data, $inspection, $initialAnalysis, $renderRepresentation, $storedFile, $renderFile, $audit): DocumentTemplate {
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
        AuditService $audit
    ): RedirectResponse {
        $this->authorizeConfiguration($request);
        $type = $this->normalizeType($type);
        $this->assertTemplateType($type, $template);
        abort_unless($template->source_mode === 'OFFICIAL_LAYOUT' && $template->file && $template->renderFile, 422, 'Only an uploaded draft can be prepared.');
        abort_if(in_array($template->status, ['ACTIVE', 'HISTORICAL', 'FINALIZED'], true), 422, 'An active or historical layout cannot be prepared again. Register a new version instead.');

        $configuration = $layouts->configuration($template);
        $sourceInspection = $layouts->inspectStoredSource(
            $files->bytes($template->file),
            $template->file->original_name,
            $template->file->mime_type,
            $configuration['format'],
            false,
        );
        $format = $sourceInspection['format'];
        $review = $sourceInspection['review'];
        // The preserved upload establishes the source type, while analysis
        // always runs on the exact PDF representation that production FPDI
        // imports. This keeps normalized, flat, and office-converted layouts
        // on one deterministic analysis/rendering path.
        $productionReview = $layouts->inspectProductionPdf($files->bytes($template->renderFile), true);
        $review = [
            ...$review,
            'render_pages' => $productionReview['pages'],
            'fillable_fields' => $productionReview['fillable_fields'],
            'fillable_widgets' => $productionReview['fillable_widgets'],
            'layout_source' => $productionReview['layout_source'],
            'layout_words' => $productionReview['layout_words'],
            'layout_lines' => $productionReview['layout_lines'],
        ];
        $automatic = $layouts->autoMap($type, $format, $review);
        $analysis = $automatic['analysis'];
        $storedReview = $this->persistedReview($review);
        $preparation = $analysis['ready']
            ? ['state' => 'READY']
            : ['state' => 'FAILED', 'message' => $layouts->preparationFeedback($format, $review, $analysis)];

        $before = $template->content_template;
        $template->update([
            'content_template' => json_encode([
                'format' => $format,
                'render_representation' => $configuration['render_representation'],
                'review' => $storedReview,
                'mappings' => $automatic['mappings'],
                'table_layouts' => $automatic['table_layouts'],
                'analysis' => $analysis,
                'preparation' => $preparation,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'status' => $analysis['ready'] ? 'READY_FOR_PREVIEW' : 'NEEDS_PREPARATION',
            'configured_by_user_id' => $request->user()->id,
        ]);
        $template->refresh();

        if ($analysis['ready']) {
            try {
                $renderer->render($template, $renderer->sampleData($type));
            } catch (\Throwable $exception) {
                report($exception);
                $preparation = ['state' => 'FAILED', 'message' => 'The generated sample could not be validated for this layout.'];
                $template->update([
                    'content_template' => json_encode([
                        'format' => $format,
                        'render_representation' => $configuration['render_representation'],
                        'review' => $storedReview,
                        'mappings' => $automatic['mappings'],
                        'table_layouts' => $automatic['table_layouts'],
                        'analysis' => $analysis,
                        'preparation' => $preparation,
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                    'status' => 'NEEDS_PREPARATION',
                ]);
            }
        }

        $audit->record('DOCUMENT_TEMPLATE_LAYOUT_PREPARED', $template, reason: $template->change_reason, before: [
            'configuration' => $before,
        ], after: [
            'format' => $format,
            'preparation' => $preparation,
            'analysis' => $analysis,
        ]);

        $redirect = $this->redirectToTemplate($type);
        if ($template->fresh()->status !== 'READY_FOR_PREVIEW') {
            // The draft card presents the single neutral failure state. Do
            // not add a green global success banner for a failed preparation.
            return $redirect;
        }

        return $redirect->with('status', "{$template->version_label} was prepared automatically and is ready for sample preview.");
    }

    public function preview(
        Request $request,
        string $type,
        DocumentTemplate $template,
        DocumentTemplateLayoutService $layouts,
        DocumentTemplateRenderer $renderer
    ): View {
        $this->authorizeConfiguration($request);
        $type = $this->normalizeType($type);
        $this->assertTemplateType($type, $template);
        abort_unless($template->source_mode === 'OFFICIAL_LAYOUT' && $template->file && $template->renderFile, 404, 'A preserved production source is unavailable for this legacy version.');

        $configuration = $layouts->configuration($template);
        $analysis = $layouts->analysis(
            $type,
            $configuration['mappings'],
            $configuration['analysis']['suggestions'] ?? [],
            $configuration['table_layouts'],
        );
        abort_unless($analysis['ready'] && $template->status === 'READY_FOR_PREVIEW', 422, 'Prepare this uploaded layout automatically before previewing a generated sample.');

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
            'sampleValues' => $layouts->sampleValues($type),
            'renderRoute' => route('administration.document-templates.render', ['type' => $this->routeType($type), 'template' => $template]),
            'sampleRoute' => $renderer->canRender($template)
                ? route('administration.document-templates.sample', ['type' => $this->routeType($type), 'template' => $template])
                : null,
            'pageCount' => $renderer->pageCount($template),
        ]);
    }

    public function sample(
        Request $request,
        string $type,
        DocumentTemplate $template,
        DocumentTemplateRenderer $renderer,
        DocumentTemplateLayoutService $layouts,
    ): StreamedResponse {
        $this->authorizeConfiguration($request);
        $type = $this->normalizeType($type);
        $this->assertTemplateType($type, $template);
        $configuration = $layouts->configuration($template);
        $analysis = $layouts->analysis($type, $configuration['mappings'], $configuration['analysis']['suggestions'] ?? [], $configuration['table_layouts']);
        abort_unless($template->status === 'READY_FOR_PREVIEW' && $analysis['ready'], 422, 'Prepare this uploaded layout automatically before previewing a generated sample.');

        return response()->stream(
            fn () => print $renderer->render($template, $renderer->sampleData($type)),
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
        DocumentTemplateLayoutService $layouts,
        DocumentTemplateRenderer $renderer,
        AuditService $audit
    ): RedirectResponse {
        $this->authorizeConfiguration($request);
        $type = $this->normalizeType($type);
        $this->assertTemplateType($type, $template);
        $request->validate(['preview_confirmed' => ['accepted']]);
        $configuration = $layouts->configuration($template);
        $analysis = $layouts->analysis(
            $type,
            $configuration['mappings'],
            $configuration['analysis']['suggestions'] ?? [],
            $configuration['table_layouts'],
        );
        abort_unless(
            $template->source_mode === 'OFFICIAL_LAYOUT' && $template->file && $template->renderFile && $template->status === 'READY_FOR_PREVIEW' && $analysis['ready'] && $renderer->canRender($template),
            422,
            'This layout cannot be activated because a production-ready document could not be generated.'
        );

        try {
            $renderer->render($template, $renderer->sampleData($type));
        } catch (\Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'template' => 'This layout cannot be activated because a production-ready document could not be generated.',
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
