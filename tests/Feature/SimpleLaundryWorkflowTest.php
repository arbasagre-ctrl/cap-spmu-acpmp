<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\GeneratedDocument;
use App\Models\Incident;
use App\Models\InventoryItem;
use App\Models\LaundryJob;
use App\Models\LaundryJobLine;
use App\Models\OverdueCase;
use App\Models\RequestItem;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers the current linen workflow:
 *
 *   Borrower returns linen to the offline Laundry Worker
 *     -> Laundry Worker checks it, wet-signs Received by + Date, and later
 *        delivers the accomplished physical Laundry Form directly to SPMU
 *     -> Action Officer uploads/verifies the accomplished Laundry Form
 *     -> Action Officer encodes the form in SPMU Return
 *          -> serviceable linen becomes Available automatically
 *          -> there is NO second Laundry action or quantity/condition classification
 */
class SimpleLaundryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');

        /*
         * Pin the clock to a Tuesday.
         *
         * Pickup and return are only permitted on an operationally open day,
         * and Monday-Friday is the configured default. Left on the real clock
         * these tests pass or fail depending on which day the suite happens to
         * run - a weekend run is rejected by the operational calendar. Tuesday
         * keeps "today" and the next three days inside the open week.
         */
        Carbon::setTestNow(Carbon::create(2026, 9, 1, 9));
    }
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }


    public function test_completed_form_return_encoding_automatically_restores_serviceable_linen_to_available(): void
    {
        [$job, $jobLine, $borrower, $custody, $item] = $this->outstandingLaundryCase(quantity: 2);
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);
        $inventory = app(InventoryService::class);
        $availableBeforeReturnEncoding = $this->currentAvailable($inventory, $item);

        $this->uploadAccomplishedForm($job, $officer)->assertSessionHasNoErrors();

        $this->recordReturn($custody, $officer, [
            $jobLine->custody_line_id => ['FINE' => 2],
        ])->assertSessionHasNoErrors();

        $job->refresh();
        $jobLine->refresh();
        $custody->refresh();

        $this->assertSame('LAUNDRY_COMPLETED', $job->status);
        $this->assertSame(2, $jobLine->received_quantity);
        $this->assertSame(2, $jobLine->completed_quantity);
        // No Laundry Worker portal account is created or mapped. The legacy
        // worker_name field stays empty; only the actual physical receipt date
        // from the signed form is persisted.
        $this->assertNull($job->worker_name);
        $this->assertNotNull($job->worker_received_at);
        $this->assertSame('CLOSED', $custody->status);
        $this->assertFalse(Route::has('laundry.receive'));
        $this->assertFalse(Route::has('laundry.verify'));
        $this->assertSame(
            $availableBeforeReturnEncoding + 2.0,
            $this->currentAvailable($inventory, $item)
        );

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('laundry.show', $job))
            ->assertOk()
            ->assertSeeText('Completion Summary')
            ->assertSeeText('returned to Available inventory')
            ->assertDontSeeText('Confirm Laundry Turnover')
            ->assertDontSeeText('Archive accomplished Laundry Form')
            ->assertDontSeeText('Clean / Available')
            ->assertDontSeeText('Maintenance')
            ->assertDontSeeText('Issued by:')
            ->assertDontSeeText('Received by:');
    }

    public function test_adverse_linen_findings_keep_accountability_while_serviceable_linen_becomes_available(): void
    {
        [$job, $jobLine, $borrower, $custody] = $this->outstandingLaundryCase(quantity: 2);
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);

        $this->uploadAccomplishedForm($job, $officer)->assertSessionHasNoErrors();
        $this->recordReturn(
            $custody,
            $officer,
            [$jobLine->custody_line_id => ['FINE' => 1, 'DAMAGED' => 1]],
            [
                'remarks' => 'One linen item marked damaged by Laundry Personnel.',
                'evidence_files' => [
                    $jobLine->custody_line_id => UploadedFile::fake()->image('linen-damage.jpg'),
                ],
            ]
        )
            ->assertSessionHasNoErrors();

        $job->refresh();
        $jobLine->refresh();

        $this->assertSame('LAUNDRY_COMPLETED', $job->status);
        $this->assertSame(1, $jobLine->completed_quantity);
        $this->assertSame(1, Incident::query()
            ->where('custody_transaction_id', $custody->id)
            ->where('incident_type', 'DAMAGED')
            ->count()
        );
    }

    public function test_received_by_date_prevents_late_accountability_when_form_is_uploaded_later(): void
    {
        [$job, $jobLine, $borrower, $custody] = $this->outstandingLaundryCase(quantity: 2);
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);

        $physicalReceiptDate = now()->toDateString();
        $this->travelTo(now()->addDays(2)->setTime(10, 0));

        $this->uploadAccomplishedForm($job, $officer, $physicalReceiptDate)
            ->assertSessionHasNoErrors();
        $this->recordReturn($custody->fresh(), $officer, [
            $jobLine->custody_line_id => ['FINE' => 2],
        ])->assertSessionHasNoErrors();

        $this->assertSame($physicalReceiptDate, $job->fresh()->worker_received_at?->toDateString());
        $this->assertNull(OverdueCase::query()
            ->where('custody_transaction_id', $custody->id)
            ->first());
    }

    public function test_late_received_by_date_continues_to_accountability(): void
    {
        [$job, $jobLine, $borrower, $custody] = $this->outstandingLaundryCase(quantity: 2);
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);
        $custody->update(['due_at' => now()->subDay()->endOfDay()]);

        $this->uploadAccomplishedForm($job, $officer)->assertSessionHasNoErrors();
        $this->recordReturn($custody->fresh(), $officer, [
            $jobLine->custody_line_id => ['FINE' => 2],
        ])->assertSessionHasNoErrors();

        $lateReturn = OverdueCase::query()
            ->where('custody_transaction_id', $custody->id)
            ->firstOrFail();

        $this->assertSame('RETURNED_PENDING_SETTLEMENT', $lateReturn->status);
        $this->assertSame(now()->toDateString(), $lateReturn->actual_return_date?->toDateString());
        $this->assertSame('LAUNDRY_RECEIPT', $lateReturn->return_date_source);
    }

    public function test_accomplished_form_upload_stays_in_the_spmu_return_workspace(): void
    {
        [$job, $jobLine, $borrower, $custody] = $this->outstandingLaundryCase(quantity: 2);
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('laundry.show', $job))
            ->assertOk()
            ->assertSeeText('Laundry processing / completed form pending')
            ->assertSeeText('complete the offline laundry process')
            ->assertSeeText('Open SPMU Return')
            ->assertDontSeeText('Archive accomplished Laundry Form')
            ->assertDontSeeText('Finalize Linen Availability');

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('custody.return.show', $custody))
            ->assertOk()
            ->assertSeeText('Upload Completed Form')
            ->assertDontSeeText('I confirm the signed form is complete.')
            ->assertSeeText('Use the RECEIVED BY date on the signed form.');
    }

    private function recordReturn(
        CustodyTransaction $custody,
        User $officer,
        array $accounting,
        array $extra = []
    ) {
        /*
         * Linen carries the Laundry RECEIVED BY date from the accomplished
         * form. It is no longer defaulted from the SPMU encoding time, so the
         * Action Officer supplies it here as they would in the UI.
         */
        return $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(
                route('custody.return', $custody),
                array_merge(
                    [
                        'accounting' => $accounting,
                        'laundry_received_date' => now()->toDateString(),
                    ],
                    $extra
                )
            );
    }

    private function uploadAccomplishedForm(
        LaundryJob $job,
        User $officer,
        ?string $receivedOn = null
    )
    {
        return $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(route('laundry.spmu.upload-form', $job), [
                'evidence' => UploadedFile::fake()->create(
                    'accomplished-laundry-form.pdf',
                    20,
                    'application/pdf'
                ),
                'laundry_received_on' => $receivedOn ?: now()->toDateString(),
            ]);
    }

    private function currentAvailable(InventoryService $inventory, InventoryItem $item): float
    {
        return (float) $inventory->availability(
            $item->fresh(),
            now()->subMonth(),
            now()->addMonth()
        )['current_available'];
    }

    /**
     * @return array{0: LaundryJob, 1: LaundryJobLine, 2: User, 3: CustodyTransaction, 4: InventoryItem}
     */
    private function outstandingLaundryCase(int $quantity): array
    {
        $borrower = $this->classificationUser(AccessClassification::BorrowerOnly);
        $item = InventoryItem::query()
            ->where('active', true)
            ->where('borrowable', true)
            ->where('laundry_required', true)
            ->firstOrFail();

        $request = BorrowingRequest::create([
            'request_no' => 'BR-LAUNDRY-'.uniqid(),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
        ]);

        $version = $request->versions()->create([
            'version_no' => 1,
            'purpose_event' => 'Simple linen workflow test',
            'location' => 'CSPC Campus',
            'needed_from' => now()->subDay(),
            'return_due_at' => now()->endOfDay(),
            'event_details' => 'Borrower returns linen through the Laundry Area first.',
            'off_campus' => false,
            'created_by_user_id' => $borrower->id,
        ]);

        $requestItem = RequestItem::create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => $item->unit->unit_name,
            'requested_quantity' => $quantity,
            'approved_quantity' => $quantity,
            'use_location' => 'ON_CAMPUS',
        ]);

        $allocation = Allocation::create([
            'request_item_id' => $requestItem->id,
            'period_start' => $version->needed_from,
            'period_end' => $version->return_due_at,
            'allocated_quantity' => $quantity,
            'released_quantity' => $quantity,
            'restored_quantity' => 0,
            'status' => 'RELEASED',
            'allocated_at' => now()->subDay(),
        ]);

        $custody = CustodyTransaction::create([
            'custody_no' => 'CUS-LAUNDRY-'.uniqid(),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => 'ACTIVE',
            'released_at' => now()->subHours(6),
            'due_at' => now()->endOfDay(),
        ]);

        $custodyLine = CustodyLine::create([
            'custody_transaction_id' => $custody->id,
            'request_item_id' => $requestItem->id,
            'allocation_id' => $allocation->id,
            'approved_quantity' => $quantity,
            'quantity_to_receive' => $quantity,
            'actual_released_quantity' => $quantity,
            'returned_quantity' => 0,
            'release_condition' => 'SERVICEABLE',
            'item_status' => 'RELEASED_PENDING_RETURN',
            'compliance_status' => 'FOR_LAUNDRY',
        ]);

        $formBytes = '%PDF-1.4 simple laundry form';
        $formPath = 'tests/laundry/'.uniqid().'.pdf';
        Storage::disk('local')->put($formPath, $formBytes);

        $storedFile = StoredFile::create([
            'uploaded_by_user_id' => null,
            'disk' => 'local',
            'storage_path' => $formPath,
            'original_name' => 'laundry-form.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($formBytes),
            'sha256' => hash('sha256', $formBytes),
            'classification' => 'CONTROLLED_DOCUMENT',
        ]);

        $document = GeneratedDocument::create([
            'stored_file_id' => $storedFile->id,
            'request_version_id' => $version->id,
            'subject_type' => CustodyTransaction::class,
            'subject_id' => $custody->id,
            'document_no' => 'DOC-LAUNDRY-'.uniqid(),
            'document_type' => 'LAUNDRY_FORM',
            'version_no' => 1,
            'sha256' => $storedFile->sha256,
            'status' => 'FINAL',
            'generated_at' => now(),
        ]);

        $job = LaundryJob::create([
            'custody_transaction_id' => $custody->id,
            'generated_document_id' => $document->id,
            'status' => 'FOR_LAUNDRY',
        ]);

        $jobLine = LaundryJobLine::create([
            'laundry_job_id' => $job->id,
            'custody_line_id' => $custodyLine->id,
            'issued_quantity' => $quantity,
            'affected_quantity' => 0,
        ]);

        return [
            $job->fresh(['custody.borrower', 'custody.request']),
            $jobLine->fresh(['custodyLine.requestItem']),
            $borrower,
            $custody->fresh(['lines']),
            $item,
        ];
    }

    private function classificationUser(AccessClassification $classification): User
    {
        return User::query()
            ->where('access_classification', $classification->value)
            ->firstOrFail();
    }
}
