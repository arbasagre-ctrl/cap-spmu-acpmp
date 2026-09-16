<?php

namespace Tests\Feature;

use App\Services\OfficeDraftTemplateRenderer;
use App\Services\ProtectedFileService;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;
use ZipArchive;

/**
 * Phase 4: minor presentation editing on an Office Draft.
 *
 * An Admin may retouch safe static wording - a heading, a label, footer
 * text - without re-uploading the template. The two promises this guards:
 * the set of editable sections can never include anything data-bound,
 * signature-related, or otherwise locked, and applying a saved edit never
 * mutates the immutable uploaded source - only the temporary clone that is
 * already how every Draft-only compile works.
 */
class OfficeDraftMinorEditTest extends TestCase
{
    public function test_editable_sections_expose_only_safe_static_text_without_technical_identities(): void
    {
        $sections = $this->renderer()->editableSectionsForSource($this->docxBytes(), $this->docxSchema());

        $this->assertCount(1, $sections);
        $this->assertSame('0', $sections[0]['key']);
        $this->assertSame('Borrower Slip', $sections[0]['text']);
        $this->assertSame('left', $sections[0]['alignment']);

        // Non-technical: no raw tag, part path, or coordinate in the key or label.
        $this->assertStringNotContainsString('word/document.xml', $sections[0]['label']);
        $this->assertStringNotContainsString('borrower.full_name', $sections[0]['label']);
        $this->assertStringContainsString('Borrower Slip', $sections[0]['label']);

        // The content-control-bound text and the repeating item row never appear.
        $texts = array_column($sections, 'text');
        $this->assertNotContains('SOURCE BORROWER', $texts);
        $this->assertNotContains('QTY', $texts);
    }

    public function test_saving_a_text_edit_is_applied_without_touching_the_immutable_upload(): void
    {
        $source = $this->docxBytes();
        $schema = $this->docxSchema();
        $schema['minor_edits'] = [
            ['key' => '0', 'text' => 'Updated Heading', 'alignment' => null],
        ];

        $compiled = $this->renderer()->compileWorkingSource($source, $schema, $this->context());

        /* The upload itself is never mutated - only a temporary clone is compiled. */
        $this->assertStringContainsString('Borrower Slip', $this->zipEntry($source, 'word/document.xml'));

        $document = $this->zipEntry($compiled['bytes'], 'word/document.xml');
        $this->assertStringContainsString('Updated Heading', $document);
        $this->assertStringNotContainsString('Borrower Slip', $document);
        /* The content control's data binding is completely untouched. */
        $this->assertStringContainsString('borrower.full_name', $document);
    }

    public function test_alignment_edit_is_applied_to_the_paragraph(): void
    {
        $schema = $this->docxSchema();
        $schema['minor_edits'] = [
            ['key' => '0', 'text' => 'Borrower Slip', 'alignment' => 'center'],
        ];

        $compiled = $this->renderer()->compileWorkingSource($this->docxBytes(), $schema, $this->context());
        $document = $this->zipEntry($compiled['bytes'], 'word/document.xml');

        $this->assertStringContainsString('w:jc w:val="center"', $document);
    }

    public function test_validate_minor_edits_rejects_a_key_that_is_not_a_safe_editable_section(): void
    {
        $this->expectException(ValidationException::class);

        // Only index "0" is a safe editable section for this fixture; the
        // content-control-bound paragraph never enters the sequence at all.
        $this->renderer()->validateMinorEditsForSource($this->docxBytes(), $this->docxSchema(), [
            ['key' => '1', 'text' => 'Hijacked', 'alignment' => null],
        ]);
    }

    public function test_validate_minor_edits_rejects_blank_text(): void
    {
        $this->expectException(ValidationException::class);

        $this->renderer()->validateMinorEditsForSource($this->docxBytes(), $this->docxSchema(), [
            ['key' => '0', 'text' => '   ', 'alignment' => null],
        ]);
    }

    public function test_validate_minor_edits_returns_a_normalized_safe_list(): void
    {
        $edits = $this->renderer()->validateMinorEditsForSource($this->docxBytes(), $this->docxSchema(), [
            ['key' => '0', 'text' => '  New wording  ', 'alignment' => 'right'],
        ]);

        $this->assertCount(1, $edits);
        $this->assertSame('New wording', $edits[0]['text']);
        $this->assertSame('right', $edits[0]['alignment']);
    }

    public function test_xlsx_editable_sections_exclude_named_range_table_and_formula_cells(): void
    {
        $sections = $this->renderer()->editableSectionsForSource($this->xlsxBytes(), $this->xlsxSchema());

        $texts = array_column($sections, 'text');

        // The only static cell outside every named range, table, and
        // formula is offered - addressed only by an opaque sequence index.
        $this->assertCount(1, $sections);
        $this->assertSame('0', $sections[0]['key']);
        $this->assertSame('Office of the SPMU', $sections[0]['text']);

        // The named-range anchor, every table cell, and the formula cell are never offered.
        $this->assertNotContains('REQUEST PLACEHOLDER', $texts);
        $this->assertNotContains('qty', $texts);
    }

    public function test_xlsx_minor_edit_updates_a_static_cell_and_sets_alignment(): void
    {
        $source = $this->xlsxBytes();
        $schema = $this->xlsxSchema();
        $schema['minor_edits'] = [
            ['key' => '0', 'text' => 'Office of the CSPC-SPMU', 'alignment' => 'center'],
        ];

        $compiled = $this->renderer()->compileWorkingSource($source, $schema, $this->context([
            'request' => ['number' => 'REQ-2026-0001'],
            'items' => ['records' => []],
        ]));

        $this->assertStringContainsString('Office of the SPMU', $this->zipEntry($source, 'xl/worksheets/sheet1.xml'));

        $sheet = $this->zipEntry($compiled['bytes'], 'xl/worksheets/sheet1.xml');
        $styles = $this->zipEntry($compiled['bytes'], 'xl/styles.xml');
        $this->assertStringContainsString('Office of the CSPC-SPMU', $sheet);
        $this->assertStringNotContainsString('>Office of the SPMU<', $sheet);
        $this->assertStringContainsString('horizontal="center"', $styles);
    }

    public function test_xlsx_validate_minor_edits_rejects_an_out_of_range_key(): void
    {
        $this->expectException(ValidationException::class);

        // Only index "0" is a safe editable section for this fixture; the
        // named-range, table, and formula cells never enter the sequence.
        $this->renderer()->validateMinorEditsForSource($this->xlsxBytes(), $this->xlsxSchema(), [
            ['key' => '1', 'text' => 'Hijacked formula', 'alignment' => null],
        ]);
    }

    private function renderer(): OfficeDraftTemplateRenderer
    {
        return new OfficeDraftTemplateRenderer(Mockery::mock(ProtectedFileService::class));
    }

    /** @param array<string,array<string,mixed>> $namespaces @return array<string,mixed> */
    private function context(array $namespaces = []): array
    {
        $paths = [];
        foreach ($namespaces as $namespace => $values) {
            foreach (array_keys($values) as $key) {
                $paths[] = $namespace.'.'.$key;
            }
        }

        return ['namespaces' => $namespaces, 'available_paths' => $paths];
    }

    /** @return array<string,mixed> */
    private function docxSchema(): array
    {
        return [
            'source' => ['format' => 'DOCX'],
            'compatibility_warnings' => [],
            'office_identity' => ['content_controls' => [
                ['part' => 'word/document.xml', 'tag' => 'borrower.full_name', 'id' => '1', 'classification' => 'SYSTEM_CONTROLLED'],
                ['part' => 'word/document.xml', 'tag' => 'items.records', 'id' => '2', 'classification' => 'SYSTEM_CONTROLLED'],
            ]],
            'document_structure' => ['headers_footers' => []],
        ];
    }

    /** @return array<string,mixed> */
    private function xlsxSchema(): array
    {
        return [
            'source' => ['format' => 'XLSX'],
            'compatibility_warnings' => [],
            'office_identity' => [
                'named_ranges' => [
                    ['name' => 'request.number', 'reference' => 'Input!$A$5'],
                ],
                'tables' => [
                    ['part' => 'xl/tables/table1.xml', 'name' => 'spmu__items__records', 'ref' => 'A1:C3', 'columns' => ['qty', 'description', 'unit']],
                ],
            ],
        ];
    }

    private function docxBytes(): string
    {
        return $this->zip([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
            'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
                .'<w:p><w:r><w:t>Borrower Slip</w:t></w:r></w:p>'
                .'<w:sdt><w:sdtPr><w:id w:val="1"/><w:tag w:val="borrower.full_name"/></w:sdtPr><w:sdtContent><w:p><w:r><w:t>SOURCE BORROWER</w:t></w:r></w:p></w:sdtContent></w:sdt>'
                .'<w:tbl><w:sdt><w:sdtPr><w:id w:val="2"/><w:tag w:val="items.records"/></w:sdtPr><w:sdtContent><w:tr><w:trPr><w:trHeight w:val="240" w:hRule="exact"/></w:trPr><w:tc><w:p><w:sdt><w:sdtPr><w:tag w:val="items.qty"/></w:sdtPr><w:sdtContent><w:p><w:r><w:t>QTY</w:t></w:r></w:p></w:sdtContent></w:sdt></w:p></w:tc></w:tr></w:sdtContent></w:sdt></w:tbl>'
                .'<w:sectPr/></w:body></w:document>',
        ]);
    }

    private function xlsxBytes(): string
    {
        return $this->zip([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Input" sheetId="1" r:id="rId1"/></sheets><definedNames><definedName name="request.number">Input!$A$5</definedName></definedNames></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/></Relationships>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheetData>'
                .'<row r="1"><c r="A1" s="0" t="inlineStr"><is><t>qty</t></is></c><c r="B1" s="0" t="inlineStr"><is><t>description</t></is></c><c r="C1" s="0" t="inlineStr"><is><t>unit</t></is></c></row>'
                .'<row r="2" ht="18" customHeight="1"><c r="A2" s="0" t="inlineStr"><is><t>Q1</t></is></c><c r="B2" s="0" t="inlineStr"><is><t>D1</t></is></c><c r="C2" s="0" t="inlineStr"><is><t>U1</t></is></c></row>'
                .'<row r="3" ht="18" customHeight="1"><c r="A3" s="0" t="inlineStr"><is><t>Q2</t></is></c><c r="B3" s="0" t="inlineStr"><is><t>D2</t></is></c><c r="C3" s="0" t="inlineStr"><is><t>U2</t></is></c></row>'
                .'<row r="5" ht="18" customHeight="1"><c r="A5" s="0" t="inlineStr"><is><t>REQUEST PLACEHOLDER</t></is></c></row>'
                .'<row r="7"><c r="A7" s="0" t="inlineStr"><is><t>Office of the SPMU</t></is></c></row>'
                .'<row r="8"><c r="A8" s="0"><f>1+1</f><v>2</v></c></row>'
                .'</sheetData><tableParts count="1"><tablePart r:id="rId2"/></tableParts></worksheet>',
            'xl/worksheets/_rels/sheet1.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId2" Target="../tables/table1.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table"/></Relationships>',
            'xl/tables/table1.xml' => '<?xml version="1.0"?><table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" id="1" name="spmu__items__records" displayName="spmu__items__records" ref="A1:C3"><tableColumns count="3"><tableColumn id="1" name="qty"/><tableColumn id="2" name="description"/><tableColumn id="3" name="unit"/></tableColumns></table>',
            'xl/styles.xml' => '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font/></fonts><fills count="1"><fill/></fills><borders count="1"><border/></borders><cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellXfs></styleSheet>',
        ]);
    }

    /** @param array<string,string> $entries */
    private function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'spmu-office-minor-edit-test-');
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

    private function zipEntry(string $bytes, string $entry): string
    {
        $path = tempnam(sys_get_temp_dir(), 'spmu-office-minor-edit-read-');
        $this->assertIsString($path);
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
