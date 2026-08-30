<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use App\Models\InventoryItem;
use App\Models\RequestItem;
use App\Models\RequestSupportingDocument;
use App\Models\StoredFile;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpmuDocumentVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            DatabaseSeeder::class
        );
    }

    public function test_spmu_head_sees_split_preview_checklist_and_confirmation_workspace(): void
    {
        $request =
            $this->underSpmuRequest(
                studentActivity:
                    false
            );

        $head =
            $this->classificationUser(
                AccessClassification::SpmuHead
            );

        $this
            ->withSession([
                'active_workspace' =>
                    'SPMU',
            ])
            ->actingAs(
                $head
            )
            ->get(
                route(
                    'requests.show',
                    $request
                )
            )
            ->assertOk()
            ->assertSeeText(
                'Inspect the approved document'
            )
            ->assertSeeText(
                'Request details match the signed letter'
            )
            ->assertSeeText(
                'Required signatures and documents are complete'
            )
            ->assertSeeText(
                'Inventory availability is verified'
            )
            ->assertSeeText(
                'Document Status'
            )
            ->assertSeeText(
                'Verify & Approve'
            )
            ->assertSeeText(
                'Return for Revision'
            )
            ->assertSeeText(
                'Reject'
            )
            ->assertSee(
                'data-verification-confirm-dialog',
                false
            );
    }

    public function test_regular_action_officer_can_review_but_cannot_decide_without_delegation(): void
    {
        $request =
            $this->underSpmuRequest(
                studentActivity:
                    false
            );

        $officer =
            $this->classificationUser(
                AccessClassification::SpmuOfficer
            );

        $response =
            $this
                ->withSession([
                    'active_workspace' =>
                        'SPMU',
                ])
                ->actingAs(
                    $officer
                )
                ->get(
                    route(
                        'requests.show',
                        $request
                    )
                );

        $response
            ->assertOk()
            ->assertSeeText(
                'Inspect the approved document'
            )
            ->assertSeeText(
                'Review only.'
            );

        /*
         * The page-level JavaScript contains the selector text
         * "[data-verification-form]" even when the actual decision form
         * is intentionally not rendered. Therefore checking for the bare
         * selector name is not a valid authorization assertion.
         *
         * These exact HTML attributes exist only on the real decision
         * controls, so their absence proves that a regular Action Officer
         * has review-only access without an active formal delegation.
         */
        $this->assertStringNotContainsString(
            'data-required-supporting-present=',
            $response->getContent()
        );

        $this->assertStringNotContainsString(
            'data-decision-trigger="APPROVED"',
            $response->getContent()
        );

        $this->assertStringNotContainsString(
            'name="decision"',
            $response->getContent()
        );
    }

    public function test_approval_requires_all_three_document_checklist_confirmations(): void
    {
        $request =
            $this->underSpmuRequest(
                studentActivity:
                    false
            );

        $head =
            $this->classificationUser(
                AccessClassification::SpmuHead
            );

        $this
            ->withSession([
                'active_workspace' =>
                    'SPMU',
            ])
            ->actingAs(
                $head
            )
            ->post(
                route(
                    'approvals.decide',
                    $request
                ),
                [
                    'decision' =>
                        'APPROVED',
                ]
            )
            ->assertSessionHasErrors([
                'details_complete',
                'documents_complete',
                'availability_verified',
                'confirm_e_signature',
            ]);

        $this->assertSame(
            RequestStatus::UnderSpmu,
            $request
                ->fresh()
                ->status
        );
    }

    public function test_return_for_revision_requires_remarks(): void
    {
        $request =
            $this->underSpmuRequest(
                studentActivity:
                    false
            );

        $head =
            $this->classificationUser(
                AccessClassification::SpmuHead
            );

        $this
            ->withSession([
                'active_workspace' =>
                    'SPMU',
            ])
            ->actingAs(
                $head
            )
            ->post(
                route(
                    'approvals.decide',
                    $request
                ),
                [
                    'decision' =>
                        'RETURNED_FOR_REVISION',
                ]
            )
            ->assertSessionHasErrors(
                'remarks'
            );

        $this->assertSame(
            RequestStatus::UnderSpmu,
            $request
                ->fresh()
                ->status
        );
    }

    public function test_student_activity_shows_permission_to_conduct_attachment(): void
    {
        $request =
            $this->underSpmuRequest(
                studentActivity:
                    true
            );

        $head =
            $this->classificationUser(
                AccessClassification::SpmuHead
            );

        $this
            ->withSession([
                'active_workspace' =>
                    'SPMU',
            ])
            ->actingAs(
                $head
            )
            ->get(
                route(
                    'requests.show',
                    $request
                )
            )
            ->assertOk()
            ->assertSeeText(
                'Permission to Conduct Letter'
            )
            ->assertSeeText(
                'View Attachment'
            );
    }

    private function underSpmuRequest(
        bool $studentActivity
    ): BorrowingRequest {
        $borrower =
            $this->classificationUser(
                AccessClassification::BorrowerOnly
            );

        $item =
            InventoryItem::query()
                ->with(
                    'unit'
                )
                ->where(
                    'active',
                    true
                )
                ->where(
                    'borrowable',
                    true
                )
                ->firstOrFail();

        $scheduleDate =
            now()
                ->addDays(
                    2
                )
                ->startOfDay();

        $returnDate =
            now()
                ->addDays(
                    3
                )
                ->startOfDay();

        $request =
            BorrowingRequest::query()
                ->create([
                    'request_no' =>
                        'BR-SPMU-VERIFY-'
                        .uniqid(),

                    'borrower_user_id' =>
                        $borrower->id,

                    'accountable_unit_id' =>
                        $borrower
                            ->organizational_unit_id,

                    'current_version_no' =>
                        1,

                    'status' =>
                        RequestStatus::UnderSpmu,
                ]);

        $version =
            $request
                ->versions()
                ->create([
                    'version_no' =>
                        1,

                    'purpose_event' =>
                        'SPMU document verification test',

                    'event_details' =>
                        'Current scanned-document verification workspace.',

                    'location' =>
                        'CSPC Campus',

                    'schedule_date' =>
                        $scheduleDate
                            ->toDateString(),

                    'return_date' =>
                        $returnDate
                            ->toDateString(),

                    'needed_from' =>
                        $scheduleDate,

                    'return_due_at' =>
                        $returnDate
                            ->copy()
                            ->endOfDay(),

                    'represents_student_activity' =>
                        $studentActivity,

                    'off_campus' =>
                        false,

                    'created_by_user_id' =>
                        $borrower->id,
                ]);

        RequestItem::query()
            ->create([
                'request_version_id' =>
                    $version->id,

                'inventory_item_id' =>
                    $item->id,

                'description_snapshot' =>
                    $item
                        ->unique_description,

                'unit_snapshot' =>
                    $item
                        ->unit
                        ->unit_name,

                'requested_quantity' =>
                    1,

                'use_location' =>
                    'ON_CAMPUS',
            ]);

        $version
            ->approvalSteps()
            ->create([
                'stage_code' =>
                    'SPMU',

                'sequence_no' =>
                    1,

                'received_at' =>
                    now(),

                'decision' =>
                    'RECEIVED',
            ]);

        $this->attach(
            $request,
            $version->id,
            $borrower,
            RequestSupportingDocument::TYPE_REQUEST_LETTER,
            'approved-request-letter.pdf',
            'application/pdf'
        );

        if ($studentActivity) {
            $this->attach(
                $request,
                $version->id,
                $borrower,
                RequestSupportingDocument::TYPE_PERMISSION_TO_CONDUCT,
                'permission-to-conduct.pdf',
                'application/pdf'
            );
        }

        return $request
            ->fresh();
    }

    private function attach(
        BorrowingRequest $request,
        int $versionId,
        User $borrower,
        string $type,
        string $filename,
        string $mime
    ): void {
        $bytes =
            '%PDF-1.4 test';

        $file =
            StoredFile::query()
                ->create([
                    'uploaded_by_user_id' =>
                        $borrower->id,

                    'disk' =>
                        'local',

                    'storage_path' =>
                        'tests/spmu-verification/'
                        .$request->id
                        .'/'
                        .$filename,

                    'original_name' =>
                        $filename,

                    'mime_type' =>
                        $mime,

                    'byte_size' =>
                        strlen(
                            $bytes
                        ),

                    'sha256' =>
                        hash(
                            'sha256',
                            $bytes
                        ),

                    'classification' =>
                        'REQUEST_SUPPORTING_DOCUMENT',
                ]);

        RequestSupportingDocument::query()
            ->create([
                'request_id' =>
                    $request->id,

                'request_version_id' =>
                    $versionId,

                'document_type' =>
                    $type,

                'version_no' =>
                    1,

                'stored_file_id' =>
                    $file->id,

                'uploaded_by_user_id' =>
                    $borrower->id,

                'uploaded_at' =>
                    now(),

                'verification_status' =>
                    RequestSupportingDocument::STATUS_PENDING,

                'is_current' =>
                    true,
            ]);
    }

    private function classificationUser(
        AccessClassification $classification
    ): User {
        return User::query()
            ->where(
                'access_classification',
                $classification->value
            )
            ->firstOrFail();
    }
}
