<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use App\Models\RequestSupportingDocument;
use App\Models\StoredFile;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProtectedFilePreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
    }

    public function test_protected_file_allows_same_origin_inline_preview_for_authorized_spmu(): void
    {
        $spmu = User::query()
            ->where(
                'access_classification',
                AccessClassification::SpmuHead->value
            )
            ->firstOrFail();

        $bytes = '%PDF-1.4 preview-test';
        $path = 'tests/preview/approved-request-letter.pdf';

        Storage::disk('local')->put($path, $bytes);

        $file = StoredFile::query()->create([
            'uploaded_by_user_id' => $spmu->id,
            'disk' => 'local',
            'storage_path' => $path,
            'original_name' => 'approved-request-letter.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'classification' => 'REQUEST_SUPPORTING_DOCUMENT',
        ]);

        $response = $this
            ->withSession([
                'active_workspace' => 'SPMU',
            ])
            ->actingAs($spmu)
            ->get(route('files.show', $file));

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');

        $this->assertStringContainsString(
            "frame-ancestors 'self'",
            (string) $response->headers->get('Content-Security-Policy')
        );

        $this->assertStringContainsString(
            'inline;',
            (string) $response->headers->get('Content-Disposition')
        );
    }

    public function test_protected_file_preview_wrapper_is_available_and_embeds_the_authorized_stream(): void
    {
        $spmu = User::query()
            ->where(
                'access_classification',
                AccessClassification::SpmuHead->value
            )
            ->firstOrFail();

        $bytes = '%PDF-1.4 preview-wrapper-test';
        $path = 'tests/preview/wrapper-test.pdf';

        Storage::disk('local')->put($path, $bytes);

        $file = StoredFile::query()->create([
            'uploaded_by_user_id' => $spmu->id,
            'disk' => 'local',
            'storage_path' => $path,
            'original_name' => 'wrapper-test.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'classification' => 'REQUEST_SUPPORTING_DOCUMENT',
        ]);

        $response = $this
            ->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmu)
            ->get(route('files.preview', $file));

        $response
            ->assertOk()
            ->assertViewIs('documents.file-preview')
            ->assertViewHas('file', fn (StoredFile $viewFile) => $viewFile->is($file));

        $response->assertSee(route('files.show', $file, false), false);
    }

    public function test_spmu_can_preview_current_borrower_uploaded_request_supporting_document(): void
    {
        $borrower = User::query()
            ->where(
                'access_classification',
                AccessClassification::BorrowerOnly->value
            )
            ->firstOrFail();

        $spmu = User::query()
            ->where(
                'access_classification',
                AccessClassification::SpmuHead->value
            )
            ->firstOrFail();

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-PREVIEW-001',
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::UnderSpmu,
        ]);

        $version = $request->versions()->create([
            'version_no' => 1,
            'purpose_event' => 'Protected preview test',
            'location' => 'CSPC Campus',
            'needed_from' => now()->addDay(),
            'return_due_at' => now()->addDays(2),
            'created_by_user_id' => $borrower->id,
        ]);

        $bytes = '%PDF-1.4 borrower-supporting-document';
        $path = 'tests/preview/borrower-approved-letter.pdf';

        Storage::disk('local')->put($path, $bytes);

        $file = StoredFile::query()->create([
            'uploaded_by_user_id' => $borrower->id,
            'disk' => 'local',
            'storage_path' => $path,
            'original_name' => 'borrower-approved-letter.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'classification' => 'REQUEST_SUPPORTING_DOCUMENT',
        ]);

        RequestSupportingDocument::query()->create([
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'document_type' => RequestSupportingDocument::TYPE_REQUEST_LETTER,
            'version_no' => 1,
            'stored_file_id' => $file->id,
            'uploaded_by_user_id' => $borrower->id,
            'uploaded_at' => now(),
            'verification_status' => RequestSupportingDocument::STATUS_PENDING,
            'is_current' => true,
        ]);

        $this
            ->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmu)
            ->get(route('files.show', $file))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_normal_application_pages_keep_strict_frame_protection(): void
    {
        $spmu = User::query()
            ->where(
                'access_classification',
                AccessClassification::SpmuHead->value
            )
            ->firstOrFail();

        $response = $this
            ->withSession([
                'active_workspace' => 'SPMU',
            ])
            ->actingAs($spmu)
            ->get(route('dashboard'));

        $response
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY');

        $this->assertStringContainsString(
            "frame-ancestors 'none'",
            (string) $response->headers->get('Content-Security-Policy')
        );
    }
}
