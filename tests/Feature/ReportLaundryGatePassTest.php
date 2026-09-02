<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\ApprovalStage;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\ApprovalStep;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\GatePass;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\LaundryJob;
use App\Models\LaundryJobLine;
use App\Models\OrganizationalUnit;
use App\Models\RequestItem;
use App\Models\RequestVersion;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Reports\ReportDataset;
use App\Reports\ReportFilters;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Stage 5: Laundry Operations and Off-Campus / Gate Pass.
 *
 * Both reports describe existing workflows rather than defining new rules, so
 * the assertions here are about faithfully reporting what the workflow tables
 * hold: laundry job state and quantities for linen, and the verification →
 * decision → gate pass → release sequence for off-campus requests.
 */
class ReportLaundryGatePassTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationalUnit $unit;

    private Carbon $from;

    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unit = OrganizationalUnit::query()->create([
            'unit_code' => 'LGP',
            'unit_name' => 'Laundry Fixture Unit',
            'unit_type' => 'OFFICE',
            'active' => true,
        ]);

        $this->from = Carbon::create(2026, 4, 1)->startOfDay();
        $this->to = Carbon::create(2026, 4, 30)->endOfDay();

        Carbon::setTestNow(Carbon::create(2026, 4, 20, 9));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* Laundry Operations                                                  */
    /* ------------------------------------------------------------------ */

    public function test_linen_awaiting_laundry_return_is_reported_with_its_workflow_label(): void
    {
        $linen = $this->item('Rectangular Table Cloth', 200, laundryRequired: true);

        $this->laundryJob($linen, issued: 40, received: 0, completed: 0, status: 'FOR_LAUNDRY');

        $row = $this->laundry()->rows->first();

        $this->assertSame('Awaiting Laundry Return', $row['laundry_status']);
        $this->assertSame('40', $row['issued_quantity']);
        $this->assertSame('0', $row['completed_quantity']);
        $this->assertSame('Rectangular Table Cloth', $row['linen_items']);
    }

    public function test_internal_laundry_pending_is_distinguished_from_completed(): void
    {
        $linen = $this->item('Rectangular Table Cloth', 200, laundryRequired: true);

        $this->laundryJob($linen, 40, 40, 0, 'TURNED_OVER_TO_LAUNDRY');
        $this->laundryJob($linen, 25, 25, 25, 'LAUNDRY_COMPLETED');

        $dataset = $this->laundry();

        $this->assertSame(2, $dataset->count());
        $this->assertSame(1, $dataset->summary['Internal laundry pending']);
        $this->assertSame(1, $dataset->summary['Completed']);
    }

    public function test_received_but_uncompleted_linen_is_reported_as_outstanding(): void
    {
        /*
         * Received is not completed: the linen is back with Laundry but has
         * not returned to available stock, so outstanding must reflect that.
         */
        $linen = $this->item('Rectangular Table Cloth', 200, laundryRequired: true);

        $this->laundryJob($linen, 40, 40, 15, 'TURNED_OVER_TO_LAUNDRY');

        $row = $this->laundry()->rows->first();

        $this->assertSame('40', $row['received_quantity']);
        $this->assertSame('15', $row['completed_quantity']);
        $this->assertSame('25', $row['outstanding_quantity']);
    }

    public function test_laundry_report_filters_by_linen_and_status(): void
    {
        $cloth = $this->item('Rectangular Table Cloth', 200, laundryRequired: true);
        $curtain = $this->item('Stage Curtain', 50, laundryRequired: true);

        $this->laundryJob($cloth, 40, 40, 40, 'LAUNDRY_COMPLETED');
        $this->laundryJob($curtain, 10, 0, 0, 'FOR_LAUNDRY');

        $this->assertSame(1, $this->laundry(['linen' => (string) $curtain->id])->count());
        $this->assertSame(1, $this->laundry(['laundry_status' => 'LAUNDRY_COMPLETED'])->count());
        $this->assertSame(2, $this->laundry()->count());
    }

    /* ------------------------------------------------------------------ */
    /* Off-Campus / Gate Pass                                              */
    /* ------------------------------------------------------------------ */

    public function test_on_campus_request_needs_no_verification_and_issues_no_gate_pass(): void
    {
        $this->offCampusRequest(offCampus: false, headDecision: 'APPROVED');

        $row = $this->gatePass()->rows->first();

        $this->assertSame('No', $row['off_campus']);
        $this->assertSame('Not required (on-campus)', $row['verification']);
        $this->assertSame('Not issued', $row['gate_pass_status']);
    }

    public function test_off_campus_request_reports_verification_before_decision(): void
    {
        $this->offCampusRequest(
            offCampus: true,
            verification: 'RECEIVED',
            headDecision: null
        );

        $row = $this->gatePass()->rows->first();

        $this->assertSame('Yes', $row['off_campus']);
        $this->assertSame('Awaiting verification', $row['verification']);
        $this->assertSame('Awaiting decision', $row['decision']);
        $this->assertSame('Not issued', $row['gate_pass_status']);
    }

    public function test_approved_off_campus_request_reports_its_gate_pass_state(): void
    {
        $this->offCampusRequest(
            offCampus: true,
            verification: 'VERIFIED',
            headDecision: 'APPROVED',
            withCustody: true,
            gatePassStatus: 'READY_FOR_PRINTING'
        );

        $row = $this->gatePass()->rows->first();

        $this->assertSame('Verified', $row['verification']);
        $this->assertSame('Approved', $row['decision']);
        $this->assertSame('Ready for printing', $row['gate_pass_status']);
    }

    public function test_permission_to_conduct_is_only_expected_for_a_student_activity(): void
    {
        $this->offCampusRequest(
            offCampus: true,
            verification: 'VERIFIED',
            headDecision: 'APPROVED',
            studentActivity: false
        );

        $this->offCampusRequest(
            offCampus: true,
            verification: 'VERIFIED',
            headDecision: 'APPROVED',
            studentActivity: true
        );

        $rows = $this->gatePass()->rows;

        $notStudent = $rows->firstWhere('student_activity', 'No');
        $student = $rows->firstWhere('student_activity', 'Yes');

        $this->assertSame('No', $notStudent['ptc_required']);
        $this->assertSame('Not required', $notStudent['ptc_on_file']);

        $this->assertSame('Yes', $student['ptc_required']);
        /* Required but not uploaded in this fixture. */
        $this->assertSame('No', $student['ptc_on_file']);
    }

    public function test_gate_pass_report_filters_by_off_campus_student_activity_and_status(): void
    {
        $this->offCampusRequest(offCampus: false, headDecision: 'APPROVED');

        $this->offCampusRequest(
            offCampus: true,
            verification: 'VERIFIED',
            headDecision: 'APPROVED',
            studentActivity: true,
            withCustody: true,
            gatePassStatus: 'VERIFIED'
        );

        $this->assertSame(1, $this->gatePass(['off_campus' => 'YES'])->count());
        $this->assertSame(1, $this->gatePass(['off_campus' => 'NO'])->count());
        $this->assertSame(1, $this->gatePass(['student_activity' => 'YES'])->count());
        $this->assertSame(1, $this->gatePass(['gate_pass_status' => 'VERIFIED'])->count());
        $this->assertSame(1, $this->gatePass(['gate_pass_status' => 'NOT_ISSUED'])->count());
    }

    public function test_screen_and_csv_records_match_for_both_reports(): void
    {
        $linen = $this->item('Rectangular Table Cloth', 200, laundryRequired: true);
        $this->laundryJob($linen, 40, 40, 40, 'LAUNDRY_COMPLETED');
        $this->offCampusRequest(offCampus: true, verification: 'VERIFIED', headDecision: 'APPROVED');

        foreach ([$this->laundry(), $this->gatePass()] as $dataset) {
            $handle = fopen('php://memory', 'r+');
            app(ReportService::class)->writeCsv($dataset, $handle);
            rewind($handle);

            $csv = [];
            while (($line = fgetcsv($handle)) !== false) {
                $csv[] = $line;
            }
            fclose($handle);

            $headerIndex = null;
            foreach ($csv as $index => $line) {
                if ($line === $dataset->columnLabels()) {
                    $headerIndex = $index;

                    break;
                }
            }

            $this->assertNotNull($headerIndex);
            $this->assertSame($dataset->records(), array_values(array_slice($csv, $headerIndex + 1)));
        }
    }

    /* ------------------------------------------------------------------ */
    /* Fixture                                                             */
    /* ------------------------------------------------------------------ */

    private function laundry(array $input = []): ReportDataset
    {
        return $this->generate('laundry', $input);
    }

    private function gatePass(array $input = []): ReportDataset
    {
        return $this->generate('gate-pass', $input);
    }

    private function generate(string $report, array $input = []): ReportDataset
    {
        return app(ReportService::class)->generate(
            ReportFilters::fromRequest(
                Request::create('/reports', 'GET', $input),
                $report,
                $this->from,
                $this->to,
                'month'
            )
        );
    }

    private function item(string $description, int $total, bool $laundryRequired = false): InventoryItem
    {
        $category = InventoryCategory::query()->firstOrCreate(
            ['category_code' => 'LGP'],
            ['category_name' => 'Laundry Fixture', 'active' => true]
        );

        $measure = UnitOfMeasure::query()->firstOrCreate(
            ['unit_code' => 'PC'],
            ['unit_name' => 'Piece', 'active' => true]
        );

        return InventoryItem::query()->create([
            'category_id' => $category->id,
            'unit_id' => $measure->id,
            'unique_description' => $description,
            'total_quantity' => $total,
            'condition_code' => 'SERVICEABLE',
            'borrowable' => true,
            'off_campus_allowed' => true,
            'laundry_required' => $laundryRequired,
            'provisional' => false,
            'active' => true,
        ]);
    }

    private function laundryJob(
        InventoryItem $linen,
        int $issued,
        int $received,
        int $completed,
        string $status
    ): LaundryJob {
        [, $version, $borrower] = $this->requestAndVersion('ACADEMIC', 'College of Computer Studies');

        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-'.fake()->unique()->numberBetween(100000, 999999),
            'request_id' => $version->request_id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => 'RETURN_PROCESSING',
            'due_at' => $this->from->copy()->addDays(12)->endOfDay(),
            'released_at' => $this->from->copy()->addDays(3),
        ]);

        $requestItem = RequestItem::query()->create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $linen->id,
            'description_snapshot' => $linen->unique_description,
            'unit_snapshot' => 'Piece',
            'requested_quantity' => $issued,
            'approved_quantity' => $issued,
        ]);

        $allocation = Allocation::query()->create([
            'request_item_id' => $requestItem->id,
            'period_start' => $this->from->copy()->addDays(2)->startOfDay(),
            'period_end' => $this->from->copy()->addDays(12)->endOfDay(),
            'allocated_quantity' => $issued,
            'released_quantity' => $issued,
            'restored_quantity' => 0,
            'status' => 'RELEASED',
            'allocated_at' => $this->from->copy()->addDay(),
        ]);

        $custodyLine = CustodyLine::query()->create([
            'custody_transaction_id' => $custody->id,
            'request_item_id' => $requestItem->id,
            'allocation_id' => $allocation->id,
            'approved_quantity' => $issued,
            'quantity_to_receive' => $issued,
            'actual_released_quantity' => $issued,
            'returned_quantity' => $received,
        ]);

        $job = LaundryJob::query()->create([
            'custody_transaction_id' => $custody->id,
            'status' => $status,
            'worker_name' => 'Laundry Personnel',
            'worker_received_at' => $received > 0 ? $this->from->copy()->addDays(6) : null,
            'worker_completed_at' => $completed > 0 ? $this->from->copy()->addDays(8) : null,
        ]);

        $job->forceFill([
            'created_at' => $this->from->copy()->addDays(5),
            'updated_at' => $this->from->copy()->addDays(5),
        ])->save();

        LaundryJobLine::query()->create([
            'laundry_job_id' => $job->id,
            'custody_line_id' => $custodyLine->id,
            'issued_quantity' => $issued,
            'received_quantity' => $received,
            'completed_quantity' => $completed,
        ]);

        return $job->refresh();
    }

    private function offCampusRequest(
        bool $offCampus,
        ?string $verification = null,
        ?string $headDecision = null,
        bool $studentActivity = false,
        bool $withCustody = false,
        ?string $gatePassStatus = null,
    ): BorrowingRequest {
        [$request, $version, $borrower] = $this->requestAndVersion(
            'ACADEMIC',
            'College of Computer Studies',
            offCampus: $offCampus,
            studentActivity: $studentActivity
        );

        if ($offCampus && $verification !== null) {
            ApprovalStep::query()->create([
                'request_version_id' => $version->id,
                'stage_code' => ApprovalStage::Spmu,
                'sequence_no' => 1,
                'received_at' => $this->from->copy()->addDay(),
                'decision' => $verification,
                'decided_at' => in_array($verification, ['PENDING', 'RECEIVED'], true)
                    ? null
                    : $this->from->copy()->addDays(2),
            ]);
        }

        ApprovalStep::query()->create([
            'request_version_id' => $version->id,
            'stage_code' => ApprovalStage::Spmu,
            'sequence_no' => 2,
            'received_at' => $this->from->copy()->addDay(),
            'decision' => $headDecision ?: 'RECEIVED',
            'decided_at' => $headDecision === null ? null : $this->from->copy()->addDays(3),
        ]);

        if (! $withCustody) {
            return $request->refresh();
        }

        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-'.fake()->unique()->numberBetween(100000, 999999),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => 'PREPARING_RELEASE',
            'due_at' => $this->from->copy()->addDays(12)->endOfDay(),
        ]);

        if ($gatePassStatus !== null) {
            /*
             * gate_passes.custody_line_id is NOT NULL: a gate pass covers a
             * specific released line, not the transaction as a whole.
             */
            $item = $this->item('Portable Sound System '.fake()->unique()->numberBetween(1, 9999), 20);

            $requestItem = RequestItem::query()->create([
                'request_version_id' => $version->id,
                'inventory_item_id' => $item->id,
                'description_snapshot' => $item->unique_description,
                'unit_snapshot' => 'Piece',
                'requested_quantity' => 2,
                'approved_quantity' => 2,
            ]);

            $allocation = Allocation::query()->create([
                'request_item_id' => $requestItem->id,
                'period_start' => $this->from->copy()->addDays(2)->startOfDay(),
                'period_end' => $this->from->copy()->addDays(12)->endOfDay(),
                'allocated_quantity' => 2,
                'released_quantity' => 0,
                'restored_quantity' => 0,
                'status' => 'ACTIVE',
                'allocated_at' => $this->from->copy()->addDay(),
            ]);

            $custodyLine = CustodyLine::query()->create([
                'custody_transaction_id' => $custody->id,
                'request_item_id' => $requestItem->id,
                'allocation_id' => $allocation->id,
                'approved_quantity' => 2,
                'quantity_to_receive' => 2,
                'actual_released_quantity' => 0,
                'returned_quantity' => 0,
            ]);

            GatePass::query()->create([
                'custody_transaction_id' => $custody->id,
                'custody_line_id' => $custodyLine->id,
                'bearer_name' => $borrower->full_name,
                'purpose' => 'Off-campus activity',
                'destination' => 'Off-campus venue',
                'status' => $gatePassStatus,
                'verified_at' => $gatePassStatus === 'VERIFIED'
                    ? $this->from->copy()->addDays(4)
                    : null,
            ]);
        }

        return $request->refresh();
    }

    /** @return array{0: BorrowingRequest, 1: RequestVersion, 2: User} */
    private function requestAndVersion(
        string $division,
        string $unit,
        bool $offCampus = false,
        bool $studentActivity = false,
    ): array {
        $createdAt = $this->from->copy()->addDay();

        $borrower = User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
        ]);

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-'.fake()->unique()->numberBetween(100000, 999999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $this->unit->id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
        ]);

        $request->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        $version = RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Gate pass fixture activity',
            'location' => 'Off-campus venue',
            'division_code' => $division,
            'office_unit' => $unit,
            'off_campus' => $offCampus,
            'represents_student_activity' => $studentActivity,
            'submitted_at' => $createdAt,
            'schedule_date' => $createdAt->copy()->addDay()->toDateString(),
            'return_date' => $createdAt->copy()->addDays(11)->toDateString(),
            'needed_from' => $createdAt->copy()->addDay()->startOfDay(),
            'return_due_at' => $createdAt->copy()->addDays(11)->endOfDay(),
        ]);

        return [$request, $version, $borrower];
    }
}
