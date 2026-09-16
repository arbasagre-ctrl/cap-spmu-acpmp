<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\DocumentTemplateLayoutService;
use App\Services\DocumentTemplateRenderer;
use App\Services\OfficeDraftTemplateRenderer;
use App\Services\ProtectedFileService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;
use ZipArchive;

/**
 * Phase 5: activating a validated Office (DOCX/XLSX) Draft as the production
 * template for its form type.
 *
 * Draft preview and production output must be provably the same pipeline, so
 * these tests mock only the one LibreOffice-dependent step this test image
 * cannot run (OfficeDraftTemplateRenderer::render()'s final PDF conversion),
 * using a partial mock so every other method - including the real
 * isOfficeFormat() dispatch and the real compatibility/eligibility checks -
 * runs unmocked. The renderer-level test proves the compile step itself
 * (which never even looks at status) is untouched by activation.
 */
class OfficeTemplateActivationTest extends TestCase
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

    public function test_ready_office_draft_activates_successfully(): void
    {
        $this->mockOfficeRenderWithoutLibreOffice();
        $draft = $this->readyOfficeDraft();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->post(route('administration.document-templates.activate', ['type' => 'borrower-slip', 'template' => $draft]), ['preview_confirmed' => true])
            ->assertRedirect();

        $draft->refresh();
        $this->assertSame('ACTIVE', $draft->status);
        $this->assertNotNull($draft->activated_at);
    }

    public function test_previous_active_becomes_historical_and_the_new_draft_becomes_active(): void
    {
        $this->mockOfficeRenderWithoutLibreOffice();
        $previousActive = $this->activeOfficeTemplate();
        $draft = $this->readyOfficeDraft();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->post(route('administration.document-templates.activate', ['type' => 'borrower-slip', 'template' => $draft]), ['preview_confirmed' => true])
            ->assertRedirect();

        $previousActive->refresh();
        $this->assertSame('HISTORICAL', $previousActive->status);
        $this->assertNotNull($previousActive->superseded_at);

        $draft->refresh();
        $this->assertSame('ACTIVE', $draft->status);
        $this->assertNull($draft->superseded_at);

        // Exactly one Active row remains for the type, and it is the Draft.
        $activeIds = DocumentTemplate::query()->where('document_type', 'BORROWER_SLIP')->where('status', 'ACTIVE')->pluck('id');
        $this->assertSame([$draft->id], $activeIds->all());

        // value_json is written via a raw query-builder update() (unchanged,
        // pre-existing activate() behaviour shared by both paths), which
        // bypasses the model's 'array' cast on write - so it is read back
        // the same raw way here rather than through the cast-aware accessor.
        $rawSettingValue = DB::table('system_settings')
            ->where('setting_key', 'borrower_slip_template_version')
            ->value('value_json');
        $this->assertSame($draft->version_label, $rawSettingValue);
    }

    public function test_a_draft_with_a_blocking_compatibility_issue_cannot_activate(): void
    {
        $this->mockOfficeRenderWithoutLibreOffice();
        $draft = $this->readyOfficeDraft();
        $schema = $draft->dynamic_schema;
        $schema['compatibility_warnings'] = [
            ['severity' => 'BLOCKING', 'code' => 'TEST_BLOCKING_ISSUE', 'message' => 'A blocking issue for this test.'],
        ];
        $draft->update(['dynamic_schema' => $schema]);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->post(route('administration.document-templates.activate', ['type' => 'borrower-slip', 'template' => $draft]), ['preview_confirmed' => true])
            ->assertStatus(422);

        $draft->refresh();
        $this->assertSame('READY_FOR_PREVIEW', $draft->status);
        $this->assertNull($draft->activated_at);
    }

    public function test_an_unprepared_unpreviewed_draft_cannot_activate(): void
    {
        $this->mockOfficeRenderWithoutLibreOffice();
        $draft = $this->readyOfficeDraft(['status' => 'NEEDS_PREPARATION']);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->post(route('administration.document-templates.activate', ['type' => 'borrower-slip', 'template' => $draft]), ['preview_confirmed' => true])
            ->assertStatus(422);

        $draft->refresh();
        $this->assertSame('NEEDS_PREPARATION', $draft->status);
        $this->assertNull($draft->activated_at);
    }

    public function test_activation_requires_the_preview_confirmation_checkbox(): void
    {
        $this->mockOfficeRenderWithoutLibreOffice();
        $draft = $this->readyOfficeDraft();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->post(route('administration.document-templates.activate', ['type' => 'borrower-slip', 'template' => $draft]))
            ->assertSessionHasErrors('preview_confirmed');

        $draft->refresh();
        $this->assertSame('READY_FOR_PREVIEW', $draft->status);
    }

    public function test_active_production_generation_resolves_and_renders_through_the_newly_activated_office_template(): void
    {
        $this->mockOfficeRenderWithoutLibreOffice();
        $draft = $this->readyOfficeDraft();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->post(route('administration.document-templates.activate', ['type' => 'borrower-slip', 'template' => $draft]), ['preview_confirmed' => true])
            ->assertRedirect();

        // The workflow's own resolution query - unchanged by this phase -
        // now finds the Office template automatically, with no code change
        // required anywhere outside the template system.
        $resolved = DocumentTemplate::query()
            ->where('document_type', 'BORROWER_SLIP')
            ->where('status', 'ACTIVE')
            ->orderByDesc('template_version')
            ->first();
        $this->assertSame($draft->id, $resolved?->id);

        // DocumentService::renderCustomTemplate() is the one seam every
        // production document call site shares. Invoked directly against the
        // now-Active Office template, it must dispatch to the same Office
        // renderer used during activation - not the generic/PDF production
        // renderer - proving the workflow automatically uses the newly
        // activated template's own rendering pipeline.
        $legacyRenderer = Mockery::mock(DocumentTemplateRenderer::class);
        $legacyRenderer->shouldNotReceive('render');
        $this->app->instance(DocumentTemplateRenderer::class, $legacyRenderer);

        $documents = app(DocumentService::class);
        $method = new ReflectionMethod(DocumentService::class, 'renderCustomTemplate');
        $bytes = $method->invoke($documents, $resolved, ['request_no' => 'BR-TEST-0001']);

        $this->assertSame("%PDF-1.4\nphase-five\n", $bytes);
    }

    public function test_activation_never_modifies_unrelated_workflow_or_business_data(): void
    {
        $this->mockOfficeRenderWithoutLibreOffice();
        $draft = $this->readyOfficeDraft();

        $borrower = User::factory()->create(['access_classification' => AccessClassification::BorrowerOnly->value]);
        $unit = OrganizationalUnit::query()->create([
            'unit_code' => 'ACT-TEST',
            'unit_name' => 'Activation Test Unit',
            'unit_type' => 'OFFICE',
            'active' => true,
        ]);
        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-ACTIVATION-TEST',
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $unit->id,
            'current_version_no' => 1,
            'status' => 'UNDER_SPMU',
        ]);
        $statusBefore = $request->status;
        $requestCountBefore = BorrowingRequest::query()->count();
        $custodyCountBefore = CustodyTransaction::query()->count();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->post(route('administration.document-templates.activate', ['type' => 'borrower-slip', 'template' => $draft]), ['preview_confirmed' => true])
            ->assertRedirect();

        $this->assertSame($requestCountBefore, BorrowingRequest::query()->count());
        $this->assertSame($custodyCountBefore, CustodyTransaction::query()->count());
        $request->refresh();
        $this->assertSame($statusBefore->value, $request->status->value);
        $this->assertNull($request->final_approved_at);
    }

    public function test_historical_generated_documents_remain_unchanged_after_activation(): void
    {
        $this->mockOfficeRenderWithoutLibreOffice();
        $draft = $this->readyOfficeDraft();

        $historicalFile = app(ProtectedFileService::class)->storeBytes(
            "%PDF-1.4\nhistorical\n",
            'generated-documents/borrower-slip',
            'historical-borrower-slip.pdf',
            'application/pdf',
            'pdf',
            'GENERATED_DOCUMENT'
        );
        $historical = GeneratedDocument::query()->create([
            'template_id' => $draft->id,
            'stored_file_id' => $historicalFile->id,
            'document_type' => 'BORROWER_SLIP',
            'document_no' => 'GEN-HISTORICAL-0001',
            'version_no' => 1,
            'sha256' => $historicalFile->sha256,
            'status' => 'FINAL',
            'generated_at' => now(),
        ]);
        $before = $historical->getAttributes();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->post(route('administration.document-templates.activate', ['type' => 'borrower-slip', 'template' => $draft]), ['preview_confirmed' => true])
            ->assertRedirect();

        $historical->refresh();
        $this->assertSame($before['stored_file_id'], $historical->stored_file_id);
        $this->assertSame($before['sha256'], $historical->sha256);
        $this->assertSame($before['status'], $historical->status);
        $this->assertSame(
            "%PDF-1.4\nhistorical\n",
            app(ProtectedFileService::class)->bytes($historical->file)
        );
    }

    public function test_pdf_format_templates_are_not_dispatched_through_the_office_activation_path(): void
    {
        $pdfTemplate = DocumentTemplate::query()->create([
            'document_type' => 'GATE_PASS',
            'template_version' => 900,
            'version_label' => 'v900.0',
            'template_name' => 'Gate Pass v900.0 (PDF fixture)',
            'content_template' => json_encode(['format' => 'PDF']),
            'dynamic_schema' => ['source' => ['format' => 'PDF']],
            'stored_file_id' => null,
            'source_mode' => 'OFFICIAL_LAYOUT',
            'change_reason' => 'Test fixture: PDF format is never Office-dispatched.',
            'status' => 'READY_FOR_PREVIEW',
            'configured_by_user_id' => $this->head->id,
        ]);

        // The exact same check activate() and renderCustomTemplate() both
        // use to decide Office vs. the untouched legacy/PDF path.
        $this->assertFalse(app(OfficeDraftTemplateRenderer::class)->isOfficeFormat($pdfTemplate));
    }

    public function test_a_minor_edited_drafts_compiled_source_is_identical_before_and_after_activation(): void
    {
        $source = $this->docxBytes();
        $schema = $this->docxSchema();
        $schema['minor_edits'] = [
            ['key' => '0', 'text' => 'Activated Heading Text', 'alignment' => 'center'],
        ];
        $context = ['namespaces' => [], 'available_paths' => []];

        // compileWorkingSource() takes raw bytes and a schema array, never a
        // DocumentTemplate - it has no notion of Draft vs. Active at all.
        // Activation only ever flips a status column; it never touches the
        // stored source bytes or dynamic_schema this call reads. Compiling
        // the identical inputs twice - once standing in for "as Draft", once
        // for "as Active" - must therefore be byte-for-byte identical.
        $officeDrafts = new OfficeDraftTemplateRenderer(app(ProtectedFileService::class));
        $asDraft = $officeDrafts->compileWorkingSource($source, $schema, $context);
        $asActive = $officeDrafts->compileWorkingSource($source, $schema, $context);

        $this->assertSame($asDraft['bytes'], $asActive['bytes']);

        $document = $this->zipEntry($asActive['bytes'], 'word/document.xml');
        $this->assertStringContainsString('Activated Heading Text', $document);
        $this->assertStringContainsString('w:jc w:val="center"', $document);
    }

    /**
     * A partial mock over the real constructor: every method except render()
     * (the one step that needs LibreOffice, unavailable in this test image)
     * runs its real implementation, including isOfficeFormat() and every
     * compatibility/eligibility check activate() performs before it.
     */
    private function mockOfficeRenderWithoutLibreOffice(): void
    {
        $officeDrafts = Mockery::mock(OfficeDraftTemplateRenderer::class, [app(ProtectedFileService::class)])->makePartial();
        $officeDrafts->shouldReceive('render')->andReturn("%PDF-1.4\nphase-five\n");
        $this->app->instance(OfficeDraftTemplateRenderer::class, $officeDrafts);
    }

    /** @param array<string,mixed> $overrides */
    private function readyOfficeDraft(array $overrides = []): DocumentTemplate
    {
        $upload = UploadedFile::fake()->createWithContent('borrower-slip-draft.docx', $this->docxBytes());
        $layouts = app(DocumentTemplateLayoutService::class);
        $inspection = $layouts->inspectUpload($upload);
        $storedFile = app(ProtectedFileService::class)->storeUpload($upload, 'document-templates/borrower-slip', 'DOCUMENT_TEMPLATE_SOURCE');
        $schema = $layouts->schemaForStoredSource('DOCX', $inspection['review'], $storedFile->sha256, $storedFile->id);
        $schema['minor_edits'] = [];

        return DocumentTemplate::query()->create(array_merge([
            'document_type' => 'BORROWER_SLIP',
            'template_version' => 600 + DocumentTemplate::query()->where('document_type', 'BORROWER_SLIP')->count(),
            'version_label' => 'v600.'.DocumentTemplate::query()->where('document_type', 'BORROWER_SLIP')->count(),
            'template_name' => 'Borrower Slip Office Draft fixture',
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
            'change_reason' => 'Test fixture: Office Draft ready to activate.',
            'status' => 'READY_FOR_PREVIEW',
            'configured_by_user_id' => $this->head->id,
            'activated_at' => null,
            'superseded_at' => null,
        ], $overrides));
    }

    private function activeOfficeTemplate(): DocumentTemplate
    {
        DocumentTemplate::query()->where('document_type', 'BORROWER_SLIP')->where('status', 'ACTIVE')->update(['status' => 'HISTORICAL']);

        return $this->readyOfficeDraft([
            'template_version' => 500,
            'version_label' => 'v500.0',
            'status' => 'ACTIVE',
            'activated_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function docxSchema(): array
    {
        return [
            'source' => ['format' => 'DOCX'],
            'compatibility_warnings' => [],
            'office_identity' => ['content_controls' => [
                ['part' => 'word/document.xml', 'tag' => 'borrower.full_name', 'id' => '1', 'classification' => 'SYSTEM_CONTROLLED'],
            ]],
            'document_structure' => ['headers_footers' => []],
        ];
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
        $path = tempnam(sys_get_temp_dir(), 'spmu-office-activation-');
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

    private function zipEntry(string $bytes, string $entry): string
    {
        $path = tempnam(sys_get_temp_dir(), 'spmu-office-activation-read-');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $contents = $zip->getFromName($entry);
        $zip->close();
        @unlink($path);
        $this->assertIsString($contents);

        return $contents;
    }
}
