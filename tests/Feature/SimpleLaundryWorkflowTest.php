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
use App\Models\RequestItem;
use App\Models\StoredFile;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

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
        [$job] = $this->laundryCase();
        $worker = $this->classificationUser(AccessClassification::SpmuOfficer);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($worker)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeText('Laundry Operations');

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($worker)
            ->get(route('laundry.show', $job))
            ->assertOk()
            ->assertSeeText('View / Print Laundry Form')
            ->assertSeeText('Borrower turnover only.');
    }

    public function test_only_spmu_action_officer_records_laundry_turnover(): void
    {
        [$job, $line, $borrower] = $this->laundryCase();
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);

        $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->post(route('laundry.receive', $job), [
                'borrower_turnover_signature_confirmed' => 1,
                'lines' => [
                    $line->id => ['received_quantity' => 2],
                ],
            ])
            ->assertForbidden();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(route('laundry.receive', $job), [
                'borrower_turnover_signature_confirmed' => 1,
                'lines' => [
                    $line->id => ['received_quantity' => 2],
                ],
            ])
            ->assertSessionHasNoErrors();

        $job->refresh();
        $line->refresh();

        $this->assertSame('IN_PROCESS', $job->status);
        $this->assertSame($officer->full_name, $job->worker_name);
        $this->assertSame(2.0, (float) $line->received_quantity);
        $this->assertNull($line->issue_type);
        $this->assertNull($line->completed_quantity);

        $this->assertDatabaseHas('notification_events', [
            'event_code' => 'LAUNDRY_RECEIVED',
        ]);
    }

    public function test_action_officer_records_processing_and_head_keeps_read_only_oversight(): void
    {
        [$job, $line] = $this->laundryCase();
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);
        $spmuHead = $this->classificationUser(AccessClassification::SpmuHead);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(route('laundry.receive', $job), [
                'borrower_turnover_signature_confirmed' => 1,
                'lines' => [
                    $line->id => ['received_quantity' => 2],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(route('laundry.complete-processing', $job), [
                'worker_remarks' => 'Processing completed.',
                'lines' => [
                    $line->id => [
                        'issue_type' => 'NONE',
                        'affected_quantity' => 0,
                        'completed_quantity' => 2,
                        'remarks' => null,
                    ],
                ],
            ])
            ->assertSessionHasNoErrors();

        $job->refresh();
        $this->assertSame('READY_FOR_SPMU_RETURN', $job->status);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('laundry.spmu.show', $job))
            ->assertOk()
            ->assertSeeText('Laundry Final Acceptance');

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuHead)
            ->get(route('custody.show', $job->custody_transaction_id))
            ->assertOk()
            ->assertSeeText('Laundry Form');
    }

    public function test_spmu_transcribes_the_scan_and_only_final_spmu_return_makes_linen_available(): void
    {
        [$job, $jobLine] = $this->laundryCase();
        $spmu = $this->classificationUser(AccessClassification::SpmuOfficer);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmu)
            ->post(route('laundry.receive', $job), [
                'borrower_turnover_signature_confirmed' => 1,
                'lines' => [
                    $jobLine->id => ['received_quantity' => 2],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmu)
            ->post(route('laundry.complete-processing', $job), [
                'worker_remarks' => 'One stain was treated; linen completed.',
                'lines' => [
                    $jobLine->id => [
                        'issue_type' => 'STAINED',
                        'affected_quantity' => 1,
                        'completed_quantity' => 2,
                        'remarks' => 'One piece arrived stained and was cleaned.',
                    ],
                ],
            ])
            ->assertSessionHasNoErrors();

        /* Recording Laundry completion alone must never restore inventory. */
        $this->assertDatabaseMissing('inventory_transaction_lines', [
            'inventory_item_id' => $jobLine->custodyLine->requestItem->inventory_item_id,
            'from_state' => 'BORROWED',
            'to_state' => 'AVAILABLE',
        ]);

        $job->refresh();
        $jobLine->refresh();

        $this->assertSame('READY_FOR_SPMU_RETURN', $job->status);
        $this->assertSame($spmu->full_name, $job->worker_name);
        $this->assertSame('STAINED', $jobLine->issue_type);
        $this->assertSame(1.0, (float) $jobLine->affected_quantity);
        $this->assertSame(2.0, (float) $jobLine->completed_quantity);

        /* SPMU form verification still does not make the asset Available. */
        $this->assertDatabaseMissing('inventory_transaction_lines', [
            'inventory_item_id' => $jobLine->custodyLine->requestItem->inventory_item_id,
            'from_state' => 'BORROWED',
            'to_state' => 'AVAILABLE',
        ]);

        $custodyLine = $jobLine->custodyLine;

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmu)
            ->post(route('custody.return', $job->custody), [
                'quantities' => [
                    $custodyLine->id => 2,
                ],
                'conditions' => [
                    $custodyLine->id => 'FINE',
                ],
                'remarks' => 'Cleaned linen physically received and inspected by SPMU.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('inventory_transaction_lines', [
            'inventory_item_id' => $custodyLine->requestItem->inventory_item_id,
            'from_state' => 'BORROWED',
            'to_state' => 'AVAILABLE',
            'quantity' => 2,
        ]);

        $this->assertSame('AWAITING_FINAL_FORM_UPLOAD', $job->fresh()->status);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmu)
            ->post(route('laundry.spmu.upload-form', $job), [
                'evidence' => UploadedFile::fake()->create(
                    'fully-accomplished-laundry-form.pdf',
                    20,
                    'application/pdf'
                ),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('LAUNDRY_COMPLETED', $job->fresh()->status);
        $this->assertNotNull($job->fresh()->completed_at);
        $this->assertDatabaseHas('evidence_submissions', [
            'id' => $job->fresh()->latest_evidence_submission_id,
            'uploaded_by_user_id' => $spmu->id,
            'upload_mode' => 'SPMU_ACTION_OFFICER',
            'verification_status' => 'VERIFIED',
        ]);
    }

    /**
     * @return array{LaundryJob, LaundryJobLine, User}
     */
    private function laundryCase(): array
    {
        $borrower = $this->classificationUser(AccessClassification::BorrowerOnly);
        $item = InventoryItem::query()
            ->where('active', true)
            ->where('borrowable', true)
            ->where('laundry_required', true)
            ->firstOrFail();

        $request = BorrowingRequest::query()->create([
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

        $requestItem = RequestItem::query()->create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => $item->unit->unit_name,
            'requested_quantity' => 2,
            'approved_quantity' => 2,
            'use_location' => 'ON_CAMPUS',
        ]);

        $allocation = Allocation::query()->create([
            'request_item_id' => $requestItem->id,
            'period_start' => $version->needed_from,
            'period_end' => $version->return_due_at,
            'allocated_quantity' => 2,
            'released_quantity' => 2,
            'restored_quantity' => 0,
            'status' => 'RELEASED',
            'allocated_at' => now()->subDay(),
        ]);

        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-LAUNDRY-'.uniqid(),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => 'ACTIVE',
            'released_at' => now()->subHours(6),
            'due_at' => now()->endOfDay(),
        ]);

        $custodyLine = CustodyLine::query()->create([
            'custody_transaction_id' => $custody->id,
            'request_item_id' => $requestItem->id,
            'allocation_id' => $allocation->id,
            'approved_quantity' => 2,
            'quantity_to_receive' => 2,
            'actual_released_quantity' => 2,
            'returned_quantity' => 0,
            'release_condition' => 'SERVICEABLE',
            'item_status' => 'RELEASED_PENDING_RETURN',
            'compliance_status' => 'FOR_LAUNDRY',
        ]);

        $formBytes = '%PDF-1.4 simple laundry form';
        $formPath = 'tests/laundry/'.uniqid().'.pdf';
        Storage::disk('local')->put($formPath, $formBytes);

        $storedFile = StoredFile::query()->create([
            'uploaded_by_user_id' => null,
            'disk' => 'local',
            'storage_path' => $formPath,
            'original_name' => 'laundry-form.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($formBytes),
            'sha256' => hash('sha256', $formBytes),
            'classification' => 'CONTROLLED_DOCUMENT',
        ]);

        $document = GeneratedDocument::query()->create([
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

        $job = LaundryJob::query()->create([
            'custody_transaction_id' => $custody->id,
            'generated_document_id' => $document->id,
            'status' => 'FOR_LAUNDRY',
        ]);

        $jobLine = LaundryJobLine::query()->create([
            'laundry_job_id' => $job->id,
            'custody_line_id' => $custodyLine->id,
            'issued_quantity' => 2,
            'affected_quantity' => 0,
        ]);

        return [
            $job->fresh(['custody.borrower', 'custody.request']),
            $jobLine->fresh(['custodyLine.requestItem']),
            $borrower,
        ];
    }

    private function classificationUser(AccessClassification $classification): User
    {
        return User::query()
            ->where('access_classification', $classification->value)
            ->firstOrFail();
    }
}
