<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\InventoryItem;
use App\Models\RequestItem;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\ProtectedFileService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BorrowingRequestLetterPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            DatabaseSeeder::class
        );
    }

    public function test_draft_recovery_directs_the_borrower_to_upload_the_accomplished_scanned_request_letter_without_generating_a_new_document(): void
    {
        $this->assertTrue(
            app(Router::class)->has(
                'requests.recover-draft-document'
            )
        );

        $borrower = User::query()
            ->where(
                'access_classification',
                AccessClassification::BorrowerOnly->value
            )
            ->firstOrFail();

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-DRAFT-'.uniqid(),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::Draft,
        ]);

        $scheduleDate = now()->addDay()->startOfDay();
        $returnDate = now()->addDays(2)->startOfDay();

        $version = $request->versions()->create([
            'version_no' => 1,
            'purpose_event' => 'Printable request letter test',
            'event_details' => 'Physical GSU/VPAF signatory workflow.',
            'location' => 'CSPC Campus',
            'schedule_date' => $scheduleDate->toDateString(),
            'return_date' => $returnDate->toDateString(),
            'needed_from' => $scheduleDate,
            'return_due_at' => $returnDate,
        ]);

        $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->post(
                route('requests.recover-draft-document', $request)
            )
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'status',
                'The current workflow does not generate a Borrowing Request Letter. Upload the already approved and fully signed scanned Borrowing Request Letter instead.'
            );

        $this->assertDatabaseMissing('generated_documents', [
            'request_version_id' => $version->id,
            'document_type' => 'REQUEST_LETTER',
        ]);
    }

    public function test_current_workflow_generates_a_physical_borrower_slip_after_spmu_preparation(): void
    {
        $custody =
            $this->preparedCustody(
                offCampus:
                    false,
                laundry:
                    false
            );

        $document =
            app(
                DocumentService::class
            )->borrowerSlip(
                $custody
            );

        $this->assertSame(
            'BORROWER_SLIP',
            $document
                ->document_type
        );

        $this->assertSame(
            'FINAL',
            $document
                ->status
        );

        $bytes =
            app(
                ProtectedFileService::class
            )->bytes(
                $document
                    ->file
            );

        $this->assertStringStartsWith(
            '%PDF-',
            $bytes
        );
    }

    public function test_current_gate_pass_is_generated_only_for_off_campus_property(): void
    {
        $offCampus =
            $this->preparedCustody(
                offCampus:
                    true,
                laundry:
                    false
            );

        $document =
            app(
                DocumentService::class
            )->conditionalForm(
                $offCampus,
                'GATE_PASS'
            );

        $this->assertSame(
            'GATE_PASS',
            $document
                ->document_type
        );

        $this->assertSame(
            'FINAL',
            $document
                ->status
        );

        $bytes =
            app(
                ProtectedFileService::class
            )->bytes(
                $document
                    ->file
            );

        $this->assertStringStartsWith(
            '%PDF-',
            $bytes
        );

        $onCampus =
            $this->preparedCustody(
                offCampus:
                    false,
                laundry:
                    false
            );

        try {
            app(
                DocumentService::class
            )->conditionalForm(
                $onCampus,
                'GATE_PASS'
            );

            $this->fail(
                'An on-campus-only custody must not generate a Gate Pass.'
            );
        } catch (
            ValidationException $exception
        ) {
            $this->assertArrayHasKey(
                'document',
                $exception
                    ->errors()
            );
        }
    }

    public function test_current_laundry_form_is_generated_only_for_laundry_required_property(): void
    {
        $laundryCustody =
            $this->preparedCustody(
                offCampus:
                    false,
                laundry:
                    true
            );

        $document =
            app(
                DocumentService::class
            )->conditionalForm(
                $laundryCustody,
                'LAUNDRY_FORM'
            );

        $this->assertSame(
            'LAUNDRY_FORM',
            $document
                ->document_type
        );

        $this->assertSame(
            'FINAL',
            $document
                ->status
        );

        $bytes =
            app(
                ProtectedFileService::class
            )->bytes(
                $document
                    ->file
            );

        $this->assertStringStartsWith(
            '%PDF-',
            $bytes
        );

        $regularCustody =
            $this->preparedCustody(
                offCampus:
                    false,
                laundry:
                    false
            );

        try {
            app(
                DocumentService::class
            )->conditionalForm(
                $regularCustody,
                'LAUNDRY_FORM'
            );

            $this->fail(
                'A non-laundry custody must not generate a Laundry Form.'
            );
        } catch (
            ValidationException $exception
        ) {
            $this->assertArrayHasKey(
                'document',
                $exception
                    ->errors()
            );
        }
    }

    public function test_regenerating_current_physical_form_supersedes_the_previous_generated_copy(): void
    {
        $custody =
            $this->preparedCustody(
                offCampus:
                    false,
                laundry:
                    false
            );

        $service =
            app(
                DocumentService::class
            );

        $first =
            $service->borrowerSlip(
                $custody
            );

        $second =
            $service->borrowerSlip(
                $custody->fresh()
            );

        $this->assertSame(
            'SUPERSEDED',
            $first
                ->fresh()
                ->status
        );

        $this->assertNotNull(
            $first
                ->fresh()
                ->invalidated_at
        );

        $this->assertSame(
            'FINAL',
            $second
                ->fresh()
                ->status
        );

        $this->assertSame(
            1,
            $custody
                ->request
                ->currentVersion
                ->documents()
                ->where(
                    'subject_type',
                    CustodyTransaction::class
                )
                ->where(
                    'subject_id',
                    $custody->id
                )
                ->where(
                    'document_type',
                    'BORROWER_SLIP'
                )
                ->where(
                    'status',
                    'FINAL'
                )
                ->count()
        );
    }

    private function preparedCustody(
        bool $offCampus,
        bool $laundry
    ): CustodyTransaction {
        $borrower =
            User::query()
                ->where(
                    'access_classification',
                    AccessClassification::BorrowerOnly->value
                )
                ->firstOrFail();

        $itemQuery =
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
                );

        if ($laundry) {
            $itemQuery
                ->where(
                    'laundry_required',
                    true
                );
        } elseif ($offCampus) {
            $itemQuery
                ->where(
                    'off_campus_allowed',
                    true
                );
        } else {
            $itemQuery
                ->where(
                    'laundry_required',
                    false
                );
        }

        $item =
            $itemQuery
                ->firstOrFail();

        $scheduleDate =
            now()
                ->addDay()
                ->startOfDay();

        $returnDate =
            now()
                ->addDays(
                    2
                )
                ->startOfDay();

        $request =
            BorrowingRequest::query()
                ->create([
                    'request_no' =>
                        'BR-DOC-'
                        .uniqid(),

                    'borrower_user_id' =>
                        $borrower->id,

                    'accountable_unit_id' =>
                        $borrower
                            ->organizational_unit_id,

                    'current_version_no' =>
                        1,

                    'status' =>
                        RequestStatus::ApprovedReadyForRelease,
                ]);

        $version =
            $request
                ->versions()
                ->create([
                    'version_no' =>
                        1,

                    'purpose_event' =>
                        'Current operational document test',

                    'event_details' =>
                        'Physical pickup and conditional-form workflow.',

                    'location' =>
                        $offCampus
                            ? 'Off-campus venue'
                            : 'CSPC Campus',

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

                    'off_campus' =>
                        $offCampus,

                    'created_by_user_id' =>
                        $borrower->id,
                ]);

        $requestItem =
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

                    'approved_quantity' =>
                        1,

                    'use_location' =>
                        $offCampus
                            ? 'OFF_CAMPUS'
                            : 'ON_CAMPUS',
                ]);

        $allocation =
            Allocation::query()
                ->create([
                    'request_item_id' =>
                        $requestItem->id,

                    'period_start' =>
                        $version
                            ->needed_from,

                    'period_end' =>
                        $version
                            ->return_due_at,

                    'allocated_quantity' =>
                        1,

                    'released_quantity' =>
                        0,

                    'restored_quantity' =>
                        0,

                    'status' =>
                        'ACTIVE',

                    'allocated_at' =>
                        now(),
                ]);

        $custody =
            CustodyTransaction::query()
                ->create([
                    'custody_no' =>
                        'CUS-DOC-'
                        .uniqid(),

                    'request_id' =>
                        $request->id,

                    'request_version_id' =>
                        $version->id,

                    'borrower_user_id' =>
                        $borrower->id,

                    'status' =>
                        'PREPARING_RELEASE',

                    'scheduled_release_at' =>
                        $scheduleDate
                            ->copy()
                            ->setTime(
                                9,
                                0
                            ),

                    'pickup_expires_at' =>
                        $scheduleDate
                            ->copy()
                            ->setTime(
                                12,
                                0
                            ),

                    'prepared_at' =>
                        now(),

                    'due_at' =>
                        $returnDate
                            ->copy()
                            ->endOfDay(),
                ]);

        CustodyLine::query()
            ->create([
                'custody_transaction_id' =>
                    $custody->id,

                'request_item_id' =>
                    $requestItem->id,

                'allocation_id' =>
                    $allocation->id,

                'approved_quantity' =>
                    1,

                'quantity_to_receive' =>
                    1,

                'actual_released_quantity' =>
                    0,

                'returned_quantity' =>
                    0,

                'item_status' =>
                    'CONFIRMED',
            ]);

        return $custody
            ->fresh([
                'borrower',
                'request.currentVersion',
                'lines.requestItem.inventoryItem.unit',
            ]);
    }
}
