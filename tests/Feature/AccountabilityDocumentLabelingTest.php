<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\Allocation;
use App\Models\BillingStatement;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\GeneratedDocument;
use App\Models\Incident;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\OrganizationalUnit;
use App\Models\OverdueCase;
use App\Models\RequestItem;
use App\Models\RequestVersion;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\StoredFile;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\UserSignature;
use App\Services\LateReturnService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Batch 2: document naming, preview consistency, and borrower status details.
 *
 * Covers: the Late Return Billing Statement naming split from the generic
 * property Billing Statement, the Resolved History document links using the
 * in-system documents.preview flow instead of a raw documents.view tab, the
 * borrower-facing RSLDDP stage labels already wired through
 * CustodyTransaction::workflowStatus(), and the AO dashboard's "revision"
 * wording.
 */
class AccountabilityDocumentLabelingTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $expectedReturn;

    protected function setUp(): void
    {
        parent::setUp();

        /* The real late-return assessment derives its final amount from the
           configured tariff.  This fixture drives that real workflow, so it
           must configure the same required policy rather than hand-setting
           an amount that assess() will correctly replace. */
        SystemSetting::query()->updateOrCreate(
            ['setting_key' => 'daily_overdue_tariff'],
            [
                'value_json' => 75,
                'data_type' => 'MONEY',
                'group_code' => 'PENALTY',
                'description' => 'Batch 2 test tariff.',
                'status' => 'ACTIVE',
            ]
        );

        $this->expectedReturn = Carbon::create(2026, 9, 1)->endOfDay();
        Carbon::setTestNow(Carbon::create(2026, 9, 11, 9));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* Fixture                                                             */
    /* ------------------------------------------------------------------ */

    private function user(AccessClassification $classification): User
    {
        $user = User::factory()->create(['access_classification' => $classification]);

        $isSpmu = in_array($classification, [
            AccessClassification::SpmuHead,
            AccessClassification::SpmuOfficer,
        ], true);

        if ($isSpmu) {
            $role = Role::query()->firstOrCreate(
                ['role_code' => UserRole::Spmu->value],
                ['role_name' => 'SPMU', 'active' => true]
            );

            $user->roles()->syncWithoutDetaching([
                $role->id => ['assigned_at' => now()],
            ]);
        }

        return $user->fresh();
    }

    private function borrower(): User
    {
        return $this->user(AccessClassification::BorrowerOnly);
    }

    private function officer(): User
    {
        return $this->user(AccessClassification::SpmuOfficer);
    }

    private function spmuHead(): User
    {
        return $this->user(AccessClassification::SpmuHead);
    }

    /** The Head approval route signs the issued late-return documents. */
    private function registerSignature(User $user): void
    {
        if (UserSignature::query()
            ->where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->exists()) {
            return;
        }

        $bytes = "\x89PNG\r\n\x1a\n".'batch-2-signature-'.$user->id;
        $path = 'tests/signatures/'.$user->id.'/signature.png';

        Storage::disk('local')->put($path, $bytes);

        $file = StoredFile::query()->create([
            'uploaded_by_user_id' => $user->id,
            'disk' => 'local',
            'storage_path' => $path,
            'original_name' => 'signature.png',
            'mime_type' => 'image/png',
            'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'classification' => 'SIGNATURE',
        ]);

        UserSignature::query()->create([
            'user_id' => $user->id,
            'stored_file_id' => $file->id,
            'effective_from' => now()->subMinute(),
            'effective_to' => null,
            'status' => 'ACTIVE',
        ]);
    }

    private function organizationalUnit(): OrganizationalUnit
    {
        return OrganizationalUnit::query()->firstOrCreate(
            ['unit_code' => 'BATCH2FIX'],
            ['unit_name' => 'Batch 2 Fixture Unit', 'unit_type' => 'OFFICE', 'active' => true]
        );
    }

    private function inventoryItem(): InventoryItem
    {
        $category = InventoryCategory::query()->firstOrCreate(
            ['category_code' => 'BATCH2'],
            ['category_name' => 'Batch 2 Fixture', 'active' => true]
        );

        $measure = UnitOfMeasure::query()->firstOrCreate(
            ['unit_code' => 'PC'],
            ['unit_name' => 'Piece', 'active' => true]
        );

        return InventoryItem::query()->create([
            'category_id' => $category->id,
            'unit_id' => $measure->id,
            'unique_description' => 'Batch 2 Equipment '.fake()->unique()->numberBetween(1, 99999),
            'total_quantity' => 50,
            'condition_code' => 'SERVICEABLE',
            'borrowable' => true,
            'off_campus_allowed' => false,
            'laundry_required' => false,
            'provisional' => false,
            'active' => true,
        ]);
    }

    /** A released custody carrying one line. */
    private function custody(User $borrower, string $status = 'BORROWED'): CustodyTransaction
    {
        $unit = $this->organizationalUnit();
        $item = $this->inventoryItem();

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-BATCH2-'.fake()->unique()->numberBetween(1000, 999999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $unit->id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
        ]);

        $version = RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Batch 2 fixture',
            'location' => 'Campus',
            'division_code' => 'ACADEMIC',
            'office_unit' => 'College of Computer Studies',
            'schedule_date' => '2026-08-28',
            'return_date' => $this->expectedReturn->toDateString(),
            'needed_from' => Carbon::create(2026, 8, 28)->startOfDay(),
            'return_due_at' => $this->expectedReturn,
        ]);

        $requestItem = RequestItem::query()->create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => 'Piece',
            'requested_quantity' => 5,
            'approved_quantity' => 5,
        ]);

        $allocation = Allocation::query()->create([
            'request_item_id' => $requestItem->id,
            'period_start' => Carbon::create(2026, 8, 28)->startOfDay(),
            'period_end' => $this->expectedReturn,
            'allocated_quantity' => 5,
            'released_quantity' => 5,
            'restored_quantity' => 0,
            'status' => 'RELEASED',
            'allocated_at' => Carbon::create(2026, 8, 28),
        ]);

        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-BATCH2-'.fake()->unique()->numberBetween(1000, 999999),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => $status,
            'due_at' => $this->expectedReturn,
            'released_at' => Carbon::create(2026, 8, 28, 9),
        ]);

        CustodyLine::query()->create([
            'custody_transaction_id' => $custody->id,
            'request_item_id' => $requestItem->id,
            'allocation_id' => $allocation->id,
            'approved_quantity' => 5,
            'quantity_to_receive' => 5,
            'actual_released_quantity' => 5,
            'returned_quantity' => 5,
        ]);

        return $custody->fresh(['lines.requestItem.inventoryItem']);
    }

    private function overdueCase(CustodyTransaction $custody): OverdueCase
    {
        return OverdueCase::query()->create([
            'custody_transaction_id' => $custody->id,
            'borrower_user_id' => $custody->borrower_user_id,
            'grace_expires_at' => $custody->due_at,
            'overdue_started_at' => $this->expectedReturn->copy()->addDay()->startOfDay(),
            'offense_level' => 1,
            'rate_snapshot' => 75,
            'accrued_amount' => 750,
            'status' => LateReturnService::STATUS_OVERDUE,
        ]);
    }

    private function recordReturn(CustodyTransaction $custody, Carbon $receivedAt, User $receiver): void
    {
        $custody->lines->each(fn ($line) => $line->update([
            'returned_quantity' => $line->actual_released_quantity,
        ]));

        $returnId = DB::table('return_transactions')->insertGetId([
            'return_no' => 'RT-BATCH2-'.fake()->unique()->numberBetween(1000, 999999),
            'custody_transaction_id' => $custody->id,
            'received_by_user_id' => $receiver->id,
            'return_type' => 'OVERDUE',
            'received_at' => $receivedAt,
            'status' => 'INSPECTED',
            'created_at' => $receivedAt,
            'updated_at' => $receivedAt,
        ]);

        foreach ($custody->lines as $line) {
            DB::table('return_lines')->insert([
                'return_transaction_id' => $returnId,
                'custody_line_id' => $line->id,
                'quantity_received' => $line->actual_released_quantity,
                'condition_code' => 'SERVICEABLE',
                'disposition_state' => 'AVAILABLE',
                'created_at' => $receivedAt,
                'updated_at' => $receivedAt,
            ]);
        }
    }

    /**
     * Drive a fresh custody all the way to a Head-approved late-return
     * Billing Statement, exactly like the real workflow (overdue -> physical
     * return -> automatic assessment -> Head approval/billing).
     *
     * @return array{0: OverdueCase, 1: BillingStatement}
     */
    private function lateReturnBilling(): array
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, 'OVERDUE');
        $this->overdueCase($custody);
        $officer = $this->officer();
        $head = $this->spmuHead();
        $this->registerSignature($head);

        $this->recordReturn($custody, Carbon::create(2026, 9, 4, 14), $officer);
        $case = app(LateReturnService::class)->assess($custody->fresh(['lines', 'returns']), $officer);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($head)
            ->post(route('overdue.bill', $case), ['basis' => 'Three late calendar days at the configured tariff.'])
            ->assertSessionHasNoErrors();

        $billing = BillingStatement::query()->where('borrower_user_id', $borrower->id)->firstOrFail();

        return [$case->fresh(), $billing];
    }

    /**
     * Drive a fresh property Incident to a Head-issued Billing Statement,
     * matching the real billIncident() flow.
     *
     * @return array{0: Incident, 1: BillingStatement}
     */
    private function propertyBilling(): array
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, 'OBLIGATION_OPEN');
        $officer = $this->officer();
        $head = $this->spmuHead();

        $incident = Incident::query()->create([
            'incident_no' => 'INC-BATCH2-'.fake()->unique()->numberBetween(1000, 999999),
            'custody_transaction_id' => $custody->id,
            'borrower_user_id' => $borrower->id,
            'reported_by_user_id' => $officer->id,
            'incident_type' => 'DAMAGED',
            'reported_at' => now(),
            'status' => 'FOR_BILLING',
        ]);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($head)
            ->post(route('incidents.bill', $incident), [
                'amount' => 500,
                'basis' => 'Approved appraisal for the damaged item.',
            ])
            ->assertSessionHasNoErrors();

        $billing = BillingStatement::query()->where('borrower_user_id', $borrower->id)->firstOrFail();

        return [$incident->fresh(), $billing];
    }

    /* ------------------------------------------------------------------ */
    /* 1. Late Return Billing Statement naming                            */
    /* ------------------------------------------------------------------ */

    public function test_late_return_billing_is_labeled_late_return_billing_statement_everywhere(): void
    {
        [, $billing] = $this->lateReturnBilling();
        $billing->load('lines');

        $this->assertTrue($billing->isLateReturnBilling());
        $this->assertSame('Late Return Billing Statement', $billing->displayLabel());

        $document = GeneratedDocument::query()
            ->where('subject_type', BillingStatement::class)
            ->where('subject_id', $billing->id)
            ->where('document_type', 'BILLING_STATEMENT')
            ->firstOrFail();

        $head = $this->spmuHead();

        /* Document preview title. */
        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($head)
            ->get(route('documents.preview', $document))
            ->assertOk()
            ->assertSee('Late Return Billing Statement');

        /* Current Accountability actions/details. */
        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($head)
            ->get(route('accountability.index'))
            ->assertOk()
            ->assertSee('The Late Return Notice and Late Return Billing Statement have been issued.')
            ->assertSee('Preview')
            ->assertDontSee('Open Late Return Billing Statement');
    }

    public function test_property_billing_keeps_its_legacy_generic_billing_statement_label(): void
    {
        [, $billing] = $this->propertyBilling();
        $billing->load('lines');

        $this->assertFalse($billing->isLateReturnBilling());
        $this->assertSame('Billing Statement', $billing->displayLabel());

        $document = GeneratedDocument::query()
            ->where('subject_type', BillingStatement::class)
            ->where('subject_id', $billing->id)
            ->where('document_type', 'BILLING_STATEMENT')
            ->firstOrFail();

        $head = $this->spmuHead();

        $response = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($head)
            ->get(route('documents.preview', $document));

        $response->assertOk()
            ->assertSee('Billing Statement')
            ->assertDontSee('Late Return Billing Statement');
    }

    /**
     * The generated PDF itself is binary (DocumentService::saveHtml()
     * rasterizes it), so this renders the same source template the real
     * DocumentService::lateReturnNotice() renders, exactly like the existing
     * sanction-notice title test does, instead of inspecting a compiled PDF.
     */
    public function test_late_return_notice_template_names_the_late_return_billing_statement(): void
    {
        $html = view('documents.accountability.late-return-notice', [
            'case' => new \App\Models\OverdueCase(),
            'reference' => 'LRN-000001',
            'logoDataUri' => 'data:image/png;base64,',
            'borrowerName' => 'Test Borrower',
            'officeUnit' => 'Test Office',
            'requestNo' => 'BR-0001',
            'custodyNo' => 'CUS-0001',
            'expectedReturnDate' => '01 September 2026',
            'actualReturnDate' => '04 September 2026',
            'lateDays' => 3,
            'rate' => 75.0,
            'amount' => 225.0,
            'disposition' => 'Billing Required',
            'decisionBasis' => 'Three late calendar days at the configured tariff.',
            'aoConfirmedBy' => null,
            'aoConfirmedAt' => null,
            'headName' => 'SPMU Head',
            'headDesignation' => '',
            'headDate' => '05 September 2026',
            'headSignatureHtml' => '',
            'generatedAt' => '05 September 2026, 9:00 AM',
            'items' => [],
        ])->render();

        $this->assertStringContainsString('A separate Late Return Billing Statement is the financial document used for CSPC Cashier settlement.', $html);
        $this->assertStringNotContainsString('A separate Billing Statement is the financial document', $html);
    }

    /* ------------------------------------------------------------------ */
    /* 2. Resolved History preview flow                                   */
    /* ------------------------------------------------------------------ */

    public function test_resolved_history_document_links_use_the_documents_preview_flow(): void
    {
        [$case, $billing] = $this->lateReturnBilling();
        $officer = $this->officer();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(route('payments.store', $billing), [
                'evidence' => \Illuminate\Http\UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf'),
                'official_receipt_no' => 'OR-BATCH2-000123',
                'receipt_date' => '2026-09-08',
                'amount' => 225,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(LateReturnService::STATUS_RESOLVED, $case->fresh()->status);

        $billingDocument = GeneratedDocument::query()
            ->where('subject_type', BillingStatement::class)
            ->where('subject_id', $billing->id)
            ->firstOrFail();

        $lateReturnNotice = GeneratedDocument::query()
            ->where('subject_type', OverdueCase::class)
            ->where('subject_id', $case->id)
            ->where('document_type', 'LATE_RETURN_NOTICE')
            ->firstOrFail();

        $response = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('accountability.index', ['view' => 'resolved']));

        $response->assertOk()
            ->assertSee(route('documents.preview', $billingDocument, false), false)
            ->assertSee(route('documents.preview', $lateReturnNotice, false), false)
            ->assertDontSee(route('documents.view', $billingDocument, false), false)
            ->assertDontSee(route('documents.view', $lateReturnNotice, false), false);
    }

    /* ------------------------------------------------------------------ */
    /* 3. Borrower-facing specific RSLDDP stage labels                    */
    /* ------------------------------------------------------------------ */

    public function test_borrower_my_borrowings_and_my_requests_show_the_specific_current_rslddp_stage(): void
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, 'OBLIGATION_OPEN');
        $officer = $this->officer();

        Incident::query()->create([
            'incident_no' => 'INC-BATCH2-STAGE-'.fake()->unique()->numberBetween(1000, 999999),
            'custody_transaction_id' => $custody->id,
            'borrower_user_id' => $borrower->id,
            'reported_by_user_id' => $officer->id,
            'incident_type' => 'DAMAGED',
            'reported_at' => now(),
            'status' => 'RSLDDP_FOR_ACCOUNTING_PROCESSING',
        ]);

        $myBorrowings = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('custody.index'));

        $myBorrowings->assertOk()
            ->assertSee('For Accounting Processing')
            /* Raw status keys remain as non-visible data values used by the
               in-page filter. The regression is about the visible label. */
            ->assertDontSee('>Rslddp For Accounting Processing<', false)
            ->assertDontSee('>RSLDDP_FOR_ACCOUNTING_PROCESSING<', false)
            ->assertDontSee('Accountability Pending')
            ->assertDontSee('Accountability Review');

        $myRequests = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('requests.index'));

        $myRequests->assertOk()
            ->assertSee('For Accounting Processing')
            ->assertDontSee('>Rslddp For Accounting Processing<', false)
            ->assertDontSee('>RSLDDP_FOR_ACCOUNTING_PROCESSING<', false)
            ->assertDontSee('Accountability Pending')
            ->assertDontSee('Accountability Review');
    }

    /* ------------------------------------------------------------------ */
    /* 4. AO dashboard wording                                            */
    /* ------------------------------------------------------------------ */

    public function test_ao_dashboard_request_verification_copy_says_revision_not_correction(): void
    {
        $borrower = $this->borrower();
        $officer = $this->officer();
        $unit = $this->organizationalUnit();

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-BATCH2-VERIFY-'.fake()->unique()->numberBetween(1000, 999999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $unit->id,
            'current_version_no' => 1,
            'status' => RequestStatus::UnderSpmu,
        ]);

        $version = RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'AO dashboard revision wording fixture',
            'location' => 'Campus',
            'division_code' => 'ACADEMIC',
            'office_unit' => 'College of Computer Studies',
            'schedule_date' => now()->addDays(2)->toDateString(),
            'return_date' => now()->addDays(3)->toDateString(),
            'needed_from' => now()->addDays(2)->startOfDay(),
            'return_due_at' => now()->addDays(3)->endOfDay(),
        ]);

        $version->approvalSteps()->create([
            'stage_code' => 'SPMU',
            'sequence_no' => 1,
            'received_at' => now(),
            'decision' => 'RECEIVED',
        ]);

        $response = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('return it for revision')
            ->assertDontSee('return it for correction');
    }
}
