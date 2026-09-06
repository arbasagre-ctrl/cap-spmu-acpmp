<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\DocumentTemplateLayoutService;
use App\Services\DocumentTemplateRenderer;
use App\Services\ProtectedFileService;
use App\Services\SimplePdfService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

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

    public function test_activated_pdf_layout_uses_the_same_production_renderer_as_preview(): void
    {
        $current = DocumentTemplate::query()->where('document_type', 'BORROWER_SLIP')->where('template_version', 1)->firstOrFail();
        $source = $this->systemReadyBorrowerSlipSource();

        $this->asHead()->post(route('administration.document-templates.draft.store', 'borrower-slip'), [
            'version_label' => 'v1.1',
            'reason' => 'Approved official source revision.',
            'template_file' => UploadedFile::fake()->createWithContent('borrower-slip.pdf', $source),
        ])->assertRedirect();

        $template = DocumentTemplate::query()->where('document_type', 'BORROWER_SLIP')->where('version_label', 'v1.1')->with(['file', 'renderFile'])->firstOrFail();
        $this->assertSame('NEEDS_PREPARATION', $template->status);
        $this->assertSame($source, app(ProtectedFileService::class)->bytes($template->file));
        $this->assertSame($template->file->id, $template->renderFile->id);
        $this->asHead()->get(route('administration.settings.index', ['section' => 'template-borrower-slip']))
            ->assertOk()
            ->assertSee('Template needs preparation')
            ->assertSee('Prepare Automatically')
            ->assertDontSee('Select on form')
            ->assertDontSee('Confirm Production Positions');

        $this->asHead()->post(route('administration.document-templates.prepare', ['type' => 'borrower-slip', 'template' => $template]))->assertRedirect();
        $template->refresh();
        $this->assertSame('READY_FOR_PREVIEW', $template->status);
        $this->asHead()->get(route('administration.settings.index', ['section' => 'template-borrower-slip']))
            ->assertOk()
            ->assertSee('Layout prepared')
            ->assertSee('Preview Generated Sample');

        $renderer = app(DocumentTemplateRenderer::class);
        $this->assertStringStartsWith('%PDF-', $renderer->render($template, $renderer->sampleData('BORROWER_SLIP')));

        $this->asHead()->post(route('administration.document-templates.activate', ['type' => 'borrower-slip', 'template' => $template]), ['preview_confirmed' => true])->assertRedirect();
        $this->assertSame('ACTIVE', $template->fresh()->status);
        $this->assertSame('HISTORICAL', $current->fresh()->status);
        $this->assertSame('v1.1', SystemSetting::query()->where('setting_key', 'borrower_slip_template_version')->firstOrFail()->value_json);
    }

    public function test_incomplete_or_unmapped_layout_cannot_activate(): void
    {
        $this->asHead()->post(route('administration.document-templates.draft.store', 'borrower-slip'), [
            'version_label' => 'v1.2',
            'reason' => 'Approved source needs automatic preparation.',
            'template_file' => UploadedFile::fake()->createWithContent('borrower-slip.pdf', app(SimplePdfService::class)->make(['Purpose'])),
        ])->assertRedirect();

        $template = DocumentTemplate::query()->where('document_type', 'BORROWER_SLIP')->where('version_label', 'v1.2')->firstOrFail();
        $this->assertSame('NEEDS_PREPARATION', $template->status);
        $this->asHead()->post(route('administration.document-templates.prepare', ['type' => 'borrower-slip', 'template' => $template]))->assertRedirect();
        $template->refresh();
        $this->assertSame('NEEDS_PREPARATION', $template->status);
        $this->asHead()->post(route('administration.document-templates.activate', ['type' => 'borrower-slip', 'template' => $template]), ['preview_confirmed' => true])->assertStatus(422);
    }

    public function test_flat_pdf_is_reidentified_from_its_preserved_header_when_format_metadata_is_missing(): void
    {
        $source = app(SimplePdfService::class)->make(['APPROVED BORROWER SLIP', 'Purpose', 'Remarks']);
        $this->asHead()->post(route('administration.document-templates.draft.store', 'borrower-slip'), [
            'version_label' => 'v1.25',
            'reason' => 'Flat approved PDF source.',
            'template_file' => UploadedFile::fake()->createWithContent('borrower-slip-flat.pdf', $source),
        ])->assertRedirect();

        $template = DocumentTemplate::query()->where('document_type', 'BORROWER_SLIP')->where('version_label', 'v1.25')->with(['file', 'renderFile'])->firstOrFail();
        $configuration = json_decode((string) $template->content_template, true);
        unset($configuration['format']);
        $template->update(['content_template' => json_encode($configuration)]);

        $this->asHead()->post(route('administration.document-templates.prepare', ['type' => 'borrower-slip', 'template' => $template]))->assertRedirect();
        $template->refresh();
        $prepared = json_decode((string) $template->content_template, true);

        $this->assertSame('PDF', $prepared['format']);
        $this->assertSame($source, app(ProtectedFileService::class)->bytes($template->file));
        $this->assertSame($template->file->id, $template->renderFile->id);
        $this->assertSame('NEEDS_PREPARATION', $template->status);
    }

    public function test_positioned_text_labels_are_prepared_without_form_fields_or_admin_mapping(): void
    {
        $words = [
            ['text' => 'Quantity', 'x' => 5, 'y' => 20], ['text' => 'Unit', 'x' => 15, 'y' => 20],
            ['text' => 'Article', 'x' => 25, 'y' => 20], ['text' => '/', 'x' => 33, 'y' => 20], ['text' => 'Description', 'x' => 35, 'y' => 20],
            ['text' => 'Purpose', 'x' => 58, 'y' => 20], ['text' => 'Expected', 'x' => 70, 'y' => 20], ['text' => 'Date', 'x' => 79, 'y' => 20], ['text' => 'of', 'x' => 84, 'y' => 20], ['text' => 'Return', 'x' => 87, 'y' => 20],
            ['text' => 'Date', 'x' => 5, 'y' => 58], ['text' => 'Released', 'x' => 10, 'y' => 58], ['text' => 'Release', 'x' => 30, 'y' => 58], ['text' => 'Time', 'x' => 38, 'y' => 58],
            ['text' => 'Date', 'x' => 52, 'y' => 58], ['text' => 'Returned', 'x' => 57, 'y' => 58], ['text' => 'Remarks', 'x' => 75, 'y' => 58],
        ];
        $words = array_map(fn (array $word): array => ['page' => 1, 'width' => 3, 'height' => 1.5, 'confidence' => 1.0, ...$word], $words);

        $layouts = app(DocumentTemplateLayoutService::class);
        $prepared = $layouts->autoMap('BORROWER_SLIP', 'PDF', [
            'layout_words' => $words,
            'layout_lines' => [],
            'fillable_widgets' => [],
            'labels' => [],
        ]);

        $this->assertTrue($prepared['analysis']['ready']);
        $this->assertSame(count($layouts->requiredFields('BORROWER_SLIP')), $prepared['analysis']['detected_count']);
        $this->assertNotEmpty($prepared['table_layouts']['borrowed_items']);
        $this->assertTrue(collect($prepared['mappings'])->every(fn (array $mapping): bool => $mapping['method'] === 'PDF_TABLE_HEADER'));
    }

    public function test_normal_borrower_slip_text_without_placeholders_is_mapped_from_visible_geometry(): void
    {
        // These are the positioned labels from the approved Borrower's Slip
        // layout, expressed as Poppler-style page-relative word boxes. No
        // AcroForm fields or {{field}} placeholders are available here.
        $words = [
            ['text' => 'will', 'x' => 35.5, 'y' => 20.64], ['text' => 'be', 'x' => 40.3, 'y' => 20.64], ['text' => 'used', 'x' => 43.1, 'y' => 20.64], ['text' => 'for', 'x' => 48.2, 'y' => 20.64],
            ['text' => 'returned', 'x' => 60.7, 'y' => 21.94], ['text' => 'on', 'x' => 66.5, 'y' => 21.94],
            ['text' => 'QTY.', 'x' => 14.66, 'y' => 23.86], ['text' => 'UNIT', 'x' => 37.13, 'y' => 23.86], ['text' => 'ARTICLE/DESCRIPTION', 'x' => 53.08, 'y' => 23.86],
            ['text' => 'Remarks', 'x' => 5.09, 'y' => 33.66], ['text' => 'upon', 'x' => 12.0, 'y' => 33.66], ['text' => 'return', 'x' => 17.2, 'y' => 33.66], ['text' => 'of', 'x' => 24.0, 'y' => 33.66], ['text' => 'items', 'x' => 27.0, 'y' => 33.66],
        ];
        $words = array_map(fn (array $word): array => ['page' => 1, 'width' => 3, 'height' => 1.1, 'confidence' => 1.0, ...$word], $words);

        $prepared = app(DocumentTemplateLayoutService::class)->autoMap('BORROWER_SLIP', 'PDF', [
            'layout_words' => $words,
            'layout_lines' => [],
            'fillable_widgets' => [],
            'labels' => [],
        ]);

        $this->assertTrue($prepared['analysis']['ready']);
        $this->assertSame('PDF_LABEL_VALUE_REGION', collect($prepared['mappings'])->firstWhere('field', 'purpose')['method']);
        $this->assertSame('PDF_LABEL_VALUE_REGION', collect($prepared['mappings'])->firstWhere('field', 'expected_return_date')['method']);
        $this->assertSame(2, $prepared['table_layouts']['borrowed_items']['max_rows']);
    }

    public function test_multiline_expected_return_header_is_matched_as_one_visible_column(): void
    {
        $words = [
            ['text' => 'Qty.', 'x' => 5.75, 'y' => 25.8], ['text' => 'Unit', 'x' => 15.37, 'y' => 25.8], ['text' => 'Article/Description', 'x' => 22.12, 'y' => 25.8], ['text' => 'Purpose', 'x' => 33.77, 'y' => 25.8],
            ['text' => 'Expected', 'x' => 41.69, 'y' => 25.12], ['text' => 'Date', 'x' => 45.61, 'y' => 25.12], ['text' => 'of', 'x' => 47.64, 'y' => 25.12], ['text' => 'Return', 'x' => 43.72, 'y' => 26.46],
            ['text' => 'Terms', 'x' => 1.87, 'y' => 54.75],
        ];
        $words = array_map(fn (array $word): array => ['page' => 1, 'width' => 1.6, 'height' => 1.1, 'confidence' => 1.0, ...$word], $words);

        $prepared = app(DocumentTemplateLayoutService::class)->autoMap('BORROWER_SLIP', 'PDF', [
            'layout_words' => $words,
            'layout_lines' => [],
            'fillable_widgets' => [],
            'labels' => [],
        ]);
        $expectedReturn = collect($prepared['mappings'])->firstWhere('field', 'expected_return_date');

        $this->assertTrue($prepared['analysis']['ready']);
        $this->assertSame('Expected Date of Return', $expectedReturn['location_label']);
        $this->assertGreaterThanOrEqual(0.99, $expectedReturn['confidence']);
        $this->assertSame('PDF_TABLE_HEADER', collect($prepared['mappings'])->firstWhere('field', 'items.unit')['method']);
    }

    public function test_scanned_style_borrower_slip_can_retry_automatic_preparation_without_manual_mapping(): void
    {
        $this->asHead()->post(route('administration.document-templates.draft.store', 'borrower-slip'), [
            'version_label' => 'v1.3',
            'reason' => 'Scanned approved layout needs structured replacement.',
            'template_file' => UploadedFile::fake()->createWithContent('borrower-slip-scanned.pdf', app(SimplePdfService::class)->make(['APPROVED BORROWER SLIP V2'])),
        ])->assertRedirect();

        $this->asHead()->post(route('administration.document-templates.prepare', ['type' => 'borrower-slip', 'template' => DocumentTemplate::query()->where('document_type', 'BORROWER_SLIP')->where('version_label', 'v1.3')->firstOrFail()]))
            ->assertRedirect()
            ->assertSessionMissing('status');

        $this->asHead()->get(route('administration.settings.index', ['section' => 'template-borrower-slip']))
            ->assertOk()
            ->assertSee("We couldn't automatically identify enough of this form to generate documents reliably.")
            ->assertSee('Try Again')
            ->assertSee('Replace File')
            ->assertDontSee('Select on form')
            ->assertDontSee('Confirm Production Positions')
            ->assertDontSee('need confirmation');
    }

    public function test_discard_removes_an_exclusive_unactivated_draft_but_never_the_active_layout(): void
    {
        $active = DocumentTemplate::query()->where('document_type', 'BORROWER_SLIP')->where('status', 'ACTIVE')->firstOrFail();
        $draft = $this->uploadBorrowerSlipDraft('v2.0');
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

        $draft = $this->uploadBorrowerSlipDraft('v2.3');
        $document = GeneratedDocument::query()->create([
            'template_id' => $draft->id, 'document_no' => 'TEST-DRAFT-'.str()->uuid(), 'document_type' => 'BORROWER_SLIP',
            'version_no' => 1, 'status' => 'GENERATED', 'generated_at' => now(),
        ]);
        $this->asHead()->delete(route('administration.document-templates.draft.destroy', ['type' => 'borrower-slip', 'template' => $draft]))->assertStatus(422);
        $this->assertDatabaseHas('generated_documents', ['id' => $document->id, 'template_id' => $draft->id]);
    }

    public function test_unauthorized_user_cannot_discard_a_draft(): void
    {
        $draft = $this->uploadBorrowerSlipDraft('v2.1');
        $borrower = User::factory()->create(['access_classification' => AccessClassification::BorrowerOnly]);
        $this->withSession(['active_workspace' => 'SPMU'])->actingAs($borrower)
            ->delete(route('administration.document-templates.draft.destroy', ['type' => 'borrower-slip', 'template' => $draft]))->assertForbidden();
        $this->assertDatabaseHas('document_templates', ['id' => $draft->id]);
    }

    private function asHead()
    {
        return $this->withSession(['active_workspace' => 'SPMU'])->actingAs($this->head);
    }

    private function uploadBorrowerSlipDraft(string $versionLabel): DocumentTemplate
    {
        $this->asHead()->post(route('administration.document-templates.draft.store', 'borrower-slip'), [
            'version_label' => $versionLabel,
            'reason' => 'Approved official source revision.',
            'template_file' => UploadedFile::fake()->createWithContent('borrower-slip.pdf', $this->systemReadyBorrowerSlipSource()),
        ])->assertRedirect();

        return DocumentTemplate::query()->where('document_type', 'BORROWER_SLIP')->where('version_label', $versionLabel)->firstOrFail();
    }

    private function systemReadyBorrowerSlipSource(): string
    {
        $fields = [
            'items.quantity_1' => [50, 650, 90, 665], 'items.quantity_2' => [50, 620, 90, 635],
            'items.unit_1' => [100, 650, 145, 665], 'items.unit_2' => [100, 620, 145, 635],
            'items.description_1' => [155, 650, 340, 665], 'items.description_2' => [155, 620, 340, 635],
            'purpose' => [50, 570, 340, 585], 'expected_return_date' => [50, 540, 190, 555],
            'date_released' => [50, 490, 145, 505], 'release_time' => [155, 490, 240, 505],
            'date_returned' => [250, 490, 345, 505], 'remarks' => [355, 490, 540, 505],
        ];
        $objects = [
            1 => '',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '',
            4 => '<< /Length 0 >>' . "\nstream\n\nendstream",
        ];
        $fieldReferences = [];
        $annotations = [];
        $number = 5;
        foreach ($fields as $name => $rect) {
            $fieldReferences[] = $number.' 0 R';
            $annotations[] = $number.' 0 R';
            $objects[$number] = '<< /Type /Annot /Subtype /Widget /FT /Tx /T ('.$name.') /Rect ['.implode(' ', $rect).'] /P 3 0 R >>';
            $number++;
        }
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R /AcroForm << /Fields ['.implode(' ', $fieldReferences).'] >> >>';
        $objects[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Annots ['.implode(' ', $annotations).'] /Contents 4 0 R >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $objectNumber => $object) {
            $offsets[$objectNumber] = strlen($pdf);
            $pdf .= "{$objectNumber} 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        for ($objectNumber = 1; $objectNumber <= count($objects); $objectNumber++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$objectNumber]);
        }

        return $pdf . 'trailer << /Size ' . (count($objects) + 1) . ' /Root 1 0 R >>' . "\nstartxref\n{$xref}\n%%EOF";
    }
}
