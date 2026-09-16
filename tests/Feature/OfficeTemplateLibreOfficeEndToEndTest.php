<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\OfficeDraftTemplateRenderer;
use App\Services\ProtectedFileService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

/**
 * Phase 5 verification: a real, unmocked, LibreOffice-backed end-to-end run
 * of upload -> prepare -> minor edit -> preview -> activate -> production
 * generation, for both DOCX and XLSX.
 *
 * Unlike OfficeTemplateActivationTest (which mocks OfficeDraftTemplateRenderer
 * ::render() because the standard test image has no LibreOffice), every step
 * here calls through the real service and the real `soffice` binary. This
 * test image only exists to make that possible - see
 * Dockerfile.test.libreoffice / docker-compose.test.libreoffice.yml. The
 * standard Dockerfile.test / docker-compose.test.yml "test" service is
 * untouched, so the existing fast suite is unaffected.
 *
 * If `soffice` is unavailable (e.g. run outside the LibreOffice test image),
 * every test here is skipped rather than failed.
 */
class OfficeTemplateLibreOfficeEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private const RUN_COMMAND = 'docker compose -f docker-compose.test.libreoffice.yml build test-libreoffice '
        .'&& docker compose -f docker-compose.test.libreoffice.yml run --rm test-libreoffice '
        .'php vendor/bin/phpunit --colors=never tests/Feature/OfficeTemplateLibreOfficeEndToEndTest.php';

    private User $head;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->sofficeAvailable()) {
            self::markTestSkipped(
                'LibreOffice ("soffice") is not available in this test environment. Run this test in the '
                .'LibreOffice-enabled Docker image instead: '.self::RUN_COMMAND
            );
        }

        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $this->head = User::query()->where('access_classification', AccessClassification::SpmuHead->value)->firstOrFail();
    }

    public function test_docx_draft_real_libreoffice_end_to_end(): void
    {
        $result = $this->verifyRealLibreOfficeActivation(
            documentType: 'BORROWER_SLIP',
            routeSlug: 'borrower-slip',
            filename: 'borrower-slip-draft.docx',
            bytes: $this->docxBytes(),
            originalEditableText: 'Borrower Slip',
            newEditableText: 'REAL LIBREOFFICE DOCX HEADING',
        );

        $this->assertStringContainsString('REAL LIBREOFFICE DOCX HEADING', $result['preview_text']);
        $this->assertStringContainsString('REAL LIBREOFFICE DOCX HEADING', $result['production_text']);
        $this->assertSame($result['preview_pages'], $result['production_pages']);
    }

    public function test_xlsx_draft_real_libreoffice_end_to_end(): void
    {
        $result = $this->verifyRealLibreOfficeActivation(
            documentType: 'GATE_PASS',
            routeSlug: 'gate-pass',
            filename: 'gate-pass-draft.xlsx',
            bytes: $this->xlsxBytes(),
            originalEditableText: 'Office of the SPMU',
            // Kept close in length to the original cell text: XLSX cell
            // rendering wraps mid-word once text is much longer than the
            // fixture's narrow default column width, which is a rendering
            // artifact of this synthetic fixture, not something worth
            // asserting on here.
            newEditableText: 'REAL XLSX VERIFIED',
        );

        $this->assertStringContainsString('REAL XLSX VERIFIED', $result['preview_text']);
        $this->assertStringContainsString('REAL XLSX VERIFIED', $result['production_text']);
        $this->assertSame($result['preview_pages'], $result['production_pages']);
    }

    /**
     * Drives the full real pipeline once for one document type/format and
     * returns the extracted preview/production PDF text and page counts so
     * each test can assert on them.
     *
     * @return array{preview_text:string,production_text:string,preview_pages:int,production_pages:int}
     */
    private function verifyRealLibreOfficeActivation(
        string $documentType,
        string $routeSlug,
        string $filename,
        string $bytes,
        string $originalEditableText,
        string $newEditableText,
    ): array {
        // The seeded built-in Active layout for this type (every document
        // type ships with one - see 2026_08_26_074500_add_versioned_document
        // _template_sources.php), standing in for "whatever is in production
        // today". It must end up HISTORICAL, and any document already
        // generated under it must stay untouched.
        $previousActive = DocumentTemplate::query()
            ->where('document_type', $documentType)
            ->where('status', 'ACTIVE')
            ->firstOrFail();

        $oldFile = app(ProtectedFileService::class)->storeBytes(
            "%PDF-1.4\nold-generated-document\n",
            'generated-documents/'.strtolower($documentType),
            'old-generated.pdf',
            'application/pdf',
            'pdf',
            'GENERATED_DOCUMENT'
        );
        $oldGenerated = GeneratedDocument::query()->create([
            'template_id' => $previousActive->id,
            'stored_file_id' => $oldFile->id,
            'document_type' => $documentType,
            'document_no' => 'GEN-OLD-'.$documentType,
            'version_no' => 1,
            'sha256' => $oldFile->sha256,
            'status' => 'FINAL',
            'generated_at' => now(),
        ]);
        $oldGeneratedBefore = $oldGenerated->getAttributes();

        // 1. Real HTTP upload. storeDraft() itself shells out to soffice to
        //    build the legacy render representation, so this alone already
        //    exercises a real conversion of the uploaded source.
        $upload = UploadedFile::fake()->createWithContent($filename, $bytes);
        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->post(route('administration.document-templates.draft.store', ['type' => $routeSlug]), [
                'version_label' => 'v2.0',
                'template_file' => $upload,
                'reason' => 'Real LibreOffice E2E verification upload.',
            ])
            ->assertRedirect();

        $draft = DocumentTemplate::query()
            ->where('document_type', $documentType)
            ->where('version_label', 'v2.0')
            ->firstOrFail();
        $this->assertSame('NEEDS_PREPARATION', $draft->status);

        // 2. Real HTTP prepare -> real soffice run through the Office Draft
        //    compiler (compile() -> convertWorkingOfficeSource()).
        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->post(route('administration.document-templates.prepare', ['type' => $routeSlug, 'template' => $draft]))
            ->assertRedirect();

        $draft->refresh();
        $this->assertSame(
            'READY_FOR_PREVIEW',
            $draft->status,
            'Prepare failed: '.json_encode($draft->dynamic_schema['compatibility_warnings'] ?? [])
        );

        // 3. Real HTTP minor-edit screen renders (no LibreOffice needed for
        //    the screen itself - only the section scan).
        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->get(route('administration.document-templates.minor-edit', ['type' => $routeSlug, 'template' => $draft]))
            ->assertOk();

        $officeDrafts = app(OfficeDraftTemplateRenderer::class);
        $sections = $officeDrafts->editableSections($draft->fresh());
        $target = collect($sections)->firstWhere('text', $originalEditableText);
        $this->assertNotNull($target, "Fixture text \"{$originalEditableText}\" was not found among editable sections: ".json_encode($sections));

        // 4. Real HTTP minor-edit save -> re-runs the real Office Draft
        //    compiler (another real soffice run) to re-validate the edit.
        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->post(route('administration.document-templates.minor-edit.update', ['type' => $routeSlug, 'template' => $draft]), [
                'edits' => [
                    ['key' => $target['key'], 'text' => $newEditableText, 'alignment' => 'center'],
                ],
            ])
            ->assertRedirect();

        $draft->refresh();
        $this->assertSame(
            'READY_FOR_PREVIEW',
            $draft->status,
            'Re-prepare after minor edit failed: '.json_encode($draft->dynamic_schema['compatibility_warnings'] ?? [])
        );

        // 5. Real HTTP Preview - a real soffice-rendered PDF, the same one
        //    an Admin would see before confirming activation.
        $previewResponse = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->get(route('administration.document-templates.sample', ['type' => $routeSlug, 'template' => $draft]));
        $previewResponse->assertOk();
        $previewPdf = $previewResponse->streamedContent();
        $this->assertStringStartsWith('%PDF-', $previewPdf);
        $previewText = $this->pdfText($previewPdf);
        $this->assertStringContainsString($newEditableText, $this->normalizeWhitespace($previewText));

        // 6. Real HTTP activation. assertOfficeDraftReadyToActivate() calls
        //    the real render() again (another real soffice run) before the
        //    transactional HISTORICAL/ACTIVE flip.
        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->post(route('administration.document-templates.activate', ['type' => $routeSlug, 'template' => $draft]), ['preview_confirmed' => true])
            ->assertRedirect();

        $draft->refresh();
        $this->assertSame('ACTIVE', $draft->status);
        $this->assertNotNull($draft->activated_at);

        $previousActive->refresh();
        $this->assertSame('HISTORICAL', $previousActive->status);
        $this->assertNotNull($previousActive->superseded_at);

        // 7. The workflow's own resolution query (unchanged by Phase 5) now
        //    finds this Draft automatically.
        $resolved = DocumentTemplate::query()
            ->where('document_type', $documentType)
            ->where('status', 'ACTIVE')
            ->orderByDesc('template_version')
            ->first();
        $this->assertSame($draft->id, $resolved?->id);

        // 8. Real production generation through DocumentService's shared
        //    seam - the same renderCustomTemplate() every production call
        //    site uses - with the real, unmocked Office renderer: another
        //    real soffice run, this time via the Active/production path.
        $documents = app(DocumentService::class);
        $method = new ReflectionMethod(DocumentService::class, 'renderCustomTemplate');
        $productionPdf = $method->invoke($documents, $resolved, []);
        $this->assertStringStartsWith('%PDF-', $productionPdf);
        $productionText = $this->pdfText($productionPdf);
        $this->assertStringContainsString($newEditableText, $this->normalizeWhitespace($productionText));

        // 9. A document already generated under the now-superseded Active
        //    layout is untouched by any of the above.
        $oldGenerated->refresh();
        $this->assertSame($oldGeneratedBefore['stored_file_id'], $oldGenerated->stored_file_id);
        $this->assertSame($oldGeneratedBefore['sha256'], $oldGenerated->sha256);
        $this->assertSame(
            "%PDF-1.4\nold-generated-document\n",
            app(ProtectedFileService::class)->bytes($oldGenerated->file)
        );

        return [
            'preview_text' => $this->normalizeWhitespace($previewText),
            'production_text' => $this->normalizeWhitespace($productionText),
            'preview_pages' => $this->pdfPageCount($previewPdf),
            'production_pages' => $this->pdfPageCount($productionPdf),
        ];
    }

    /**
     * pdftotext -layout preserves the source's visual line breaks, so text
     * that LibreOffice wraps across lines to fit a narrow XLSX column comes
     * back with embedded newlines. Comparisons care that the words appear in
     * order, not exactly how the renderer happened to wrap them.
     */
    private function normalizeWhitespace(string $text): string
    {
        return (string) preg_replace('/\s+/', ' ', trim($text));
    }

    private function sofficeAvailable(): bool
    {
        $process = new Process(['which', 'soffice']);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Real PDF text extraction via poppler-utils (present in the
     * LibreOffice-enabled test image), used only to compare what Preview and
     * production actually rendered - not to assert exact PDF bytes, since
     * LibreOffice embeds a fresh timestamp on every conversion even when the
     * visual content is identical.
     */
    private function pdfText(string $pdfBytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'spmu-libreoffice-e2e-pdf-');
        $this->assertIsString($path);
        file_put_contents($path, $pdfBytes);

        try {
            $process = new Process(['pdftotext', '-layout', $path, '-']);
            $process->run();
            $this->assertTrue($process->isSuccessful(), 'pdftotext failed: '.$process->getErrorOutput());

            return $process->getOutput();
        } finally {
            @unlink($path);
        }
    }

    private function pdfPageCount(string $pdfBytes): int
    {
        $path = tempnam(sys_get_temp_dir(), 'spmu-libreoffice-e2e-pdfinfo-');
        $this->assertIsString($path);
        file_put_contents($path, $pdfBytes);

        try {
            $process = new Process(['pdfinfo', $path]);
            $process->run();
            $this->assertTrue($process->isSuccessful(), 'pdfinfo failed: '.$process->getErrorOutput());
            $this->assertMatchesRegularExpression('/^Pages:\s*(\d+)$/m', $process->getOutput());
            preg_match('/^Pages:\s*(\d+)$/m', $process->getOutput(), $matches);

            return (int) $matches[1];
        } finally {
            @unlink($path);
        }
    }

    /**
     * A minimal but *structurally complete* OOXML package. Every other
     * template test in this codebase builds a much barer fixture, which is
     * fine because they only ever exercise ZipArchive/DOMDocument scanning.
     * Real LibreOffice additionally requires a valid `_rels/.rels` root
     * relationship and matching `[Content_Types].xml` entries to open a
     * package at all, so this fixture includes both.
     */
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
                .'<w:sdt><w:sdtPr><w:id w:val="1"/><w:tag w:val="borrower.full_name"/></w:sdtPr><w:sdtContent><w:p><w:r><w:t>SOURCE BORROWER</w:t></w:r></w:p></w:sdtContent></w:sdt>'
                .'<w:sectPr/></w:body></w:document>',
        ]);
    }

    private function xlsxBytes(): string
    {
        return $this->zip([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .'<Override PartName="/xl/tables/table1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Input" sheetId="1" r:id="rId1"/></sheets><definedNames><definedName name="request.number">Input!$A$5</definedName></definedNames></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/><Relationship Id="rId2" Target="styles.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"/></Relationships>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheetData>'
                .'<row r="1"><c r="A1" s="0" t="inlineStr"><is><t>qty</t></is></c><c r="B1" s="0" t="inlineStr"><is><t>description</t></is></c><c r="C1" s="0" t="inlineStr"><is><t>unit</t></is></c></row>'
                .'<row r="2" ht="18" customHeight="1"><c r="A2" s="0" t="inlineStr"><is><t>Q1</t></is></c><c r="B2" s="0" t="inlineStr"><is><t>D1</t></is></c><c r="C2" s="0" t="inlineStr"><is><t>U1</t></is></c></row>'
                .'<row r="3" ht="18" customHeight="1"><c r="A3" s="0" t="inlineStr"><is><t>Q2</t></is></c><c r="B3" s="0" t="inlineStr"><is><t>D2</t></is></c><c r="C3" s="0" t="inlineStr"><is><t>U2</t></is></c></row>'
                .'<row r="5" ht="18" customHeight="1"><c r="A5" s="0" t="inlineStr"><is><t>REQUEST PLACEHOLDER</t></is></c></row>'
                .'<row r="7"><c r="A7" s="0" t="inlineStr"><is><t>Office of the SPMU</t></is></c></row>'
                .'</sheetData><tableParts count="1"><tablePart r:id="rId2"/></tableParts></worksheet>',
            'xl/worksheets/_rels/sheet1.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId2" Target="../tables/table1.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table"/></Relationships>',
            'xl/tables/table1.xml' => '<?xml version="1.0"?><table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" id="1" name="spmu__items__records" displayName="spmu__items__records" ref="A1:C3"><tableColumns count="3"><tableColumn id="1" name="qty"/><tableColumn id="2" name="description"/><tableColumn id="3" name="unit"/></tableColumns></table>',
            'xl/styles.xml' => '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font/></fonts><fills count="1"><fill/></fills><borders count="1"><border/></borders><cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellXfs></styleSheet>',
        ]);
    }

    /** @param array<string,string> $entries */
    private function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'spmu-office-libreoffice-e2e-');
        $this->assertIsString($path);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::OVERWRITE) === true);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        $bytes = file_get_contents($path);
        @unlink($path);
        $this->assertIsString($bytes);

        return $bytes;
    }
}
