<?php

namespace Tests\Unit;

use App\Services\DocumentPreviewGeometryService;
use PHPUnit\Framework\TestCase;

class DocumentPreviewGeometryServiceTest extends TestCase
{
    public function test_it_detects_portrait_from_the_pdf_page_box(): void
    {
        $pdf = '%PDF-1.7 1 0 obj << /Type /Page /MediaBox [0 0 612 792] >> endobj';

        $geometry = (new DocumentPreviewGeometryService)->inspectPdfBytes($pdf);

        $this->assertSame('portrait', $geometry['orientation']);
        $this->assertEqualsWithDelta(612 / 792, $geometry['ratio'], 0.0001);
    }

    public function test_it_detects_landscape_without_document_name_rules(): void
    {
        $pdf = '%PDF-1.7 9 0 obj << /Type /Page /MediaBox [0 0 841.89 595.28] >> endobj';

        $geometry = (new DocumentPreviewGeometryService)->inspectPdfBytes($pdf);

        $this->assertSame('landscape', $geometry['orientation']);
    }

    public function test_rotation_changes_the_effective_orientation(): void
    {
        $pdf = '%PDF-1.7 3 0 obj << /Type /Page /MediaBox [0 0 612 792] /Rotate 90 >> endobj';

        $geometry = (new DocumentPreviewGeometryService)->inspectPdfBytes($pdf);

        $this->assertSame('landscape', $geometry['orientation']);
    }

    public function test_it_honors_inherited_page_geometry_and_rotation(): void
    {
        $pdf = '%PDF-1.7 '
            .'1 0 obj << /Type /Page /Parent 2 0 R >> endobj '
            .'2 0 obj << /Type /Pages /MediaBox [0 0 612 792] /Rotate 90 >> endobj';

        $geometry = (new DocumentPreviewGeometryService)->inspectPdfBytes($pdf);

        $this->assertSame('landscape', $geometry['orientation']);
    }

    public function test_unknown_geometry_falls_back_safely(): void
    {
        $geometry = (new DocumentPreviewGeometryService)->inspectPdfBytes('%PDF-1.7');

        $this->assertSame('unknown', $geometry['orientation']);
        $this->assertNull($geometry['ratio']);
    }
}
