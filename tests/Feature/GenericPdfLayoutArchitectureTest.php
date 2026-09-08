<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\StoredFile;
use App\Services\DocumentTemplateLayoutService;
use App\Services\DocumentTemplateRenderer;
use App\Services\SimplePdfService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenericPdfLayoutArchitectureTest extends TestCase
{
    public function test_two_visually_different_borrower_slip_revisions_use_one_generic_reader_and_interpreter(): void
    {
        Storage::fake('local');
        $layouts = app(DocumentTemplateLayoutService::class);
        $renderer = app(DocumentTemplateRenderer::class);
        $fields = ['items.qty', 'items.unit', 'items.description', 'purpose', 'expected_return_date', 'date_released', 'release_time', 'date_returned', 'remarks'];
        $positions = [];

        foreach (['revision-a' => false, 'revision-b' => true] as $name => $alternate) {
            $bytes = $this->borrowerSlipRevision($alternate);

            // Upload acceptance has no filename or revision dependency.
            $upload = UploadedFile::fake()->createWithContent($name.'.pdf', $bytes);
            $this->assertSame('PDF', $layouts->inspectUpload($upload)['format']);

            $review = $layouts->inspectProductionPdf($bytes, true);
            $prepared = $layouts->autoMap('BORROWER_SLIP', 'PDF', $review);
            $this->assertSame('TEXT_LAYER', $review['layout_source']);
            $this->assertNotEmpty($review['layout_model']['pages']);
            $this->assertNotEmpty($review['layout_model']['phrases']);
            $this->assertTrue($prepared['analysis']['ready']);
            $this->assertEqualsCanonicalizing($fields, array_values(array_intersect($fields, array_column($prepared['mappings'], 'field'))));

            // Preparation produces the same type payload and the same
            // production renderer that serves a generated preview.
            $template = $this->inMemoryTemplate($name, $bytes, $prepared);
            $preview = $renderer->render($template, $renderer->sampleData('BORROWER_SLIP'));
            $this->assertStringStartsWith('%PDF-', $preview);
            $positions[$name] = collect($prepared['mappings'])->firstWhere('field', 'items.qty');
        }

        // The two revisions intentionally put the item table in different
        // places and use different column widths. The parser did not branch
        // by a version or filename to prepare either one.
        $this->assertNotSame($positions['revision-a']['x'], $positions['revision-b']['x']);
        $this->assertNotSame($positions['revision-a']['y'], $positions['revision-b']['y']);
    }

    public function test_generic_reader_reads_unrelated_and_scanned_pdfs_without_borrower_semantic_match(): void
    {
        $layouts = app(DocumentTemplateLayoutService::class);
        $unrelated = app(SimplePdfService::class)->make(['Meeting Minutes', 'Attendance and agenda only.']);
        $unrelatedReview = $layouts->inspectProductionPdf($unrelated, true);
        $unrelatedMapping = $layouts->autoMap('BORROWER_SLIP', 'PDF', $unrelatedReview);

        $this->assertSame('TEXT_LAYER', $unrelatedReview['layout_source']);
        $this->assertNotEmpty($unrelatedReview['layout_model']['pages']);
        $this->assertFalse($unrelatedMapping['analysis']['ready']);

        $scanned = $this->scannedPdf();
        $scannedReview = $layouts->inspectProductionPdf($scanned, true);
        $scannedMapping = $layouts->autoMap('BORROWER_SLIP', 'PDF', $scannedReview);

        $this->assertSame('OCR', $scannedReview['layout_source']);
        $this->assertNotEmpty($scannedReview['layout_words']);
        $this->assertNotEmpty($scannedReview['layout_model']['pages']);
        $this->assertFalse($scannedMapping['analysis']['ready']);
    }

    public function test_all_other_supported_document_types_prepare_two_meaningfully_different_layouts_without_revision_rules(): void
    {
        Storage::fake('local');
        $layouts = app(DocumentTemplateLayoutService::class);
        $renderer = app(DocumentTemplateRenderer::class);

        $requiredByType = [
            'GATE_PASS' => ['purpose', 'items.qty', 'items.unit', 'items.description'],
            'LAUNDRY_FORM' => ['items.qty', 'items.unit', 'items.description'],
            'BILLING_STATEMENT' => ['billing_no', 'borrower_name', 'items.description', 'items.amount', 'total_amount'],
            'RSLDDP' => ['incident_no', 'borrower_name', 'items.qty', 'items.description', 'items.condition'],
        ];

        foreach ($requiredByType as $type => $requiredFields) {
            $positions = [];
            foreach (['layout-a' => false, 'layout-b' => true] as $revision => $alternate) {
                $source = $this->supportedDocumentRevision($type, $alternate);
                $review = $layouts->inspectProductionPdf($source, true);
                $prepared = $layouts->autoMap($type, 'PDF', $review);

                $this->assertSame('TEXT_LAYER', $review['layout_source'], $type.' '.$revision);
                $this->assertNotEmpty($review['layout_model']['pages'], $type.' '.$revision);
                $this->assertTrue($prepared['analysis']['ready'], $type.' '.$revision);
                $this->assertEqualsCanonicalizing(
                    $requiredFields,
                    array_values(array_intersect($requiredFields, array_column($prepared['mappings'], 'field'))),
                    $type.' '.$revision,
                );

                $template = $this->inMemoryTemplate($type.'-'.$revision, $source, $prepared, $type);
                $preview = $renderer->render($template, $renderer->sampleData($type));
                $this->assertStringStartsWith('%PDF-', $preview, $type.' '.$revision);
                $positions[$revision] = collect($prepared['mappings'])->firstWhere('field', $requiredFields[0]);
            }

            // Layout B moves the header/table and changes table widths. Both
            // go through the same generic reader and configured aliases.
            $this->assertNotSame($positions['layout-a']['x'], $positions['layout-b']['x'], $type);
            $this->assertNotSame($positions['layout-a']['y'], $positions['layout-b']['y'], $type);
        }
    }

    public function test_dynamic_rows_stay_inside_the_detected_table_body_and_fail_at_capacity(): void
    {
        Storage::fake('local');
        $layouts = app(DocumentTemplateLayoutService::class);
        $renderer = app(DocumentTemplateRenderer::class);
        // The release/return grid intentionally has three approved blank
        // rows, so a multi-line Remarks value can be verified without
        // creating a continuation page or entering the following section.
        $source = $this->borrowerSlipRevision(false, 5, 3);
        $review = $layouts->inspectProductionPdf($source, true);
        $prepared = $layouts->autoMap('BORROWER_SLIP', 'PDF', $review);
        $template = $this->inMemoryTemplate('dynamic-rows', $source, $prepared);

        $this->assertArrayHasKey('grid', $prepared['table_layouts']['borrowed_items']);
        $this->assertArrayHasKey('grid', $prepared['table_layouts']['release_return']);
        $data = [
            ...$renderer->sampleData('BORROWER_SLIP'),
            'items' => [
                [
                    'qty' => '1',
                    'unit' => 'Piece',
                    'description' => 'Portable presentation kit',
                ],
                ['qty' => '2', 'unit' => 'Piece', 'description' => 'Short item'],
            ],
            'purpose' => 'Academic demonstration with class instruction',
            'remarks' => 'Returned after inspection with the accessories counted and the equipment placed back into the assigned controlled storage area.',
        ];

        // Short and multi-line cells share the same renderer used by the
        // preview route and by final controlled-document generation.
        $rendered = $renderer->render($template, $data);
        $this->assertStringStartsWith('%PDF-', $rendered);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->expectExceptionMessage('This document contains more item text than the approved form can display safely.');
        $renderer->render($template, [
            ...$data,
            'items' => array_fill(0, 6, ['qty' => '1', 'unit' => 'Piece', 'description' => 'Item', 'purpose' => 'Purpose']),
        ]);
    }

    public function test_dynamic_table_safety_is_shared_by_every_supported_document_type(): void
    {
        Storage::fake('local');
        $layouts = app(DocumentTemplateLayoutService::class);
        $renderer = app(DocumentTemplateRenderer::class);

        foreach (['GATE_PASS', 'LAUNDRY_FORM', 'BILLING_STATEMENT', 'RSLDDP'] as $type) {
            $source = $this->supportedDocumentRevision($type, true);
            $prepared = $layouts->autoMap($type, 'PDF', $layouts->inspectProductionPdf($source, true));
            $template = $this->inMemoryTemplate('dynamic-'.$type, $source, $prepared, $type);
            $sample = $renderer->sampleData($type);
            $item = $sample['items'][0];
            $item['description'] = 'Long authoritative article description that wraps safely within the detected table column without entering the next column or the fixed official section below.';
            $item['remarks'] = 'Long recorded findings remain inside the approved table body and use the same dynamic row height as every other column.';
            $item['basis'] = 'Existing accountability basis with enough words to demonstrate column wrapping and safe calculated row height.';
            $item['disposition'] = 'Existing disposition text that is retained without truncation and remains inside the detected official table body.';

            // One intentionally multi-line row must remain within every
            // revision's detected fixed table body. Borrower's Slip covers
            // the separate multiple-row/different-height regression case.
            $rendered = $renderer->render($template, [...$sample, 'items' => [$item]]);
            $this->assertStringStartsWith('%PDF-', $rendered, $type);

            try {
                $renderer->render($template, [...$sample, 'items' => array_fill(0, 6, $item)]);
                $this->fail($type.' should fail before writing beyond the approved table body.');
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $this->assertStringContainsString('This document contains more item text than the approved form can display safely.', $exception->getMessage(), $type);
            }
        }
    }

    /** @param array<string,mixed> $prepared */
    private function inMemoryTemplate(string $name, string $bytes, array $prepared, string $type = 'BORROWER_SLIP'): DocumentTemplate
    {
        $path = 'generic-layout-tests/'.$name.'.pdf';
        Storage::disk('local')->put($path, $bytes);
        $file = new StoredFile([
            'disk' => 'local',
            'storage_path' => $path,
            'original_name' => $name.'.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($bytes),
            'classification' => 'DOCUMENT_TEMPLATE_RENDER',
        ]);
        $template = new DocumentTemplate([
            'document_type' => $type,
            'source_mode' => 'OFFICIAL_LAYOUT',
            'content_template' => json_encode([
                'format' => 'PDF',
                'render_representation' => 'DIRECT_PDF',
                'mappings' => $prepared['mappings'],
                'table_layouts' => $prepared['table_layouts'],
                'analysis' => $prepared['analysis'],
            ]),
        ]);
        $template->setRelation('renderFile', $file);

        return $template;
    }

    private function borrowerSlipRevision(bool $alternate, int $rows = 5, int $releaseRows = 1): string
    {
        $pdf = new \FPDF('P', 'pt', [612, 792]);
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', 'B', 14);
        $pdf->Text($alternate ? 205 : 220, 42, "BORROWER'S SLIP");
        $pdf->SetFont('Helvetica', '', 9);
        $top = $alternate ? 83 : 67;
        $pdf->Text($alternate ? 52 : 34, $top, 'Date:');
        $pdf->Line($alternate ? 84 : 67, $top + 2, $alternate ? 200 : 178, $top + 2);
        $pdf->Text($alternate ? 232 : 208, $top, 'Employee');
        $pdf->Rect($alternate ? 218 : 194, $top - 8, 8, 8);
        $pdf->Text($alternate ? 342 : 312, $top, 'Others');
        $pdf->Rect($alternate ? 328 : 298, $top - 8, 8, 8);

        $x = $alternate ? 48.0 : 30.0;
        $y = $alternate ? 151.0 : 132.0;
        $widths = $alternate ? [48, 72, 164, 108, 150] : [58, 64, 142, 132, 146];
        $tableWidth = array_sum($widths);
        $rowHeight = $alternate ? 24 : 20;
        $pdf->SetFont('Helvetica', 'B', 8);
        $headers = ['Qty.', 'Unit', 'Article / Description', 'Purpose', 'Expected Date of Return'];
        $cursor = $x;
        foreach ($headers as $index => $header) {
            $pdf->Text($cursor + 3, $y + 14, $header);
            $cursor += $widths[$index];
        }
        $pdf->Rect($x, $y, $tableWidth, $rowHeight * ($rows + 1));
        $cursor = $x;
        foreach ($widths as $width) {
            $pdf->Line($cursor, $y, $cursor, $y + $rowHeight * ($rows + 1));
            $cursor += $width;
        }
        for ($row = 1; $row <= $rows; $row++) {
            $pdf->Line($x, $y + $row * $rowHeight, $x + $tableWidth, $y + $row * $rowHeight);
        }
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Text($x, $y + $rowHeight * ($rows + 1) + 18, 'Terms and Conditions');

        $releaseY = $y + $rowHeight * ($rows + 1) + 45;
        $releaseWidths = $alternate ? [130, 110, 130, 172] : [120, 115, 125, 182];
        $pdf->SetFont('Helvetica', 'B', 8);
        $cursor = $x;
        foreach (['Date Released', 'Release Time', 'Date Returned', 'Remarks'] as $index => $header) {
            $pdf->Text($cursor + 3, $releaseY + 14, $header);
            $cursor += $releaseWidths[$index];
        }
        $pdf->Rect($x, $releaseY, array_sum($releaseWidths), $rowHeight * ($releaseRows + 1));
        $cursor = $x;
        foreach ($releaseWidths as $width) {
            $pdf->Line($cursor, $releaseY, $cursor, $releaseY + $rowHeight * ($releaseRows + 1));
            $cursor += $width;
        }
        for ($row = 1; $row <= $releaseRows; $row++) {
            $pdf->Line($x, $releaseY + $row * $rowHeight, $x + array_sum($releaseWidths), $releaseY + $row * $rowHeight);
        }

        return $pdf->Output('S');
    }

    private function supportedDocumentRevision(string $type, bool $alternate): string
    {
        $specifications = [
            'GATE_PASS' => [
                'title' => 'GATE PASS',
                'header' => ['Gate Pass Number', 'Request Number', 'Custody Number', 'Purpose', 'Destination'],
                'columns' => ['Qty.', 'Unit', 'Item Description', 'Premises'],
                'signatory' => 'Requested By',
            ],
            'LAUNDRY_FORM' => [
                'title' => 'LAUNDRY FORM',
                'header' => ['Request Number', 'Custody Number', 'Borrower', 'Requesting Office', 'Date Requested'],
                'columns' => ['Qty.', 'Unit', 'Article / Description', 'Date of Receipt', 'Remarks'],
                'signatory' => 'Requested By',
            ],
            'BILLING_STATEMENT' => [
                'title' => 'BILLING STATEMENT',
                'header' => ['Billing Statement Number', 'Borrower', 'Issued Date', 'Due Date', 'Total Amount'],
                'columns' => ['Particulars', 'Basis for Charge', 'Assessed Amount'],
                'signatory' => 'Issued By',
            ],
            'RSLDDP' => [
                'title' => 'RSLDDP REPORT',
                'header' => ['Incident Number', 'Borrower', 'Custody Reference', 'Date Reported', 'Appraisal Amount'],
                'columns' => ['No. of Units', 'Property Description', 'Condition Found', 'Disposition'],
                'signatory' => 'Reported By',
            ],
        ];
        $specification = $specifications[$type];
        $pdf = new \FPDF('P', 'pt', [612, 792]);
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', 'B', 14);
        $pdf->Text($alternate ? 205 : 232, $alternate ? 54 : 40, $specification['title']);

        $left = $alternate ? 58.0 : 34.0;
        $headerY = $alternate ? 92.0 : 68.0;
        $pdf->SetFont('Helvetica', '', 8.5);
        foreach ($specification['header'] as $index => $label) {
            $y = $headerY + $index * ($alternate ? 20 : 17);
            $pdf->Text($left, $y, $label.':');
            $pdf->Line($left + 125, $y + 2, $alternate ? 490 : 520, $y + 2);
        }

        $tableY = $headerY + count($specification['header']) * ($alternate ? 20 : 17) + ($alternate ? 34 : 26);
        $availableWidth = $alternate ? 492.0 : 544.0;
        $weights = $alternate
            ? array_reverse([1.0, 1.15, 2.45, 1.5, 1.25])
            : [1.0, 1.15, 2.45, 1.5, 1.25];
        $columnCount = count($specification['columns']);
        $weights = array_slice($weights, 0, $columnCount);
        $weightTotal = array_sum($weights);
        $widths = array_map(fn (float $weight): float => ($weight / $weightTotal) * $availableWidth, $weights);
        $rowHeight = $alternate ? 25.0 : 21.0;
        $bodyRows = 5;
        $tableHeight = $rowHeight * ($bodyRows + 1);

        $pdf->SetFont('Helvetica', 'B', 8);
        $cursor = $left;
        foreach ($specification['columns'] as $index => $label) {
            $pdf->Text($cursor + 3, $tableY + 14, $label);
            $cursor += $widths[$index];
        }
        $pdf->Rect($left, $tableY, $availableWidth, $tableHeight);
        $cursor = $left;
        foreach ($widths as $width) {
            $pdf->Line($cursor, $tableY, $cursor, $tableY + $tableHeight);
            $cursor += $width;
        }
        for ($row = 1; $row <= $bodyRows; $row++) {
            $pdf->Line($left, $tableY + $row * $rowHeight, $left + $availableWidth, $tableY + $row * $rowHeight);
        }
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->Text($left, $tableY + $tableHeight + 19, 'Official notes and fixed conditions');

        // The signatory matrix has no copied coordinates: it supplies a
        // second semantic context whose locations differ for each revision.
        $matrixY = $tableY + $tableHeight + ($alternate ? 55 : 45);
        $matrixWidth = $alternate ? 330.0 : 360.0;
        $matrixLeft = $alternate ? 150.0 : 126.0;
        $matrixRowHeight = 17.0;
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->Text($matrixLeft + 8, $matrixY + 12, $specification['signatory']);
        $pdf->Rect($matrixLeft, $matrixY, $matrixWidth, $matrixRowHeight * 5);
        $pdf->Line($matrixLeft + 90, $matrixY, $matrixLeft + 90, $matrixY + $matrixRowHeight * 5);
        foreach (['Signature', 'Printed Name', 'Designation', 'Date'] as $index => $label) {
            $y = $matrixY + $matrixRowHeight * ($index + 1);
            $pdf->SetFont('Helvetica', '', 7.5);
            $pdf->Text($matrixLeft + 4, $y + 11, $label);
            $pdf->Line($matrixLeft, $y, $matrixLeft + $matrixWidth, $y);
        }

        return $pdf->Output('S');
    }

    private function scannedPdf(): string
    {
        $imagePath = tempnam(sys_get_temp_dir(), 'spmu-scanned-layout-').'.png';
        $image = imagecreatetruecolor(1400, 1800);
        $white = imagecolorallocate($image, 255, 255, 255);
        $ink = imagecolorallocate($image, 0, 0, 0);
        imagefill($image, 0, 0, $white);
        $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
        imagettftext($image, 46, 0, 120, 220, $ink, $font, 'UNRELATED SCANNED MEMO');
        imagettftext($image, 30, 0, 120, 310, $ink, $font, 'This scanned document is not a Borrower Slip.');
        imagepng($image, $imagePath);
        imagedestroy($image);

        try {
            $pdf = new \FPDF('P', 'pt', [612, 792]);
            $pdf->AddPage();
            $pdf->Image($imagePath, 0, 0, 612, 792, 'PNG');

            return $pdf->Output('S');
        } finally {
            @unlink($imagePath);
        }
    }
}
