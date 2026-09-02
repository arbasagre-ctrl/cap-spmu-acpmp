<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\BorrowerViolation;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\Incident;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\OrganizationalUnit;
use App\Models\OverdueCase;
use App\Models\RequestItem;
use App\Models\RequestVersion;
use App\Models\ReturnTransaction;
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
 * Stage 3: Release & Custody and Return & Accountability.
 *
 * The rule under test throughout is that approval is not release and, once a
 * custody transaction exists, the custody and return lifecycle is what the
 * report must follow. The consolidation of Overdue and Accountability into
 * the return report is checked by asserting that a transaction which is both
 * overdue and carrying an open incident produces exactly one row.
 */
class ReportReleaseReturnTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationalUnit $unit;

    private Carbon $from;

    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unit = OrganizationalUnit::query()->create([
            'unit_code' => 'RELRET',
            'unit_name' => 'Release Fixture Unit',
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
    /* Release & Custody                                                   */
    /* ------------------------------------------------------------------ */

    public function test_approved_but_unreleased_request_is_excluded_from_the_release_report(): void
    {
        /* Approved at the request level, but no custody record was created. */
        $this->request(
            division: 'ACADEMIC',
            unit: 'College of Computer Studies',
            status: RequestStatus::ApprovedReadyForRelease
        );

        $this->assertSame(0, $this->release()->count());
    }

    public function test_custody_awaiting_release_appears_without_a_release_timestamp(): void
    {
        $this->request(
            division: 'ACADEMIC',
            unit: 'College of Computer Studies',
            status: RequestStatus::ApprovedReadyForRelease,
            custody: ['status' => 'PREPARING_RELEASE']
        );

        $row = $this->release()->rows->first();

        $this->assertSame('Preparing Release', $row['status']);
        $this->assertSame('', $row['released_at']);
    }

    public function test_physically_released_custody_reports_the_release_facts(): void
    {
        $item = $this->item('Monoblock Chair', 300);

        $this->request(
            division: 'ACADEMIC',
            unit: 'College of Computer Studies',
            status: RequestStatus::ApprovedReadyForRelease,
            lines: [['item' => $item, 'released' => 40, 'returned' => 0]],
            custody: [
                'status' => 'ACTIVE',
                'released_at' => $this->from->copy()->addDays(3),
            ]
        );

        $row = $this->release()->rows->first();

        $this->assertSame('Released / On Custody', $row['status']);
        $this->assertNotSame('', $row['released_at']);
        $this->assertSame('40', $row['released_quantity']);
        $this->assertSame('40', $row['outstanding_quantity']);
    }

    public function test_release_report_filters_by_division_and_equipment(): void
    {
        $chair = $this->item('Monoblock Chair', 300);
        $table = $this->item('Round Table', 60);

        $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            RequestStatus::ApprovedReadyForRelease,
            lines: [['item' => $chair, 'released' => 10, 'returned' => 0]],
            custody: ['status' => 'ACTIVE', 'released_at' => $this->from->copy()->addDays(3)]
        );

        $this->request(
            'ADMINISTRATION',
            'Library',
            RequestStatus::ApprovedReadyForRelease,
            lines: [['item' => $table, 'released' => 5, 'returned' => 0]],
            custody: ['status' => 'ACTIVE', 'released_at' => $this->from->copy()->addDays(4)]
        );

        $this->assertSame(1, $this->release(['division' => 'ACADEMIC'])->count());
        $this->assertSame(1, $this->release(['equipment' => (string) $table->id])->count());
        $this->assertSame(2, $this->release()->count());
    }

    /* ------------------------------------------------------------------ */
    /* Return & Accountability                                             */
    /* ------------------------------------------------------------------ */

    public function test_return_completed_before_the_due_date_is_reported_on_time(): void
    {
        $item = $this->item('Monoblock Chair', 300);
        $due = $this->from->copy()->addDays(10);

        $custody = $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            RequestStatus::ApprovedReadyForRelease,
            lines: [['item' => $item, 'released' => 20, 'returned' => 20]],
            custody: [
                'status' => 'CLOSED',
                'released_at' => $this->from->copy()->addDays(3),
                'due_at' => $due,
                'closed_at' => $this->from->copy()->addDays(8),
            ],
            returnDate: $due
        )->custody;

        $this->recordReturn($custody, $this->from->copy()->addDays(8));

        $row = $this->returns()->rows->first();

        $this->assertSame('Returned on time', $row['return_status']);
        $this->assertSame('0', $row['outstanding_quantity']);
    }

    public function test_return_completed_after_the_due_date_is_reported_late(): void
    {
        $item = $this->item('Monoblock Chair', 300);

        $custody = $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            RequestStatus::ApprovedReadyForRelease,
            lines: [['item' => $item, 'released' => 20, 'returned' => 20]],
            custody: [
                'status' => 'CLOSED',
                'released_at' => $this->from->copy()->addDay(),
                'due_at' => $this->from->copy()->addDays(5),
                'closed_at' => $this->from->copy()->addDays(9),
            ],
            returnDate: $this->from->copy()->addDays(5)
        )->custody;

        $this->recordReturn($custody, $this->from->copy()->addDays(9));

        $this->assertSame('Returned late', $this->returns()->rows->first()['return_status']);
    }

    public function test_unreturned_items_past_the_due_date_are_reported_currently_overdue(): void
    {
        $item = $this->item('Monoblock Chair', 300);

        /* now() is 20 Apr; the due date of 10 Apr has passed. */
        $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            RequestStatus::ApprovedReadyForRelease,
            lines: [['item' => $item, 'released' => 20, 'returned' => 0]],
            custody: [
                'status' => 'OVERDUE',
                'released_at' => $this->from->copy()->addDays(2),
                'due_at' => $this->from->copy()->addDays(9),
            ],
            returnDate: $this->from->copy()->addDays(9)
        );

        $row = $this->returns()->rows->first();

        $this->assertSame('Currently overdue', $row['return_status']);
        $this->assertSame('20', $row['outstanding_quantity']);
    }

    public function test_active_custody_within_the_due_date_is_reported_as_still_on_custody(): void
    {
        $item = $this->item('Monoblock Chair', 300);

        $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            RequestStatus::ApprovedReadyForRelease,
            lines: [['item' => $item, 'released' => 20, 'returned' => 0]],
            custody: [
                'status' => 'ACTIVE',
                'released_at' => $this->from->copy()->addDays(18),
                'due_at' => $this->from->copy()->addDays(25),
            ],
            returnDate: $this->from->copy()->addDays(25)
        );

        $this->assertSame('Still on custody', $this->returns()->rows->first()['return_status']);
    }

    public function test_open_and_closed_accountability_are_distinguished(): void
    {
        $item = $this->item('Monoblock Chair', 300);

        $open = $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            RequestStatus::ApprovedReadyForRelease,
            lines: [['item' => $item, 'released' => 20, 'returned' => 0]],
            custody: [
                'status' => 'INCIDENT_OPEN',
                'released_at' => $this->from->copy()->addDays(2),
                'due_at' => $this->from->copy()->addDays(25),
            ],
            returnDate: $this->from->copy()->addDays(25)
        )->custody;

        $this->incident($open, 'REPORTED');

        $closed = $this->request(
            'ADMINISTRATION',
            'Library',
            RequestStatus::ApprovedReadyForRelease,
            lines: [['item' => $item, 'released' => 5, 'returned' => 5]],
            custody: [
                'status' => 'CLOSED',
                'released_at' => $this->from->copy()->addDays(2),
                'due_at' => $this->from->copy()->addDays(25),
                'closed_at' => $this->from->copy()->addDays(6),
            ],
            returnDate: $this->from->copy()->addDays(25)
        )->custody;

        $this->incident($closed, 'RESOLVED');
        $this->recordReturn($closed, $this->from->copy()->addDays(6));

        $this->assertSame(1, $this->returns(['open_accountability' => 'OPEN'])->count());
        $this->assertSame(1, $this->returns(['open_accountability' => 'NONE'])->count());

        $openRow = $this->returns(['open_accountability' => 'OPEN'])->rows->first();

        $this->assertSame('Open Accountability', $openRow['accountability']);
        $this->assertSame('1', $openRow['open_incidents']);
    }

    public function test_a_transaction_that_is_overdue_and_has_an_incident_produces_one_row(): void
    {
        /*
         * Overdue and Accountability used to be separate reports. Merging
         * them must not double-count a transaction that appears in both.
         */
        $item = $this->item('Monoblock Chair', 300);

        $custody = $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            RequestStatus::ApprovedReadyForRelease,
            lines: [['item' => $item, 'released' => 20, 'returned' => 0]],
            custody: [
                'status' => 'OVERDUE',
                'released_at' => $this->from->copy()->addDays(2),
                'due_at' => $this->from->copy()->addDays(9),
            ],
            returnDate: $this->from->copy()->addDays(9)
        )->custody;

        $this->incident($custody, 'REPORTED');

        OverdueCase::query()->create([
            'custody_transaction_id' => $custody->id,
            'borrower_user_id' => $custody->borrower_user_id,
            /* grace_expires_at is NOT NULL: the grace window always exists. */
            'grace_expires_at' => $this->from->copy()->addDays(10),
            'overdue_started_at' => $this->from->copy()->addDays(10),
            'offense_level' => 1,
            'rate_snapshot' => 25,
            'accrued_amount' => 100,
            'status' => 'OPEN',
        ]);

        BorrowerViolation::query()->create([
            'borrower_user_id' => $custody->borrower_user_id,
            'custody_transaction_id' => $custody->id,
            'violation_code' => 'LATE_RETURN',
            'status' => 'CONFIRMED',
            'detected_at' => $this->from->copy()->addDays(11),
        ]);

        $dataset = $this->returns();

        $this->assertSame(1, $dataset->count());

        $row = $dataset->rows->first();

        $this->assertSame('Currently overdue', $row['return_status']);
        $this->assertSame('Open Accountability', $row['accountability']);
        $this->assertSame('1', $row['confirmed_violations']);
        $this->assertNotSame('', $row['overdue_started_at']);
    }

    public function test_return_report_filters_by_division_and_return_status(): void
    {
        $item = $this->item('Monoblock Chair', 300);

        $onTime = $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            RequestStatus::ApprovedReadyForRelease,
            lines: [['item' => $item, 'released' => 10, 'returned' => 10]],
            custody: [
                'status' => 'CLOSED',
                'released_at' => $this->from->copy()->addDay(),
                'due_at' => $this->from->copy()->addDays(12),
                'closed_at' => $this->from->copy()->addDays(5),
            ],
            returnDate: $this->from->copy()->addDays(12)
        )->custody;

        $this->recordReturn($onTime, $this->from->copy()->addDays(5));

        $this->request(
            'ADMINISTRATION',
            'Library',
            RequestStatus::ApprovedReadyForRelease,
            lines: [['item' => $item, 'released' => 10, 'returned' => 0]],
            custody: [
                'status' => 'OVERDUE',
                'released_at' => $this->from->copy()->addDay(),
                'due_at' => $this->from->copy()->addDays(9),
            ],
            returnDate: $this->from->copy()->addDays(9)
        );

        $this->assertSame(1, $this->returns(['division' => 'ACADEMIC'])->count());
        $this->assertSame(1, $this->returns(['return_status' => 'RETURNED_ON_TIME'])->count());
        $this->assertSame(1, $this->returns(['return_status' => 'CURRENTLY_OVERDUE'])->count());
        $this->assertSame(0, $this->returns(['return_status' => 'RETURNED_LATE'])->count());
    }

    public function test_screen_and_csv_records_match_for_both_reports(): void
    {
        $item = $this->item('Monoblock Chair', 300);

        $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            RequestStatus::ApprovedReadyForRelease,
            lines: [['item' => $item, 'released' => 10, 'returned' => 0]],
            custody: ['status' => 'ACTIVE', 'released_at' => $this->from->copy()->addDays(3)]
        );

        foreach ([$this->release(), $this->returns()] as $dataset) {
            $this->assertSame($dataset->records(), $this->csvRecords($dataset));
        }
    }

    /* ------------------------------------------------------------------ */
    /* Fixture                                                             */
    /* ------------------------------------------------------------------ */

    private function release(array $input = []): ReportDataset
    {
        return $this->generate('custody', $input);
    }

    private function returns(array $input = []): ReportDataset
    {
        return $this->generate('returns', $input);
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

    /** @return list<list<string>> */
    private function csvRecords(ReportDataset $dataset): array
    {
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

        $this->assertNotNull($headerIndex, 'CSV did not contain the column header row.');

        return array_values(array_slice($csv, $headerIndex + 1));
    }

    private function item(string $description, int $total = 100): InventoryItem
    {
        $category = InventoryCategory::query()->firstOrCreate(
            ['category_code' => 'RELRET'],
            ['category_name' => 'Release Fixture', 'active' => true]
        );

        $measure = UnitOfMeasure::query()->firstOrCreate(
            ['unit_code' => 'PC'],
            ['unit_name' => 'Piece', 'active' => true]
        );

        return InventoryItem::query()->firstOrCreate(
            ['unique_description' => $description],
            [
                'category_id' => $category->id,
                'unit_id' => $measure->id,
                'total_quantity' => $total,
                'condition_code' => 'SERVICEABLE',
                'borrowable' => true,
                'off_campus_allowed' => false,
                'laundry_required' => false,
                'provisional' => false,
                'active' => true,
            ]
        );
    }

    private function incident(CustodyTransaction $custody, string $status): Incident
    {
        /* reported_by_user_id is NOT NULL: an incident always has a reporter. */
        $reporter = User::factory()->create([
            'access_classification' => AccessClassification::SpmuOfficer,
        ]);

        return Incident::query()->create([
            'incident_no' => 'INC-'.fake()->unique()->numberBetween(10000, 99999),
            'custody_transaction_id' => $custody->id,
            'borrower_user_id' => $custody->borrower_user_id,
            'reported_by_user_id' => $reporter->id,
            'incident_type' => 'DAMAGE',
            'reported_at' => $this->from->copy()->addDays(12),
            'status' => $status,
        ]);
    }

    private function recordReturn(CustodyTransaction $custody, Carbon $receivedAt): ReturnTransaction
    {
        /* received_by_user_id is NOT NULL: a receipt always has a receiver. */
        $receiver = User::factory()->create([
            'access_classification' => AccessClassification::SpmuOfficer,
        ]);

        return ReturnTransaction::query()->create([
            'return_no' => 'RET-'.fake()->unique()->numberBetween(10000, 99999),
            'custody_transaction_id' => $custody->id,
            'received_by_user_id' => $receiver->id,
            'return_type' => 'NORMAL',
            'received_at' => $receivedAt,
        ]);
    }

    /**
     * @param  array<int, array{item: InventoryItem, released: int, returned: int}>  $lines
     */
    private function request(
        string $division,
        string $unit,
        RequestStatus $status = RequestStatus::UnderSpmu,
        array $lines = [],
        ?array $custody = null,
        ?Carbon $returnDate = null,
    ): BorrowingRequest {
        $createdAt = $this->from->copy()->addDay();

        $borrower = User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
        ]);

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-'.$createdAt->format('YmdHis').'-'.fake()->unique()->numberBetween(1000, 99999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $this->unit->id,
            'current_version_no' => 1,
            'status' => $status,
        ]);

        $request->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        $returnDate ??= $createdAt->copy()->addDays(3);

        $version = RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Release fixture activity',
            'location' => 'Campus',
            'division_code' => $division,
            'office_unit' => $unit,
            'schedule_date' => $createdAt->copy()->addDay()->toDateString(),
            'return_date' => $returnDate->toDateString(),
            'needed_from' => $createdAt->copy()->addDay()->startOfDay(),
            'return_due_at' => $returnDate->copy()->endOfDay(),
        ]);

        if ($custody === null) {
            return $request->refresh();
        }

        $transaction = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-'.$createdAt->format('Ymd').'-'.fake()->unique()->numberBetween(1000, 99999),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => $custody['status'],
            'due_at' => $custody['due_at'] ?? $returnDate->copy()->endOfDay(),
            'released_at' => $custody['released_at'] ?? null,
            'closed_at' => $custody['closed_at'] ?? null,
        ]);

        $transaction->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        foreach ($lines as $line) {
            $requestItem = RequestItem::query()->create([
                'request_version_id' => $version->id,
                'inventory_item_id' => $line['item']->id,
                'description_snapshot' => $line['item']->unique_description,
                'unit_snapshot' => 'Piece',
                'requested_quantity' => $line['released'],
                'approved_quantity' => $line['released'],
            ]);

            $allocation = Allocation::query()->create([
                'request_item_id' => $requestItem->id,
                'period_start' => $createdAt->copy()->addDay()->startOfDay(),
                'period_end' => $returnDate->copy()->endOfDay(),
                'allocated_quantity' => $line['released'],
                'released_quantity' => $line['released'],
                'restored_quantity' => 0,
                'status' => 'RELEASED',
                'allocated_at' => $createdAt,
            ]);

            /* approved_quantity and quantity_to_receive are both NOT NULL. */
            CustodyLine::query()->create([
                'custody_transaction_id' => $transaction->id,
                'request_item_id' => $requestItem->id,
                'allocation_id' => $allocation->id,
                'approved_quantity' => $line['released'],
                'quantity_to_receive' => $line['released'],
                'actual_released_quantity' => $line['released'],
                'returned_quantity' => $line['returned'] ?? 0,
            ]);
        }

        return $request->refresh();
    }
}
