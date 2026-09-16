<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Services\DocumentTemplateLayoutService;
use App\Services\DocumentTemplateRenderer;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use ZipArchive;

class DynamicOfficeTemplateRuntimeTest extends TestCase
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

    public function test_docx_reader_persists_flow_structure_and_hidden_office_identity_without_page_coordinates(): void
    {
        $layouts = app(DocumentTemplateLayoutService::class);
        $inspection = $layouts->inspectUpload(UploadedFile::fake()->createWithContent('borrower-slip-v4.docx', $this->docxBytes()));

        $this->assertSame('DOCX', $inspection['format']);
        $schema = $layouts->schemaForStoredSource('DOCX', $inspection['review'], str_repeat('a', 64), 501);

        $this->assertSame(1, $schema['schema_version']);
        $this->assertSame('DOCX', $schema['source']['format']);
        $this->assertSame(501, $schema['source']['stored_file_id']);
        $this->assertFalse($schema['document_structure']['pre_render_page_coordinates']);
        $this->assertSame('borrower.full_name', $schema['office_identity']['content_controls'][0]['tag']);
        $this->assertArrayNotHasKey('text', $schema['office_identity']['content_controls'][0]);
        $this->assertSame('portrait', $schema['document_structure']['sections'][0]['orientation']);
        $this->assertSame('SYSTEM_CONTROLLED', $schema['regions']['data'][0]['classification']);
        $this->assertContains('Template Reference', array_column($schema['office_identity']['bookmarks'], 'name'));
    }

    public function test_xlsx_reader_discovers_named_ranges_tables_merges_and_print_metadata(): void
    {
        $layouts = app(DocumentTemplateLayoutService::class);
        $inspection = $layouts->inspectUpload(UploadedFile::fake()->createWithContent('billing-v4.xlsx', $this->xlsxBytes()));
        $schema = $layouts->schemaForStoredSource('XLSX', $inspection['review'], str_repeat('b', 64), 502);

        $this->assertSame('XLSX', $inspection['format']);
        $this->assertSame('request.request_no', $schema['office_identity']['named_ranges'][0]['name']);
        $this->assertSame('Billing!$A$2', $schema['office_identity']['named_ranges'][0]['reference']);
        $this->assertArrayNotHasKey('formula', $schema['office_identity']['named_ranges'][0]);
        $this->assertSame('BillingLines', $schema['office_identity']['tables'][0]['name']);
        $this->assertContains('A1:C1', $schema['document_structure']['worksheets'][0]['merged_cells']);
        $this->assertSame(['A1', 'A2'], $schema['document_structure']['worksheets'][0]['occupied_cells']);
        $this->assertArrayNotHasKey('labels', $schema['document_structure']);
        $this->assertSame('landscape', $schema['document_structure']['worksheets'][0]['page_setup']['orientation']);
        $this->assertSame('Billing!$A$1:$C$20', $schema['document_structure']['worksheets'][0]['print_area']);
        $this->assertSame('PENDING_RUNTIME_CLASSIFICATION', $schema['regions']['data'][0]['classification']);
    }

    public function test_office_draft_upload_stores_schema_without_changing_the_existing_active_template(): void
    {
        $current = DocumentTemplate::query()
            ->where('document_type', 'BORROWER_SLIP')
            ->where('status', 'ACTIVE')
            ->firstOrFail();
        $renderer = Mockery::mock(DocumentTemplateRenderer::class);
        $renderer->shouldReceive('renderRepresentation')
            ->once()
            ->andReturn(['bytes' => "%PDF-1.4\nphase-one\n", 'representation' => 'NORMALIZED_PDF']);
        $this->app->instance(DocumentTemplateRenderer::class, $renderer);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->post(route('administration.document-templates.draft.store', 'borrower-slip'), [
                'version_label' => 'v1.1',
                'reason' => 'Phase 1 Office schema foundation test.',
                'template_file' => UploadedFile::fake()->createWithContent('borrower-slip-v4.docx', $this->docxBytes()),
            ])
            ->assertRedirect();

        $draft = DocumentTemplate::query()
            ->where('document_type', 'BORROWER_SLIP')
            ->where('version_label', 'v1.1')
            ->firstOrFail();

        $this->assertSame('NEEDS_PREPARATION', $draft->status);
        $this->assertIsArray($draft->dynamic_schema);
        $this->assertSame('DOCX', $draft->dynamic_schema['source']['format']);
        $this->assertSame($draft->stored_file_id, $draft->dynamic_schema['source']['stored_file_id']);
        $this->assertSame('ACTIVE', $current->fresh()->status);
    }

    public function test_office_archive_with_excessive_entry_count_is_rejected_before_schema_reading(): void
    {
        $layouts = app(DocumentTemplateLayoutService::class);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->expectExceptionMessage('too many internal files');
        $layouts->inspectUpload(UploadedFile::fake()->createWithContent('oversized-structure.docx', $this->archiveWithTooManyEntries()));
    }

    public function test_schema_truncation_is_explicitly_blocking_instead_of_silent(): void
    {
        $layouts = app(DocumentTemplateLayoutService::class);
        $inspection = $layouts->inspectUpload(UploadedFile::fake()->createWithContent('many-bookmarks.docx', $this->docxWithManyBookmarks()));
        $schema = $layouts->schemaForStoredSource('DOCX', $inspection['review'], str_repeat('c', 64), 503);

        $warning = collect($schema['compatibility_warnings'])->firstWhere('code', 'SCHEMA_NODE_LIMIT_EXCEEDED');

        $this->assertIsArray($warning);
        $this->assertSame('BLOCKING', $warning['severity']);
        $this->assertSame('office_identity.bookmarks', $warning['path']);
        $this->assertSame(10001, $warning['discovered_count']);
    }

    private function docxBytes(): string
    {
        return $this->zip([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
            'docProps/custom.xml' => '<?xml version="1.0"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/custom-properties"><property name="ApprovedForm"/></Properties>',
            'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"><w:body><w:p><w:r><w:t>Borrower Slip</w:t></w:r></w:p><w:sdt><w:sdtPr><w:id w:val="42"/><w:tag w:val="borrower.full_name"/><w:alias w:val="Borrower Name"/><w:dataBinding w:xpath="/runtime/borrower/full_name" w:storeItemID="{A}"/></w:sdtPr><w:sdtContent><w:p><w:r><w:t>Sample Name</w:t></w:r></w:p></w:sdtContent></w:sdt><w:bookmarkStart w:id="7" w:name="Template Reference"/><w:tbl><w:tr><w:tc><w:p><w:r><w:t>Item</w:t></w:r></w:p></w:tc></w:tr></w:tbl><w:sectPr><w:pgSz w:w="12240" w:h="15840" w:orient="portrait"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr></w:body></w:document>',
            'word/header1.xml' => '<?xml version="1.0"?><w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:p><w:r><w:t>SPMU</w:t></w:r></w:p></w:hdr>',
            'word/styles.xml' => '<?xml version="1.0"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:style w:type="paragraph" w:styleId="Normal"><w:name w:val="Normal"/></w:style></w:styles>',
        ]);
    }

    private function xlsxBytes(): string
    {
        return $this->zip([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Billing" sheetId="1" r:id="rId1"/></sheets><definedNames><definedName name="request.request_no">Billing!$A$2</definedName><definedName name="_xlnm.Print_Area">Billing!$A$1:$C$20</definedName></definedNames></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/></Relationships>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="3" width="20"/></cols><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Billing Statement</t></is></c></row><row r="2" ht="18" customHeight="1"><c r="A2" t="inlineStr"><is><t>Request Number</t></is></c></row></sheetData><mergeCells count="1"><mergeCell ref="A1:C1"/></mergeCells><pageMargins left="0.5" right="0.5" top="0.75" bottom="0.75"/><pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="1"/></worksheet>',
            'xl/tables/table1.xml' => '<?xml version="1.0"?><table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" id="1" name="BillingLines" displayName="BillingLines" ref="A4:C10"><tableColumns count="3"><tableColumn id="1" name="Description"/><tableColumn id="2" name="Quantity"/><tableColumn id="3" name="Amount"/></tableColumns></table>',
            'xl/styles.xml' => '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font/></fonts><fills count="1"><fill/></fills><borders count="1"><border/></borders><cellXfs count="1"><xf/></cellXfs></styleSheet>',
        ]);
    }

    private function archiveWithTooManyEntries(): string
    {
        $entries = [];
        for ($index = 0; $index <= 2000; $index++) {
            $entries['word/parts/'.$index.'.xml'] = '<part/>';
        }

        return $this->zip($entries);
    }

    private function docxWithManyBookmarks(): string
    {
        $bookmarks = '';
        for ($index = 1; $index <= 10001; $index++) {
            $bookmarks .= '<w:bookmarkStart w:id="'.$index.'" w:name="Field'.$index.'"/>';
        }

        return $this->zip([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
            'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$bookmarks.'<w:sectPr/></w:body></w:document>',
        ]);
    }

    /** @param array<string,string> $files */
    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'spmu-office-test-');
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
