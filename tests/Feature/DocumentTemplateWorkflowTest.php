<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\DocumentTemplateLayoutService;
use App\Services\ProtectedFileService;
use App\Services\SimplePdfService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * PDF is retired as an *uploaded official template source* (see
 * DocumentTemplateController::storeDraft()/prepare()/activate()). Generated
 * PDF output is unaffected - only uploading, preparing, or activating a new
 * PDF *source* layout is blocked. This file used to unit-test the PDF
 * auto-mapping engine (DocumentTemplateLayoutService::autoMap() and its
 * geometry-based helpers) directly; that engine no longer exists, so those
 * tests are retired along with it. What remains is: (1) coverage proving
 * retirement is enforced at every stage, and (2) the Draft discard/
 * authorization tests this file also carried, now using a DOCX fixture
 * instead of a PDF one since discard is format-agnostic and DOCX/XLSX are
 * the only remaining editable official template formats.
 */
class DocumentTemplateWorkflowTest extends TestCase
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

    public function test_uploading_a_pdf_official_template_source_is_rejected(): void
    {
        $this->asHead()->post(route('administration.document-templates.draft.store', 'borrower-slip'), [
            'version_label' => 'v9.1',
            'reason' => 'Attempted PDF source upload after retirement.',
            'template_file' => UploadedFile::fake()->createWithContent('borrower-slip.pdf', app(SimplePdfService::class)->make(['Purpose'])),
        ])->assertSessionHasErrors('template_file');

        $this->assertDatabaseMissing('document_templates', ['document_type' => 'BORROWER_SLIP', 'version_label' => 'v9.1']);
    }

    public function test_a_pdf_draft_uploaded_before_retirement_can_no_longer_be_prepared(): void
    {
        $draft = $this->legacyPdfDraft('v9.2', 'NEEDS_PREPARATION');

        $this->asHead()->post(route('administration.document-templates.prepare', ['type' => 'borrower-slip', 'template' => $draft]))
            ->assertRedirect()
            ->assertSessionHasErrors([
                'template' => 'PDF official layouts are retired and can no longer be prepared. Upload a DOCX or XLSX layout instead.',
            ]);

        $this->assertSame('NEEDS_PREPARATION', $draft->fresh()->status);
    }

    public function test_a_pdf_draft_uploaded_before_retirement_can_no_longer_be_activated(): void
    {
        $draft = $this->legacyPdfDraft('v9.3', 'READY_FOR_PREVIEW');

        $this->asHead()->post(route('administration.document-templates.activate', ['type' => 'borrower-slip', 'template' => $draft]), ['preview_confirmed' => true])
            ->assertRedirect()
            ->assertSessionHasErrors([
                'template' => 'PDF official layouts are retired and can no longer be activated. Upload a DOCX or XLSX layout instead.',
            ]);

        $draft->refresh();
        $this->assertSame('READY_FOR_PREVIEW', $draft->status);
        $this->assertNull($draft->activated_at);
    }

    public function test_discard_removes_an_exclusive_unactivated_draft_but_never_the_active_layout(): void
    {
        $active = DocumentTemplate::query()->where('document_type', 'BORROWER_SLIP')->where('status', 'ACTIVE')->firstOrFail();
        $draft = $this->uploadOfficeDraft('v2.0');
        $source = $draft->file()->firstOrFail();

        $this->asHead()->get(route('administration.settings.index', ['section' => 'template-borrower-slip']))
            ->assertOk()->assertSee('Discard Draft')->assertSee('Discard this draft layout?');
        $this->asHead()->delete(route('administration.document-templates.draft.destroy', ['type' => 'borrower-slip', 'template' => $draft]))
            ->assertRedirect()->assertSessionHas('status', "Borrower's Slip v2.0 draft was discarded. The current official layout was not changed.");

        $this->assertDatabaseMissing('document_templates', ['id' => $draft->id]);
        $this->assertDatabaseMissing('stored_files', ['id' => $source->id]);
        Storage::disk('local')->assertMissing($source->storage_path);
        $this->assertSame('ACTIVE', $active->fresh()->status);
    }

    public function test_active_and_generated_template_records_cannot_be_discarded(): void
    {
        $active = DocumentTemplate::query()->where('document_type', 'BORROWER_SLIP')->where('status', 'ACTIVE')->firstOrFail();
        $this->asHead()->delete(route('administration.document-templates.draft.destroy', ['type' => 'borrower-slip', 'template' => $active]))->assertStatus(422);

        $draft = $this->uploadOfficeDraft('v2.3');
        $document = GeneratedDocument::query()->create([
            'template_id' => $draft->id, 'document_no' => 'TEST-DRAFT-'.str()->uuid(), 'document_type' => 'BORROWER_SLIP',
            'version_no' => 1, 'status' => 'GENERATED', 'generated_at' => now(),
        ]);
        $this->asHead()->delete(route('administration.document-templates.draft.destroy', ['type' => 'borrower-slip', 'template' => $draft]))->assertStatus(422);
        $this->assertDatabaseHas('generated_documents', ['id' => $document->id, 'template_id' => $draft->id]);
    }

    public function test_unauthorized_user_cannot_discard_a_draft(): void
    {
        $draft = $this->uploadOfficeDraft('v2.1');
        $borrower = User::factory()->create(['access_classification' => AccessClassification::BorrowerOnly]);
        $this->withSession(['active_workspace' => 'SPMU'])->actingAs($borrower)
            ->delete(route('administration.document-templates.draft.destroy', ['type' => 'borrower-slip', 'template' => $draft]))->assertForbidden();
        $this->assertDatabaseHas('document_templates', ['id' => $draft->id]);
    }

    private function asHead()
    {
        return $this->withSession(['active_workspace' => 'SPMU'])->actingAs($this->head);
    }

    /**
     * A Draft built directly through the layout/storage services rather
     * than the storeDraft() HTTP route, so this suite never needs real
     * LibreOffice (storeDraft() shells out to soffice to build a DOCX/XLSX
     * upload's render representation) - the same seam
     * OfficeTemplateActivationTest uses for the same reason.
     */
    private function uploadOfficeDraft(string $versionLabel): DocumentTemplate
    {
        $upload = UploadedFile::fake()->createWithContent('borrower-slip-'.$versionLabel.'.docx', $this->docxBytes());
        $layouts = app(DocumentTemplateLayoutService::class);
        $inspection = $layouts->inspectUpload($upload);
        $storedFile = app(ProtectedFileService::class)->storeUpload($upload, 'document-templates/borrower-slip', 'DOCUMENT_TEMPLATE_SOURCE');
        $schema = $layouts->schemaForStoredSource('DOCX', $inspection['review'], $storedFile->sha256, $storedFile->id);

        return DocumentTemplate::query()->create([
            'document_type' => 'BORROWER_SLIP',
            'template_version' => 800 + DocumentTemplate::query()->where('document_type', 'BORROWER_SLIP')->count(),
            'version_label' => $versionLabel,
            'template_name' => "Borrower's Slip {$versionLabel}",
            'content_template' => json_encode([
                'format' => 'DOCX',
                'render_representation' => 'NORMALIZED_PDF',
                'review' => [],
                'mappings' => [],
                'table_layouts' => [],
                'analysis' => ['ready' => false],
                'preparation' => ['state' => 'PENDING'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'dynamic_schema' => $schema,
            'stored_file_id' => $storedFile->id,
            'render_stored_file_id' => $storedFile->id,
            'source_mode' => 'OFFICIAL_LAYOUT',
            'change_reason' => 'Approved official source revision.',
            'status' => 'NEEDS_PREPARATION',
            'configured_by_user_id' => $this->head->id,
            'activated_at' => null,
            'superseded_at' => null,
        ]);
    }

    /**
     * Stands in for a PDF Draft that was uploaded before PDF retirement -
     * still a legitimate row an existing installation may already have, so
     * prepare()/activate() must still handle it (by refusing it), not crash.
     */
    private function legacyPdfDraft(string $versionLabel, string $status): DocumentTemplate
    {
        $storedFile = app(ProtectedFileService::class)->storeBytes(
            app(SimplePdfService::class)->make(['Purpose']),
            'document-templates/borrower-slip',
            'borrower-slip-'.$versionLabel.'.pdf',
            'application/pdf',
            'pdf',
            'DOCUMENT_TEMPLATE_SOURCE'
        );

        return DocumentTemplate::query()->create([
            'document_type' => 'BORROWER_SLIP',
            'template_version' => 800 + DocumentTemplate::query()->where('document_type', 'BORROWER_SLIP')->count(),
            'version_label' => $versionLabel,
            'template_name' => "Borrower's Slip {$versionLabel}",
            'content_template' => json_encode([
                'format' => 'PDF',
                'render_representation' => 'DIRECT_PDF',
                'review' => [],
                'mappings' => [],
                'table_layouts' => [],
                'analysis' => ['ready' => $status === 'READY_FOR_PREVIEW'],
                'preparation' => $status === 'READY_FOR_PREVIEW' ? ['state' => 'READY'] : ['state' => 'PENDING'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'dynamic_schema' => null,
            'stored_file_id' => $storedFile->id,
            'render_stored_file_id' => $storedFile->id,
            'source_mode' => 'OFFICIAL_LAYOUT',
            'change_reason' => 'Legacy PDF source predating retirement.',
            'status' => $status,
            'configured_by_user_id' => $this->head->id,
            'activated_at' => null,
            'superseded_at' => null,
        ]);
    }

    private function docxBytes(): string
    {
        return $this->zip([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
                .'</Relationships>',
            'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
                .'<w:p><w:r><w:t>Borrower Slip</w:t></w:r></w:p>'
                .'<w:sectPr/></w:body></w:document>',
        ]);
    }

    /** @param array<string,string> $files */
    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'spmu-office-workflow-test-');
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
