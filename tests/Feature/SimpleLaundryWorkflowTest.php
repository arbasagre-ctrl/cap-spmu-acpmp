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
use App\Models\RequestItem;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers the current linen workflow:
 *
 *   Laundry Personnel receive returned linen first and wet-sign Received by
 *     -> Action Officer uploads/verifies the accomplished Laundry Form
 *     -> Action Officer encodes the form in SPMU Return
 *          -> serviceable linen automatically enters the internal Laundry queue
 *          -> there is NO second Laundry turnover confirmation
 *     -> internal Laundry completion marks that known serviceable quantity Available
 *          -> there is NO second quantity/condition classification in Laundry Operations
 */
class SimpleLaundryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
    }

    public function test_spmu_return_encoding_automatically_creates_the_internal_laundry_queue(): void
    {
        [$job, $jobLine, $borrower, $custody] = $this->outstandingLaundryCase(quantity: 2);
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);

        $this->uploadAccomplishedForm($job, $officer)->assertSessionHasNoErrors();

        $this->recordReturn($custody, $officer, [
            $jobLine->custody_line_id => ['FINE' => 2],
        ])->assertSessionHasNoErrors();

        $job->refresh();
        $jobLine->refresh();
        $custody->refresh();

        $this->assertSame('TURNED_OVER_TO_LAUNDRY', $job->status);
        $this->assertSame(2, $jobLine->received_quantity);
        $this->assertSame($officer->full_name, $job->worker_name);
        $this->assertNotNull($job->worker_received_at);
        $this->assertSame('CLOSED', $custody->status);
        $this->assertFalse(Route::has('laundry.receive'));
        $this->assertFalse(Route::has('laundry.verify'));

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('laundry.show', $job))
            ->assertOk()
            ->assertSeeText('Mark Laundry Complete')
            ->assertSeeText('No reclassification is needed here.')
            ->assertDontSeeText('Confirm Laundry Turnover')
            ->assertDontSeeText('Archive accomplished Laundry Form')
            ->assertDontSeeText('Clean / Available')
            ->assertDontSeeText('Maintenance')
            ->assertDontSeeText('Issued by:')
            ->assertDontSeeText('Received by:');
    }

    public function test_internal_laundry_completion_needs_no_second_quantity_input(): void
    {
        [$job, $jobLine, $borrower, $custody, $item] = $this->outstandingLaundryCase(quantity: 2);
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);
        $inventory = app(InventoryService::class);

        $this->uploadAccomplishedForm($job, $officer)->assertSessionHasNoErrors();
        $this->recordReturn($custody, $officer, [
            $jobLine->custody_line_id => ['FINE' => 2],
        ])->assertSessionHasNoErrors();

        $afterReturnEncoding = $this->currentAvailable($inventory, $item);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(route('laundry.complete-processing', $job->fresh()), [
                'worker_remarks' => 'Washing completed.',
            ])
            ->assertSessionHasNoErrors();

        $job->refresh();
        $jobLine->refresh();

        $this->assertSame('LAUNDRY_COMPLETED', $job->status);
        $this->assertSame(2, $jobLine->completed_quantity);
        $this->assertSame('CLOSED', $custody->fresh()->status);
        $this->assertSame(
            $afterReturnEncoding + 2.0,
            $this->currentAvailable($inventory, $item)
        );
    }

    public function test_adverse_linen_findings_are_not_reclassified_inside_laundry_operations(): void
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
        )->assertSessionHasNoErrors();

        $job->refresh();
        $jobLine->refresh();

        $this->assertSame('TURNED_OVER_TO_LAUNDRY', $job->status);
        $this->assertSame(1, $jobLine->received_quantity);
        $this->assertSame(1, Incident::query()
            ->where('custody_transaction_id', $custody->id)
            ->where('incident_type', 'DAMAGED')
            ->count());

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(route('laundry.complete-processing', $job), [
                'worker_remarks' => null,
            ])
            ->assertSessionHasNoErrors();

        $jobLine->refresh();
        $this->assertSame(1, $jobLine->completed_quantity);
        $this->assertSame(0, $jobLine->affected_quantity);
    }

    public function test_old_fully_encoded_jobs_skip_the_removed_turnover_and_quantity_steps(): void
    {
        [$job, $jobLine, $borrower, $custody] = $this->outstandingLaundryCase(quantity: 2);
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);

        $this->uploadAccomplishedForm($job, $officer)->assertSessionHasNoErrors();
        $this->recordReturn($custody, $officer, [
            $jobLine->custody_line_id => ['FINE' => 2],
        ])->assertSessionHasNoErrors();

        // Simulate a record left behind by the previous duplicate-turnover UI.
        $job->refresh()->update([
            'status' => 'FOR_LAUNDRY',
            'worker_received_at' => null,
        ]);
        $jobLine->refresh()->update(['received_quantity' => 0]);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('laundry.show', $job->fresh()))
            ->assertOk()
            ->assertSeeText('Mark Laundry Complete')
            ->assertSeeText('Serviceable quantity in Laundry')
            ->assertDontSeeText('Confirm Laundry Turnover')
            ->assertDontSeeText('Open SPMU Return');

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(route('laundry.complete-processing', $job->fresh()), [
                'worker_remarks' => 'Legacy record completed without duplicate encoding.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('LAUNDRY_COMPLETED', $job->fresh()->status);
        $this->assertSame(2, $jobLine->fresh()->received_quantity);
        $this->assertSame(2, $jobLine->fresh()->completed_quantity);
    }

    public function test_accomplished_form_upload_stays_in_the_spmu_return_workspace(): void
    {
        [$job, $jobLine, $borrower, $custody] = $this->outstandingLaundryCase(quantity: 2);
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('laundry.show', $job))
            ->assertOk()
            ->assertSeeText('Return linen to the Laundry Area first')
            ->assertSeeText('Open SPMU Return')
            ->assertDontSeeText('Archive accomplished Laundry Form')
            ->assertDontSeeText('Confirm Laundry Turnover');

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('custody.return.show', $custody))
            ->assertOk()
            ->assertSeeText('Upload Form')
            ->assertSeeText('I confirm this is the accomplished Laundry Form signed by Laundry Personnel.');
    }

    private function recordReturn(
        CustodyTransaction $custody,
        User $officer,
        array $accounting,
        array $extra = []
    ) {
        return $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(
                route('custody.return', $custody),
                array_merge(['accounting' => $accounting], $extra)
            );
    }

    private function uploadAccomplishedForm(LaundryJob $job, User $officer)
    {
        return $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(route('laundry.spmu.upload-form', $job), [
                'evidence' => UploadedFile::fake()->create(
                    'accomplished-laundry-form.pdf',
                    20,
                    'application/pdf'
                ),
                'laundry_received_signature_confirmed' => 1,
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
