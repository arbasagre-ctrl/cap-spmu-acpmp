<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FixedDocumentTemplateScopeTest extends TestCase
{
    #[Test]
    public function uploaded_editable_templates_cannot_override_production_forms(): void
    {
        $source = (string) file_get_contents(app_path('Services/DocumentService.php'));
        $start = strpos($source, 'private function activeUploadedTemplate(');
        $end = strpos($source, '/**', $start ?: 0);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $method = substr($source, $start, $end - $start);

        $this->assertStringContainsString('return null;', $method);
        $this->assertStringNotContainsString("source_mode === 'OFFICIAL_LAYOUT'", $method);
    }
}
