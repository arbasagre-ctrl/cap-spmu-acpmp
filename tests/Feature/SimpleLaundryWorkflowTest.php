<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\GeneratedDocument;
use App\Models\InventoryItem;
use App\Models\LaundryJob;
use App\Models\LaundryJobLine;
use App\Models\NotificationEvent;
use App\Models\RequestItem;
use App\Models\ReturnLine;
use App\Models\ReturnTransaction;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers the CURRENT, simplified linen workflow:
 *
 *   SPMU return inspection (custody.return, disposition=LAUNDRY)
 *     -> Laundry turnover confirmation (laundry.receive)
 *          -> borrower is cleared here; custody may already close
 *     -> internal Laundry completion (laundry.complete-processing)
 *          -> purely internal; never reopens the borrower's obligation
 *     -> signed Laundry Form archival (laundry.spmu.upload-form)
 *          -> documentation only; never itself changes availability
 *
 * There is no Laundry Worker system account anywhere in this flow.
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

    public function test_spmu_action_officer_can_open_laundry_operations(): void
    {
        [$job] = $this->laundryCaseAfterReturnInspection();
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeText('Laundry Operations');

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('laundry.show', $job))
            ->assertOk()
            ->assertSeeText('View generated Laundry Form')
            ->assertSeeText('Laundry Completed / Available')
            ->assertDontSeeText('Back to Stock');
    }

    public function test_only_spmu_action_officer_records_laundry_turnover(): void
    {
        [$job, $jobLine, $borrower] = $this->laundryCaseAfterReturnInspection();
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);

        $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->post(route('laundry.receive', $job), [
                'laundry_received_signature_confirmed' => 1,
                'worker_remarks' => 'Attempted borrower turnover.',
            ])
            ->assertForbidden();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(route('laundry.receive', $job), [
                'laundry_received_signature_confirmed' => 1,
                'worker_remarks' => 'Linen physically received by Laundry Personnel.',
            ])
            ->assertSessionHasNoErrors();

        $job->refresh();
        $jobLine->refresh();

        $this->assertSame('TURNED_OVER_TO_LAUNDRY', $job->status);
        $this->assertSame($officer->full_name, $job->worker_name);
        $this->assertNotNull($job->worker_received_at);
        $this->assertSame(2.0, (float) $jobLine->received_quantity);

        $this->assertDatabaseHas('notification_events', [
            'event_code' => 'LAUNDRY_USED_LINEN_RECEIVED',
        ]);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('laundry.show', $job->fresh()))
            ->assertOk()
            ->assertSeeText('Complete Laundry Processing')
            ->assertSeeText('Archive accomplished Laundry Form')
            ->assertDontSeeText('Record Clean Linen Back to Stock');
    }

    public function test_custody_is_cleared_at_turnover_without_waiting_for_internal_processing_or_archival(): void
    {
        [$job, $jobLine, $borrower, $custody] = $this->laundryCaseAfterReturnInspection();
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(route('laundry.receive', $job), [
                'laundry_received_signature_confirmed' => 1,
                'worker_remarks' => null,
            ])
            ->assertSessionHasNoErrors();

        /*
         * Borrower is cleared (custody CLOSED) the moment Laundry confirms
         * turnover -- the borrower's obligation never waits for washing.
         */
        $custody->refresh();
        $this->assertSame('CLOSED', $custody->status);
        $this->assertNotNull($custody->closed_at);

        $this->assertSame(1, NotificationEvent::query()
            ->where('event_code', 'TRANSACTION_CLOSED')
            ->where('source_type', CustodyTransaction::class)
            ->where('source_id', $custody->id)
            ->count());

        /*
         * At this exact point internal Laundry processing has NOT finished
         * and the Laundry Form has NOT been archived, so this transaction
         * is Borrower Cleared, not fully Completed.
         */
        $job->refresh();
        $this->assertSame('TURNED_OVER_TO_LAUNDRY', $job->status);

        /*
         * Internal Laundry completion happens afterward and must not
         * reopen, re-close, or send another borrower notification.
         */
        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(route('laundry.complete-processing', $job), [
                'worker_remarks' => 'All linen cleaned.',
                'lines' => [
                    $jobLine->id => [
                        'cleaned_quantity' => 2,
                        'damaged_quantity' => 0,
                        'remarks' => null,
                    ],
                ],
            ])
            ->assertSessionHasNoErrors();

        $job->refresh();
        $this->assertSame('LAUNDRY_COMPLETED', $job->status);
        $this->assertSame('CLOSED', $custody->fresh()->status);

        /*
         * Archival is documentation-only and, likewise, must not fire a
         * second borrower-facing TRANSACTION_CLOSED.
         */
        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(route('laundry.spmu.upload-form', $job), [
                'evidence' => UploadedFile::fake()->create(
                    'fully-accomplished-laundry-form.pdf',
                    20,
                    'application/pdf'
                ),
            ])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($job->fresh()->latestEvidence);

        $this->assertSame(1, NotificationEvent::query()
            ->where('event_code', 'TRANSACTION_CLOSED')
            ->where('source_type', CustodyTransaction::class)
            ->where('source_id', $custody->id)
            ->count());
    }

    public function test_linen_inventory_availability_stays_suppressed_until_internal_laundry_completion(): void
    {
        [$job, $jobLine, $borrower, $custody, $item] = $this->laundryCaseAfterReturnInspection();
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);
        $inventory = app(InventoryService::class);

        /*
         * Checkpoint 1: immediately after the return inspection (already
         * recorded by the fixture as disposition=LAUNDRY), the returned
         * quantity must stay excluded from availability -- identical to
         * how it was counted while still physically on custody.
         */
        $afterReturnInspection = $this->currentAvailable($inventory, $item);

        /*
         * Checkpoint 2: after Laundry turnover confirmation, still
         * unavailable -- borrower clearance is not the same as clean stock.
         */
        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(route('laundry.receive', $job), [
                'laundry_received_signature_confirmed' => 1,
                'worker_remarks' => null,
            ])
            ->assertSessionHasNoErrors();

        $afterTurnover = $this->currentAvailable($inventory, $item);
        $this->assertSame($afterReturnInspection, $afterTurnover,
            'Linen must remain unavailable after Laundry turnover, before internal washing is complete.');

        /*
         * Checkpoint 3: after internal Laundry completion, the cleaned
         * quantity becomes available again.
         */
        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(route('laundry.complete-processing', $job), [
                'worker_remarks' => null,
                'lines' => [
                    $jobLine->id => [
                        'cleaned_quantity' => 2,
                        'damaged_quantity' => 0,
                        'remarks' => null,
                    ],
                ],
            ])
            ->assertSessionHasNoErrors();

        $afterCompletion = $this->currentAvailable($inventory, $item);
        $this->assertSame($afterReturnInspection + 2.0, $afterCompletion,
            'Cleaned linen must become available again once internal Laundry completion is recorded.');

        /*
         * Checkpoint 4: archiving the signed Laundry Form is documentation
         * only and must not change availability at all.
         */
        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(route('laundry.spmu.upload-form', $job), [
                'evidence' => UploadedFile::fake()->create(
                    'fully-accomplished-laundry-form.pdf',
                    20,
                    'application/pdf'
                ),
            ])
            ->assertSessionHasNoErrors();

        $afterArchival = $this->currentAvailable($inventory, $item);
        $this->assertSame($afterCompletion, $afterArchival,
            'Archiving the signed Laundry Form must not, by itself, change inventory availability.');
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
     * Builds a released custody with one laundry-required line that has
     * already gone through the SPMU return inspection (disposition=LAUNDRY,
     * fully accounted), and a LaundryJob sitting at FOR_LAUNDRY awaiting
     * turnover -- i.e. the exact state the current LaundryController
     * expects before Laundry Personnel can confirm receipt.
     *
     * @return array{0: LaundryJob, 1: LaundryJobLine, 2: User, 3: CustodyTransaction, 4: InventoryItem}
     */
    private function laundryCaseAfterReturnInspection(): array
    {
        $borrower = $this->classificationUser(AccessClassification::BorrowerOnly);
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);
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
            'event_details' => 'Borrower carries used linen to Laundry and cleaned linen back to SPMU.',
            'off_campus' => false,
            'created_by_user_id' => $borrower->id,
        ]);

        $requestItem = RequestItem::create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => $item->unit->unit_name,
            'requested_quantity' => 2,
            'approved_quantity' => 2,
            'use_location' => 'ON_CAMPUS',
        ]);

        $allocation = Allocation::create([
            'request_item_id' => $requestItem->id,
            'period_start' => $version->needed_from,
            'period_end' => $version->return_due_at,
            'allocated_quantity' => 2,
            'released_quantity' => 2,
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
            'approved_quantity' => 2,
            'quantity_to_receive' => 2,
            'actual_released_quantity' => 2,
            'returned_quantity' => 2,
            'release_condition' => 'SERVICEABLE',
            'item_status' => 'RETURNED',
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

        /*
         * LaundryJob is auto-created at physical release time in the real
         * workflow (CustodyService::release()); replicated here directly.
         */
        $job = LaundryJob::create([
            'custody_transaction_id' => $custody->id,
            'generated_document_id' => $document->id,
            'status' => 'FOR_LAUNDRY',
        ]);

        $jobLine = LaundryJobLine::create([
            'laundry_job_id' => $job->id,
            'custody_line_id' => $custodyLine->id,
            'issued_quantity' => 2,
            'affected_quantity' => 0,
        ]);

        /*
         * The SPMU return inspection already happened: record it exactly
         * as CustodyService::receiveReturn() would, with a FINE condition
         * for a laundry-required item routed to disposition_state=LAUNDRY.
         */
        $return = ReturnTransaction::create([
            'return_no' => 'RET-LAUNDRY-'.uniqid(),
            'custody_transaction_id' => $custody->id,
            'received_by_user_id' => $officer->id,
            'return_type' => 'NORMAL',
            'received_at' => now(),
            'status' => 'INSPECTED',
        ]);

        ReturnLine::create([
            'return_transaction_id' => $return->id,
            'custody_line_id' => $custodyLine->id,
            'quantity_received' => 2,
            'condition_code' => 'FINE',
            'disposition_state' => 'LAUNDRY',
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
