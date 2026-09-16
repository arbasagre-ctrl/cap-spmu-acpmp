<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Models\DocumentTemplate;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\DocumentTemplateLayoutService;
use App\Services\DocumentTemplateRenderer;
use App\Services\OfficeDraftTemplateRenderer;
use App\Services\ProtectedFileService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use ZipArchive;

/**
 * Phase 4 controller wiring: reaching minor-edit through the real routes.
 *
 * These tests avoid anything that would invoke LibreOffice (the immediate
 * re-prepare step after a save), which this test environment does not have
 * installed - the renderer-level OfficeDraftMinorEditTest suite already
 * proves the compile behaviour directly. What is proven here is what the
 * controller and routes are responsible for: access is gated correctly, an
 * Active template can never be reached, and a saved edit lands in this
 * Draft's own schema without disturbing anything else.
 */
class OfficeDraftMinorEditWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $head;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $this->head = User::query()->where('access_classification', AccessClassification::SpmuHead->value)->firstOrFail();
    }

    public function test_minor_edit_is_unavailable_for_the_active_production_template(): void
    {
        $active = DocumentTemplate::query()
            ->where('document_type', 'BORROWER_SLIP')
            ->where('status', 'ACTIVE')
            ->firstOrFail();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->get(route('administration.document-templates.minor-edit', ['type' => 'borrower-slip', 'template' => $active]))
            ->assertNotFound();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->post(route('administration.document-templates.minor-edit.update', ['type' => 'borrower-slip', 'template' => $active]), ['edits' => []])
            ->assertNotFound();
    }

    public function test_minor_edit_is_unavailable_for_a_non_office_draft(): void
    {
        $draft = DocumentTemplate::query()->create([
            'document_type' => 'BORROWER_SLIP',
            'template_version' => 99,
            'version_label' => 'v99.0',
            'template_name' => 'Non-Office Draft',
            'content_template' => json_encode(['format' => 'PDF']),
            'dynamic_schema' => ['source' => ['format' => 'PDF']],
            'stored_file_id' => null,
            'source_mode' => 'OFFICIAL_LAYOUT',
            'status' => 'NEEDS_PREPARATION',
            'configured_by_user_id' => $this->head->id,
        ]);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->get(route('administration.document-templates.minor-edit', ['type' => 'borrower-slip', 'template' => $draft]))
            ->assertNotFound();
    }

    public function test_saving_a_minor_edit_persists_it_on_the_draft_without_touching_the_active_template(): void
    {
        $active = DocumentTemplate::query()
            ->where('document_type', 'BORROWER_SLIP')
            ->where('status', 'ACTIVE')
            ->firstOrFail();
        $activeSourceId = $active->stored_file_id;
        $this->mockDirectPdfRenderRepresentation();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->post(route('administration.document-templates.draft.store', 'borrower-slip'), [
                'version_label' => 'v9.9',
                'reason' => 'Phase 4 minor-edit workflow test.',
                'template_file' => UploadedFile::fake()->createWithContent('borrower-slip-v99.docx', $this->docxBytes()),
            ])
            ->assertRedirect();

        $draft = DocumentTemplate::query()->where('document_type', 'BORROWER_SLIP')->where('version_label', 'v9.9')->firstOrFail();
        $draftSourceId = $draft->stored_file_id;

        $page = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->get(route('administration.document-templates.minor-edit', ['type' => 'borrower-slip', 'template' => $draft]));

        $page->assertOk();
        $page->assertSee('Borrower Slip', false);
        // Non-technical: no raw tag, XML part, or content-control identity leaks into the form.
        $page->assertDontSee('word/document.xml', false);
        $page->assertDontSee('borrower.full_name', false);
        $page->assertDontSee('<w:', false);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->post(route('administration.document-templates.minor-edit.update', ['type' => 'borrower-slip', 'template' => $draft]), [
                'edits' => [
                    ['key' => '0', 'text' => 'Updated Heading Text', 'alignment' => 'center'],
                ],
            ])
            ->assertRedirect();

        $draft->refresh();
        $this->assertSame('Updated Heading Text', $draft->dynamic_schema['minor_edits'][0]['text']);
        $this->assertSame('center', $draft->dynamic_schema['minor_edits'][0]['alignment']);
        // The Draft's own uploaded source reference is untouched by the edit.
        $this->assertSame($draftSourceId, $draft->stored_file_id);

        // The Active production template was never read or written by this flow.
        $active->refresh();
        $this->assertSame('ACTIVE', $active->status);
        $this->assertSame($activeSourceId, $active->stored_file_id);
    }

    public function test_saving_a_minor_edit_with_an_unsafe_key_is_rejected_and_persists_nothing(): void
    {
        $this->mockDirectPdfRenderRepresentation();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->post(route('administration.document-templates.draft.store', 'borrower-slip'), [
                'version_label' => 'v9.8',
                'reason' => 'Phase 4 minor-edit rejection test.',
                'template_file' => UploadedFile::fake()->createWithContent('borrower-slip-v98.docx', $this->docxBytes()),
            ])
            ->assertRedirect();

        $draft = DocumentTemplate::query()->where('document_type', 'BORROWER_SLIP')->where('version_label', 'v9.8')->firstOrFail();

        // Only index "0" is a safe editable section for this fixture; the
        // borrower.full_name content control never enters the sequence.
        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->post(route('administration.document-templates.minor-edit.update', ['type' => 'borrower-slip', 'template' => $draft]), [
                'edits' => [
                    ['key' => '1', 'text' => 'Hijacked', 'alignment' => null],
                ],
            ])
            ->assertSessionHasErrors('edits');

        $draft->refresh();
        $this->assertArrayNotHasKey('minor_edits', $draft->dynamic_schema);
    }

    public function test_editing_the_active_template_creates_a_new_draft(): void
    {
        $active = $this->activeDocxTemplate();

        $response = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->get(route('administration.document-templates.minor-edit', ['type' => 'borrower-slip', 'template' => $active]));

        $response->assertRedirect();
        $this->assertNotSame(
            route('administration.document-templates.minor-edit', ['type' => 'borrower-slip', 'template' => $active]),
            $response->headers->get('Location')
        );

        $draft = DocumentTemplate::query()
            ->where('document_type', 'BORROWER_SLIP')
            ->where('id', '!=', $active->id)
            ->whereIn('status', ['NEEDS_PREPARATION', 'READY_FOR_PREVIEW'])
            ->firstOrFail();

        $this->assertNotSame('ACTIVE', $draft->status);
        $this->assertSame($active->id, $draft->dynamic_schema['minor_edit_source_id']);
        $response->assertRedirect(route('administration.document-templates.minor-edit', ['type' => 'borrower-slip', 'template' => $draft]));
    }

    public function test_the_active_template_remains_unchanged_after_editing_creates_a_draft(): void
    {
        $active = $this->activeDocxTemplate();
        $activeAttributes = $active->only(['status', 'stored_file_id', 'render_stored_file_id', 'version_label', 'template_version']);
        $activeSchema = $active->dynamic_schema;

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->get(route('administration.document-templates.minor-edit', ['type' => 'borrower-slip', 'template' => $active]));

        $active->refresh();
        $this->assertSame($activeAttributes, $active->only(['status', 'stored_file_id', 'render_stored_file_id', 'version_label', 'template_version']));
        $this->assertSame($activeSchema, $active->dynamic_schema);
        $this->assertArrayNotHasKey('minor_edits', $active->dynamic_schema);
    }

    public function test_the_draft_inherits_the_active_templates_source_and_structure_safely(): void
    {
        $active = $this->activeDocxTemplate();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->get(route('administration.document-templates.minor-edit', ['type' => 'borrower-slip', 'template' => $active]));

        $draft = DocumentTemplate::query()->where('document_type', 'BORROWER_SLIP')->where('id', '!=', $active->id)->whereIn('status', ['NEEDS_PREPARATION', 'READY_FOR_PREVIEW'])->firstOrFail();

        // The exact same immutable stored file - not a duplicated copy.
        $this->assertSame($active->stored_file_id, $draft->stored_file_id);
        // The same validated Office identity and document structure carry over untouched.
        $this->assertSame($active->dynamic_schema['office_identity'], $draft->dynamic_schema['office_identity']);
        $this->assertSame($active->dynamic_schema['document_structure'], $draft->dynamic_schema['document_structure']);
        $this->assertSame([], $draft->dynamic_schema['minor_edits']);
        $this->assertSame('READY_FOR_PREVIEW', $draft->status);
    }

    public function test_editing_the_active_template_twice_reuses_the_same_draft(): void
    {
        $active = $this->activeDocxTemplate();

        $first = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->get(route('administration.document-templates.minor-edit', ['type' => 'borrower-slip', 'template' => $active]));

        $second = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->get(route('administration.document-templates.minor-edit', ['type' => 'borrower-slip', 'template' => $active]));

        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'));
        $this->assertSame(
            1,
            DocumentTemplate::query()->where('document_type', 'BORROWER_SLIP')->where('id', '!=', $active->id)->whereIn('status', ['NEEDS_PREPARATION', 'READY_FOR_PREVIEW'])->count()
        );
    }

    public function test_saving_multiple_minor_edits_updates_the_same_draft_not_the_active_row(): void
    {
        $active = $this->activeDocxTemplate();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->get(route('administration.document-templates.minor-edit', ['type' => 'borrower-slip', 'template' => $active]));

        $draft = DocumentTemplate::query()->where('document_type', 'BORROWER_SLIP')->where('id', '!=', $active->id)->whereIn('status', ['NEEDS_PREPARATION', 'READY_FOR_PREVIEW'])->firstOrFail();
        $draftId = $draft->id;

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->post(route('administration.document-templates.minor-edit.update', ['type' => 'borrower-slip', 'template' => $draft]), [
                'edits' => [['key' => '0', 'text' => 'First Edit', 'alignment' => 'left']],
            ])
            ->assertRedirect();

        $draft->refresh();
        $this->assertSame('First Edit', $draft->dynamic_schema['minor_edits'][0]['text']);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->post(route('administration.document-templates.minor-edit.update', ['type' => 'borrower-slip', 'template' => $draft]), [
                'edits' => [['key' => '0', 'text' => 'Second Edit', 'alignment' => 'right']],
            ])
            ->assertRedirect();

        $draft->refresh();
        $this->assertSame($draftId, $draft->id);
        $this->assertSame('Second Edit', $draft->dynamic_schema['minor_edits'][0]['text']);
        $this->assertSame('right', $draft->dynamic_schema['minor_edits'][0]['alignment']);
        $this->assertSame(
            1,
            DocumentTemplate::query()->where('document_type', 'BORROWER_SLIP')->where('id', '!=', $active->id)->whereIn('status', ['NEEDS_PREPARATION', 'READY_FOR_PREVIEW'])->count()
        );

        $active->refresh();
        $this->assertSame('ACTIVE', $active->status);
        $this->assertArrayNotHasKey('minor_edits', $active->dynamic_schema);
    }

    public function test_preview_compiles_with_the_drafts_saved_edits(): void
    {
        $active = $this->activeDocxTemplate();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->get(route('administration.document-templates.minor-edit', ['type' => 'borrower-slip', 'template' => $active]));

        $draft = DocumentTemplate::query()->where('document_type', 'BORROWER_SLIP')->where('id', '!=', $active->id)->whereIn('status', ['NEEDS_PREPARATION', 'READY_FOR_PREVIEW'])->firstOrFail();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->post(route('administration.document-templates.minor-edit.update', ['type' => 'borrower-slip', 'template' => $draft]), [
                'edits' => [['key' => '0', 'text' => 'Previewed Heading', 'alignment' => null]],
            ])
            ->assertRedirect();

        $draft->refresh();

        // Preview and the generated sample both compile through this exact
        // seam (OfficeDraftTemplateRenderer::render() -> compile() ->
        // compileWorkingSource()); LibreOffice's own PDF conversion is not
        // available in this test image, so the compiled OOXML - the part
        // Preview actually depends on for its content - is verified directly
        // against this Draft's own persisted source and schema. The save
        // above already re-ran the real Draft-only compile as part of
        // updateMinorPresentation()'s auto re-prepare step; since that step
        // could not finish converting to PDF without LibreOffice here, it
        // recorded its own BLOCKING "render failed" compatibility warning on
        // the schema - an environment artifact, not a document problem - so
        // it is cleared for this check, which is specifically about whether
        // the saved minor_edits compile into the working source correctly.
        $schema = $draft->dynamic_schema;
        $schema['compatibility_warnings'] = [];
        $officeDrafts = app(OfficeDraftTemplateRenderer::class);
        $compiled = $officeDrafts->compileWorkingSource(
            app(ProtectedFileService::class)->bytes($draft->file),
            $schema,
            ['namespaces' => [], 'available_paths' => []],
        );

        $path = tempnam(sys_get_temp_dir(), 'spmu-preview-check-');
        file_put_contents($path, $compiled['bytes']);
        $zip = new ZipArchive;
        $zip->open($path);
        $document = $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($path);

        $this->assertStringContainsString('Previewed Heading', $document);
    }

    public function test_creating_and_editing_the_draft_never_switches_the_production_template(): void
    {
        $active = $this->activeDocxTemplate();
        $settingBefore = SystemSetting::query()->where('setting_key', 'borrower_slip_template_version')->first()?->value_json;

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->get(route('administration.document-templates.minor-edit', ['type' => 'borrower-slip', 'template' => $active]));

        $draft = DocumentTemplate::query()->where('document_type', 'BORROWER_SLIP')->where('id', '!=', $active->id)->whereIn('status', ['NEEDS_PREPARATION', 'READY_FOR_PREVIEW'])->firstOrFail();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->post(route('administration.document-templates.minor-edit.update', ['type' => 'borrower-slip', 'template' => $draft]), [
                'edits' => [['key' => '0', 'text' => 'No Switch', 'alignment' => null]],
            ]);

        $settingAfter = SystemSetting::query()->where('setting_key', 'borrower_slip_template_version')->first()?->value_json;
        $this->assertSame($settingBefore, $settingAfter);

        // Exactly one row is ACTIVE for this document type, and it is still
        // the one this whole test started from - nothing was promoted.
        $activeRows = DocumentTemplate::query()->where('document_type', 'BORROWER_SLIP')->where('status', 'ACTIVE')->pluck('id');
        $this->assertSame([$active->id], $activeRows->all());
        $this->assertNull($draft->fresh()->activated_at);
    }

    /**
     * A real Active DOCX layout, built through the same layout service and
     * file storage the live upload path uses, so the "Edit Template on
     * Active" flow is exercised against an authentic schema rather than a
     * hand-written stand-in. The built-in default the seeder installs for
     * BORROWER_SLIP has no source file at all, so it is superseded here to
     * keep exactly one Active row for the type, the same invariant a real
     * activation already maintains.
     */
    private function activeDocxTemplate(): DocumentTemplate
    {
        DocumentTemplate::query()
            ->where('document_type', 'BORROWER_SLIP')
            ->where('status', 'ACTIVE')
            ->update(['status' => 'HISTORICAL']);

        $upload = UploadedFile::fake()->createWithContent('active-borrower-slip.docx', $this->docxBytes());
        $layouts = app(DocumentTemplateLayoutService::class);
        $inspection = $layouts->inspectUpload($upload);
        $storedFile = app(ProtectedFileService::class)->storeUpload($upload, 'document-templates/borrower-slip', 'DOCUMENT_TEMPLATE_SOURCE');
        $schema = $layouts->schemaForStoredSource('DOCX', $inspection['review'], $storedFile->sha256, $storedFile->id);

        return DocumentTemplate::query()->create([
            'document_type' => 'BORROWER_SLIP',
            'template_version' => 500,
            'version_label' => 'v500.0',
            'template_name' => "Borrower's Slip v500.0",
            'content_template' => json_encode([
                'format' => 'DOCX',
                'render_representation' => 'NORMALIZED_PDF',
                'review' => [],
                'mappings' => [],
                'table_layouts' => [],
                'analysis' => ['ready' => true],
                'preparation' => ['state' => 'READY', 'compiler' => 'OFFICE_DRAFT', 'page_count' => 1],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'dynamic_schema' => $schema,
            'stored_file_id' => $storedFile->id,
            'render_stored_file_id' => $storedFile->id,
            'source_mode' => 'OFFICIAL_LAYOUT',
            'change_reason' => 'Test fixture: active DOCX template.',
            'status' => 'ACTIVE',
            'configured_by_user_id' => $this->head->id,
            'activated_at' => now(),
            'superseded_at' => null,
        ]);
    }

    /**
     * storeDraft() always calls DocumentTemplateRenderer::renderRepresentation()
     * to build the review PDF representation, which would otherwise invoke
     * LibreOffice - unavailable in this test image, exactly as
     * DynamicOfficeTemplateRuntimeTest already establishes for the same
     * upload endpoint.
     */
    private function mockDirectPdfRenderRepresentation(): void
    {
        $renderer = Mockery::mock(DocumentTemplateRenderer::class);
        $renderer->shouldReceive('renderRepresentation')
            ->once()
            ->andReturn(['bytes' => "%PDF-1.4\nphase-four\n", 'representation' => 'NORMALIZED_PDF']);
        // The minor-edit save path re-prepares the Draft, which builds a
        // synthetic sample context the same way the normal Prepare step does.
        $renderer->shouldReceive('sampleData')->andReturn([]);
        $this->app->instance(DocumentTemplateRenderer::class, $renderer);
    }

    private function docxBytes(): string
    {
        return $this->zip([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
            'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
                .'<w:p><w:r><w:t>Borrower Slip</w:t></w:r></w:p>'
                .'<w:sdt><w:sdtPr><w:id w:val="1"/><w:tag w:val="borrower.full_name"/></w:sdtPr><w:sdtContent><w:p><w:r><w:t>SOURCE BORROWER</w:t></w:r></w:p></w:sdtContent></w:sdt>'
                .'<w:sectPr/></w:body></w:document>',
        ]);
    }

    /** @param array<string,string> $files */
    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'spmu-office-minor-edit-workflow-');
        if ($path === false) {
            self::fail('Could not create Office test archive.');
        }
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::OVERWRITE) === true);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        $bytes = file_get_contents($path);
        @unlink($path);
        $this->assertIsString($bytes);

        return $bytes;
    }
}
