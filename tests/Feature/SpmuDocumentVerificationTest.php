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
                    false,
                actionOfficerVerified:
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
                'Inspect the signed Borrowing Request Letter'
            )
            ->assertSeeText(
                'Request details and required documents are complete'
            )
            ->assertSeeText(
                'Request is appropriate for approval'
            )
            ->assertSeeText(
                'Inventory availability is verified'
            )
            ->assertSeeText(
                'Review and decide'
            )
            ->assertSeeText(
                'E-sign & Approve'
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
                'Inspect the signed Borrowing Request Letter'
            )
            ->assertSeeText(
                'Verify request and documents'
            )
            ->assertSeeText(
                'E-sign & Mark VERIFIED'
            );

        /*
         * Verifying a request (sequence-1) is a baseline Action Officer
         * duty and needs no delegation; only the final Head-level decision
         * (sequence-2: Approve/Reject) is gated behind an active formal
         * delegation. The "E-sign & Mark VERIFIED" assertion above already
         * proves the verify button's trigger is "VERIFIED", not "APPROVED".
         *
         * The Reject button (data-decision-trigger="REJECTED") only renders
         * when $canDecide is true. spmu-review-styles.blade.php also
         * contains this exact substring unconditionally, as a CSS attribute
         * selector (".button[data-decision-trigger=\"REJECTED\"]:hover...")
         * used to style whichever button actually renders - so a plain
         * assertStringNotContainsString would false-positive-fail even when
         * the real button is correctly absent. Count only occurrences NOT
         * preceded by the CSS selector's "[".
         */
        $content = $response->getContent();
        $this->assertSame(
            substr_count($content, '[data-decision-trigger="REJECTED"'),
            substr_count($content, 'data-decision-trigger="REJECTED"'),
            'The Reject button must not render for a non-delegated Action Officer.'
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
                    true,
                actionOfficerVerified:
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
        bool $studentActivity,
        bool $actionOfficerVerified = false
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

        if ($actionOfficerVerified) {
            /*
             * RequestWorkflowService::verify() creates this sequence-2 step
             * only after the Action Officer verifies (see
             * RequestWorkflowService.php:575-581). Reproduce that state for
             * tests that view the request as the Head, since the Head's
             * decision UI is gated on this step already existing.
             */
            $version
                ->approvalSteps()
                ->create([
                    'stage_code' => 'SPMU',
                    'sequence_no' => 2,
                    'received_at' => now(),
                    'decision' => 'RECEIVED',
                ]);
        }

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
