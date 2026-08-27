<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\ApprovalStage;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\ApprovalStep;
use App\Models\BorrowingRequest;
use App\Models\GeneratedDocument;
use App\Models\InventoryItem;
use App\Models\RequestItem;
use App\Models\TemporaryDelegation;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\RequestWorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RevisionControlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_final_access_classifications_enforce_borrowing_and_workspace_rules(): void
    {
        $this->assertClassification(AccessClassification::BorrowerOnly, true, ['BORROWER']);
        $this->assertClassification(AccessClassification::SpmuHead, false, ['SPMU']);
        $this->assertClassification(AccessClassification::SpmuOfficer, false, ['SPMU']);
        $this->assertClassification(AccessClassification::IctuMaintainer, false, ['ICTU']);
        $this->assertFalse(AccessClassification::RetiredInactive->isPortalEnabled());
        $this->assertSame([], AccessClassification::RetiredInactive->workspaces());

        $this->assertFalse(AccessClassification::GsuHead->isPortalEnabled());
        $this->assertFalse(AccessClassification::VpafHead->isPortalEnabled());
        $this->assertSame([], AccessClassification::GsuHead->workspaces());
        $this->assertSame([], AccessClassification::VpafHead->workspaces());
    }

    public function test_formal_spmu_temporary_delegate_uses_own_account_and_is_attributed_to_the_decision(): void
    {
        $spmuHead = User::where(
            'access_classification',
            AccessClassification::SpmuHead->value
        )->firstOrFail();

        $delegate = User::where(
            'access_classification',
            AccessClassification::SpmuOfficer->value
        )->firstOrFail();

        $borrower = User::where(
            'access_classification',
            AccessClassification::BorrowerOnly->value
        )->firstOrFail();

        $ictu = User::where(
            'access_classification',
            AccessClassification::IctuMaintainer->value
        )->firstOrFail();

        /*
         * A formal delegation is SPMU-only in the active workflow.
         * The acting officer still uses their own account.
         */
        $delegate->update([
            'organizational_unit_id' => $spmuHead->organizational_unit_id,
        ]);

        $delegation = TemporaryDelegation::create([
            'office_role' => 'SPMU',
            'absent_head_user_id' => $spmuHead->id,
            'delegate_user_id' => $delegate->id,
            'recorded_by_user_id' => $ictu->id,
            'authority_reference' => 'MEMO-TEST-001',
            'reason' => 'SPMU Head is unavailable.',
            'effective_from' => now()->subHour(),
            'effective_to' => now()->addDay(),
            'status' => 'ACTIVE',
        ]);

        $request = BorrowingRequest::create([
            'request_no' => 'BR-DELEGATE-001',
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::UnderSpmu,
        ]);

        $version = $request->versions()->create([
            'version_no' => 1,
            'purpose_event' => 'SPMU delegation test',
            'location' => 'Campus',
            'needed_from' => now()->addDay(),
            'return_due_at' => now()->addDays(2),
            'created_by_user_id' => $borrower->id,
        ]);

        ApprovalStep::create([
            'request_version_id' => $version->id,
            'stage_code' => ApprovalStage::Spmu,
            'sequence_no' => 1,
            'received_at' => now(),
            'decision' => 'RECEIVED',
        ]);

        /*
         * Use REJECTED here so this focused test verifies delegation
         * attribution without depending on supporting-document and
         * inventory-reservation fixtures.
         */
        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($delegate)
            ->post(
                route('approvals.decide', $request),
                [
                    'decision' => 'REJECTED',
                    'remarks' => 'Delegated SPMU verification test.',
                ]
            )
            ->assertSessionHasNoErrors();

        $this->assertSame(
            RequestStatus::Rejected,
            $request->fresh()->status
        );

        $this->assertDatabaseHas('approval_steps', [
            'request_version_id' => $version->id,
            'stage_code' => ApprovalStage::Spmu->value,
            'approver_user_id' => $delegate->id,
            'temporary_delegation_id' => $delegation->id,
            'decision' => 'REJECTED',
        ]);
    }

    public function test_mixed_request_generates_only_the_applicable_conditional_forms(): void
    {
        [$request, $letter] = $this->approvedRequestWithItems(true, true);
        $borrower = $request->borrower;
        app(RequestWorkflowService::class)->recordApprovedLetterDownload($request, $letter, $borrower, '127.0.0.1', 'test');
        $custody = $request->fresh()->custody;

        $this->assertDatabaseHas('generated_documents', ['subject_id' => $custody->id, 'document_type' => 'GATE_PASS', 'status' => 'FINAL']);
        $this->assertDatabaseHas('generated_documents', ['subject_id' => $custody->id, 'document_type' => 'LAUNDRY_FORM', 'status' => 'FINAL']);
        $this->withSession(['active_workspace' => 'BORROWER'])->actingAs($borrower)->get(route('custody.show', $custody))->assertOk()->assertSee('Approved items for pickup');
        $spmuOfficer = User::where('access_classification', AccessClassification::SpmuOfficer->value)->firstOrFail();
        $this->withSession(['active_workspace' => 'SPMU'])->actingAs($spmuOfficer)->get(route('custody.release.show', $custody))->assertOk()->assertSee('Schedule pickup and notify the borrower');

        [$ordinaryRequest, $ordinaryLetter] = $this->approvedRequestWithItems(false, false, 'BR-ORDINARY-001');
        app(RequestWorkflowService::class)->recordApprovedLetterDownload($ordinaryRequest, $ordinaryLetter, $ordinaryRequest->borrower, '127.0.0.1', 'test');
        $ordinaryCustody = $ordinaryRequest->fresh()->custody;
        $this->assertDatabaseMissing('generated_documents', ['subject_id' => $ordinaryCustody->id, 'document_type' => 'GATE_PASS']);
        $this->assertDatabaseMissing('generated_documents', ['subject_id' => $ordinaryCustody->id, 'document_type' => 'LAUNDRY_FORM']);
    }

    public function test_early_return_route_is_available_under_the_current_policy(): void
    {
        $this->assertTrue(app('router')->has('custody.early-return'));
    }

    private function assertClassification(AccessClassification $classification, bool $mayBorrow, array $workspaces): void
    {
        $user = User::where('access_classification', $classification->value)->firstOrFail();
        $this->assertSame($mayBorrow, $user->mayBorrow());
        $this->assertSame($workspaces, $user->allowedWorkspaces());
    }

    private function approvedRequestWithItems(bool $linen, bool $offCampusBarricade, string $requestNo = 'BR-MIXED-001'): array
    {
        $borrower = User::where('access_classification', AccessClassification::BorrowerOnly->value)->firstOrFail();
        $request = BorrowingRequest::create([
            'request_no' => $requestNo, 'borrower_user_id' => $borrower->id, 'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1, 'status' => RequestStatus::FinalApprovedAwaitingDownload, 'final_approved_at' => now(), 'download_deadline_at' => now()->addHours(4),
        ]);
        $version = $request->versions()->create([
            'version_no' => 1, 'purpose_event' => 'Official form routing test', 'location' => 'CSPC Campus',
            'needed_from' => now()->addDay(), 'return_due_at' => now()->addDays(2), 'created_by_user_id' => $borrower->id,
            'off_campus' => $offCampusBarricade,
        ]);
        $items = [InventoryItem::where('laundry_required', false)->where('off_campus_allowed', false)->firstOrFail()];
        if ($linen) {
            $items[] = InventoryItem::where('laundry_required', true)->firstOrFail();
        }
        if ($offCampusBarricade) {
            $items[] = InventoryItem::where('off_campus_allowed', true)->firstOrFail();
        }
        foreach ($items as $item) {
            $requestItem = RequestItem::create([
                'request_version_id' => $version->id, 'inventory_item_id' => $item->id,
                'description_snapshot' => $item->unique_description, 'unit_snapshot' => $item->unit->unit_name,
                'requested_quantity' => 2, 'approved_quantity' => 2,
                'use_location' => $item->off_campus_allowed && $offCampusBarricade ? 'OFF_CAMPUS' : 'ON_CAMPUS',
            ]);
            Allocation::create([
                'request_item_id' => $requestItem->id, 'period_start' => $version->needed_from, 'period_end' => $version->return_due_at,
                'allocated_quantity' => 2, 'status' => 'ACTIVE', 'allocated_at' => now(),
            ]);
        }
        $letter = app(DocumentService::class)->requestLetter($request->fresh(), true);

        return [$request->fresh(), GeneratedDocument::findOrFail($letter->id)];
    }
}
