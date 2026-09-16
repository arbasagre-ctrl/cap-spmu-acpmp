<?php

namespace Tests\Feature;

use App\Services\OfficeDraftTemplateRenderer;
use App\Services\ProtectedFileService;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;
use ZipArchive;

class OfficeDraftTemplateRendererTest extends TestCase
{
    public function test_docx_compiler_clones_the_source_and_uses_native_repeating_rows(): void
    {
        $source = $this->docxBytes();
        $compiled = $this->renderer()->compileWorkingSource($source, $this->docxSchema(), $this->context([
            'borrower' => ['full_name' => 'Authorized Borrower'],
            'items' => ['records' => [
                ['qty' => '1', 'description' => 'Projector'],
                ['qty' => '2', 'description' => str_repeat('Long wrapped description ', 12)],
            ]],
        ]));

        /* The upload itself is never mutated - only a temporary clone is compiled. */
        $this->assertStringContainsString('SOURCE BORROWER', $this->zipEntry($source, 'word/document.xml'));
        $document = $this->zipEntry($compiled['bytes'], 'word/document.xml');
        $this->assertStringContainsString('Authorized Borrower', $document);
        $this->assertStringContainsString('Projector', $document);
        $this->assertStringContainsString('Long wrapped description', $document);
        $this->assertSame(2, substr_count($document, '<w:tr>'));
        $this->assertStringContainsString('w:hRule="atLeast"', $document);
        $this->assertSame('borrower.full_name', $compiled['plan']['resolver']['bindings'][0]['path']);
        $this->assertContains('items.records', array_column($compiled['plan']['resolver']['bindings'], 'path'));
    }

    public function test_xlsx_compiler_preserves_source_styles_and_uses_preallocated_wrapped_item_rows(): void
    {
        $source = $this->xlsxBytes();
        $compiled = $this->renderer()->compileWorkingSource($source, $this->xlsxSchema(), $this->context([
            'request' => ['number' => 'REQ-2026-0001'],
            'items' => ['records' => [
                ['qty' => '1', 'description' => 'Short item', 'unit' => 'pc'],
                ['qty' => '2', 'description' => str_repeat('Long wrapped description ', 12), 'unit' => 'set'],
            ]],
        ]));

        /* The upload itself is never mutated - only a temporary clone is compiled. */
        $this->assertStringContainsString('REQUEST PLACEHOLDER', $this->zipEntry($source, 'xl/worksheets/sheet1.xml'));
        $sheet = $this->zipEntry($compiled['bytes'], 'xl/worksheets/sheet1.xml');
        $styles = $this->zipEntry($compiled['bytes'], 'xl/styles.xml');
        $this->assertStringContainsString('REQ-2026-0001', $sheet);
        $this->assertStringContainsString('Long wrapped description', $sheet);
        $this->assertStringNotContainsString('customHeight="1"', $sheet);
        $this->assertStringContainsString('wrapText="1"', $styles);
        $this->assertSame('items.records', collect($compiled['plan']['resolver']['bindings'])->last()['path']);
    }

    public function test_xlsx_capacity_overflow_is_blocking_instead_of_clipping_or_expanding_unknown_layout(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('more item records than the approved Office layout can display safely');

        $this->renderer()->compileWorkingSource($this->xlsxBytes(), $this->xlsxSchema(), $this->context([
            'request' => ['number' => 'REQ-2026-0001'],
            'items' => ['records' => [
                ['qty' => '1', 'description' => 'One', 'unit' => 'pc'],
                ['qty' => '2', 'description' => 'Two', 'unit' => 'pc'],
                ['qty' => '3', 'description' => 'Three', 'unit' => 'pc'],
            ]],
        ]));
    }

    public function test_ambiguous_human_label_is_not_populated_as_a_runtime_path(): void
    {
        $schema = $this->docxSchema();
        $schema['office_identity']['content_controls'][0]['tag'] = 'Borrower Name';
        $compiled = $this->renderer()->compileWorkingSource($this->docxBytes(), $schema, $this->context([
            'borrower' => ['full_name' => 'Authorized Borrower'],
            'items' => ['records' => []],
        ]));

        $document = $this->zipEntry($compiled['bytes'], 'word/document.xml');
        $this->assertStringContainsString('SOURCE BORROWER', $document);
        $this->assertContains('NO_HIGH_CONFIDENCE_OFFICE_BINDINGS', array_column($compiled['plan']['compatibility_warnings'], 'code'));
    }

    private function renderer(): OfficeDraftTemplateRenderer
    {
        return new OfficeDraftTemplateRenderer(Mockery::mock(ProtectedFileService::class));
    }

    /** @param array<string,array<string,mixed>> $namespaces @return array<string,mixed> */
    private function context(array $namespaces): array
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
            'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:sdt><w:sdtPr><w:id w:val="1"/><w:tag w:val="borrower.full_name"/></w:sdtPr><w:sdtContent><w:p><w:r><w:t>SOURCE BORROWER</w:t></w:r></w:p></w:sdtContent></w:sdt><w:tbl><w:sdt><w:sdtPr><w:id w:val="2"/><w:tag w:val="items.records"/></w:sdtPr><w:sdtContent><w:tr><w:trPr><w:trHeight w:val="240" w:hRule="exact"/></w:trPr><w:tc><w:p><w:sdt><w:sdtPr><w:tag w:val="items.qty"/></w:sdtPr><w:sdtContent><w:p><w:r><w:t>QTY</w:t></w:r></w:p></w:sdtContent></w:sdt></w:p></w:tc><w:tc><w:p><w:sdt><w:sdtPr><w:tag w:val="items.description"/></w:sdtPr><w:sdtContent><w:p><w:r><w:t>DESCRIPTION</w:t></w:r></w:p></w:sdtContent></w:sdt></w:p></w:tc></w:tr></w:sdtContent></w:sdt></w:tbl><w:sectPr/></w:body></w:document>',
        ]);
    }

    private function xlsxBytes(): string
    {
        return $this->zip([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Input" sheetId="1" r:id="rId1"/></sheets><definedNames><definedName name="request.number">Input!$A$5</definedName></definedNames></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/></Relationships>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheetData><row r="1"><c r="A1" s="0" t="inlineStr"><is><t>qty</t></is></c><c r="B1" s="0" t="inlineStr"><is><t>description</t></is></c><c r="C1" s="0" t="inlineStr"><is><t>unit</t></is></c></row><row r="2" ht="18" customHeight="1"><c r="A2" s="0" t="inlineStr"><is><t>Q1</t></is></c><c r="B2" s="0" t="inlineStr"><is><t>D1</t></is></c><c r="C2" s="0" t="inlineStr"><is><t>U1</t></is></c></row><row r="3" ht="18" customHeight="1"><c r="A3" s="0" t="inlineStr"><is><t>Q2</t></is></c><c r="B3" s="0" t="inlineStr"><is><t>D2</t></is></c><c r="C3" s="0" t="inlineStr"><is><t>U2</t></is></c></row><row r="5" ht="18" customHeight="1"><c r="A5" s="0" t="inlineStr"><is><t>REQUEST PLACEHOLDER</t></is></c></row></sheetData><tableParts count="1"><tablePart r:id="rId2"/></tableParts></worksheet>',
            'xl/worksheets/_rels/sheet1.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId2" Target="../tables/table1.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table"/></Relationships>',
            'xl/tables/table1.xml' => '<?xml version="1.0"?><table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" id="1" name="spmu__items__records" displayName="spmu__items__records" ref="A1:C3"><tableColumns count="3"><tableColumn id="1" name="qty"/><tableColumn id="2" name="description"/><tableColumn id="3" name="unit"/></tableColumns></table>',
            'xl/styles.xml' => '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font/></fonts><fills count="1"><fill/></fills><borders count="1"><border/></borders><cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellXfs></styleSheet>',
        ]);
    }

    /** @param array<string,string> $entries */
    private function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'spmu-office-renderer-test-');
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
        $path = tempnam(sys_get_temp_dir(), 'spmu-office-renderer-read-');
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
