<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Services\DocumentService;
use App\Services\OfficialFormRuntimeContextService;
use Mockery;
use Tests\TestCase;

class OfficialFormRuntimeContextTest extends TestCase
{
    public function test_office_draft_context_accepts_only_sanitized_semantic_values(): void
    {
        $documents = Mockery::mock(DocumentService::class);
        $documents->shouldReceive('runtimePayloadForDraftPreview')
            ->once()
            ->with('BORROWER_SLIP')
            ->andReturn([
                'borrower_name' => 'Authorized Borrower',
                'purpose' => 'Authorized activity',
                'request_no' => 'BR-2026-0001',
                'borrowed_by_signature' => ['kind' => 'signature_image', 'bytes' => 'never-exposed'],
                'internal_token' => 'never-exposed',
                'items' => [[
                    'qty' => '2',
                    'description' => 'Projector',
                    'file_path' => '/private/path',
                ]],
            ]);

        $context = (new OfficialFormRuntimeContextService($documents))->forOfficeDraft($this->officeDraft());

        $this->assertSame('AUTHORIZED_WORKFLOW_RECORD', $context['source']['kind']);
        $this->assertSame('Authorized Borrower', $context['namespaces']['borrower']['full_name']);
        $this->assertSame('Authorized activity', $context['namespaces']['request']['purpose']);
        $this->assertSame('BR-2026-0001', $context['namespaces']['request']['number']);
        $this->assertSame([['qty' => '2', 'description' => 'Projector']], $context['namespaces']['items']['records']);
        $this->assertContains('borrower.full_name', $context['available_paths']);
        $this->assertArrayNotHasKey('borrowed_by_signature', $context['render_values']);
        $this->assertArrayNotHasKey('internal_token', $context['render_values']);
        $this->assertStringNotContainsString('never-exposed', json_encode($context, JSON_THROW_ON_ERROR));
    }

    public function test_runtime_values_replace_only_matching_synthetic_preview_fields(): void
    {
        $documents = Mockery::mock(DocumentService::class);
        $documents->shouldReceive('runtimePayloadForDraftPreview')->once()->andReturn([
            'borrower_name' => 'Authorized Borrower',
        ]);
        $service = new OfficialFormRuntimeContextService($documents);
        $context = $service->forOfficeDraft($this->officeDraft());

        $preview = $service->mergeWithSyntheticSample([
            'borrower_name' => 'Sample Borrower',
            'borrowed_by_signature' => ['kind' => 'signature_image', 'bytes' => 'synthetic'],
        ], $context);

        $this->assertSame('Authorized Borrower', $preview['borrower_name']);
        $this->assertSame('synthetic', $preview['borrowed_by_signature']['bytes']);
    }

    public function test_office_draft_synthetic_context_uses_safe_paths_without_signature_values(): void
    {
        $service = new OfficialFormRuntimeContextService(Mockery::mock(DocumentService::class));
        $context = [
            'schema_version' => 1,
            'form_type' => 'BORROWER_SLIP',
            'source' => ['kind' => 'SYNTHETIC_DEMO'],
            'namespaces' => [],
            'available_paths' => [],
            'render_values' => [],
        ];

        $officeContext = $service->withSyntheticDemo($context, [
            'borrower_name' => 'Sample Borrower',
            'request_no' => 'BR-2026-0001',
            'items' => [['qty' => '1', 'description' => 'Projector']],
            'borrowed_by_signature' => ['kind' => 'signature_image', 'bytes' => 'never-exposed'],
        ]);

        $this->assertSame('Sample Borrower', $officeContext['namespaces']['borrower']['full_name']);
        $this->assertSame('BR-2026-0001', $officeContext['namespaces']['request']['number']);
        $this->assertSame([['qty' => '1', 'description' => 'Projector']], $officeContext['namespaces']['items']['records']);
        $this->assertContains('items.records', $officeContext['available_paths']);
        $this->assertStringNotContainsString('never-exposed', json_encode($officeContext, JSON_THROW_ON_ERROR));
    }

    public function test_active_and_non_office_templates_never_query_runtime_preview_data(): void
    {
        $documents = Mockery::mock(DocumentService::class);
        $documents->shouldNotReceive('runtimePayloadForDraftPreview');
        $service = new OfficialFormRuntimeContextService($documents);

        $activeOfficeTemplate = $this->officeDraft(['status' => 'ACTIVE']);
        $pdfDraft = $this->officeDraft(['dynamic_schema' => ['source' => ['format' => 'PDF']]]);

        $this->assertNull($service->forOfficeDraft($activeOfficeTemplate));
        $this->assertNull($service->forOfficeDraft($pdfDraft));
    }

    /** @param array<string,mixed> $overrides */
    private function officeDraft(array $overrides = []): DocumentTemplate
    {
        return new DocumentTemplate([
            'document_type' => 'BORROWER_SLIP',
            'source_mode' => 'OFFICIAL_LAYOUT',
            'status' => 'READY_FOR_PREVIEW',
            'dynamic_schema' => ['source' => ['format' => 'DOCX']],
            ...$overrides,
        ]);
    }
}
