<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\LaundryJob;
use App\Models\OrganizationalUnit;
use App\Models\RequestItem;
use App\Models\RequestVersion;
use App\Models\ReturnLine;
use App\Models\ReturnTransaction;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Reports\ReportDataset;
use App\Reports\ReportFilters;
use App\Services\InventoryService;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Stage 4: Inventory Status and Equipment Utilization.
 *
 * Two rules carry the weight here. Inventory state must be the authoritative
 * InventoryService balance rather than a second opinion computed inside
 * Reports, and utilization must be quantity that physically left the store,
 * never quantity that was merely requested or approved.
 */
class ReportInventoryUtilizationTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationalUnit $unit;

    private Carbon $from;

    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unit = OrganizationalUnit::query()->create([
            'unit_code' => 'INVU',
            'unit_name' => 'Inventory Fixture Unit',
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
    /* Inventory Status                                                    */
    /* ------------------------------------------------------------------ */

    public function test_inventory_report_matches_the_authoritative_availability_service(): void
    {
        $chair = $this->item('Monoblock Chair', 200);
        $this->item('Round Table', 50);

        $this->release([['item' => $chair, 'released' => 30, 'returned' => 0]]);

        $dataset = $this->inventory();
        $row = $dataset->rows->firstWhere('item', 'Monoblock Chair');

        $balance = app(InventoryService::class)->availability(
            $chair->fresh(),
            now()->subYears(10)->startOfDay(),
            now()->addYears(10)->endOfDay()
        );

        $this->assertSame((string) (int) $balance['total'], $row['total']);
        $this->assertSame((string) (int) $balance['current_available'], $row['available']);
        $this->assertSame((string) (int) $balance['borrowed'], $row['borrowed']);
    }

    public function test_linen_awaiting_laundry_is_not_reported_as_available(): void
    {
        /*
         * Returned linen is not available stock until laundry processing has
         * completed. The report must reflect that, not treat the physical
         * return as restoring the quantity.
         */
        $linen = $this->item('Rectangular Table Cloth', 100, laundryRequired: true);

        $custody = $this->release(
            [['item' => $linen, 'released' => 40, 'returned' => 40]],
            custodyStatus: 'RETURN_PROCESSING'
        );

        /*
         * Laundry stock is recorded on the return line's disposition, which
         * is what InventoryService reads. The job row alone does not move
         * quantity, so the physical receipt is created here too.
         */
        $receiver = User::factory()->create([
            'access_classification' => AccessClassification::SpmuOfficer,
        ]);

        $returnTransaction = ReturnTransaction::query()->create([
            'return_no' => 'RET-'.fake()->unique()->numberBetween(10000, 99999),
            'custody_transaction_id' => $custody->id,
            'received_by_user_id' => $receiver->id,
            'return_type' => 'NORMAL',
            'received_at' => $this->from->copy()->addDays(6),
        ]);

        ReturnLine::query()->create([
            'return_transaction_id' => $returnTransaction->id,
            'custody_line_id' => $custody->lines->first()->id,
            'quantity_received' => 40,
            'condition_code' => 'SERVICEABLE',
            'disposition_state' => 'LAUNDRY',
        ]);

        LaundryJob::query()->create([
            'custody_transaction_id' => $custody->id,
            'status' => 'FOR_LAUNDRY',
        ]);

        $row = $this->inventory()->rows->firstWhere('item', 'Rectangular Table Cloth');

        $this->assertSame('40', $row['laundry']);
        $this->assertSame('60', $row['available']);
    }

    public function test_inventory_report_query_count_does_not_grow_with_the_catalogue(): void
    {
        /*
         * The report used to call availability() once per item. Query volume
         * is asserted rather than measured by eye so the N+1 cannot return.
         */
        foreach (range(1, 3) as $index) {
            $this->item("Small Catalogue Item {$index}", 10);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->inventory();
        $small = count(DB::getQueryLog());

        foreach (range(4, 12) as $index) {
            $this->item("Small Catalogue Item {$index}", 10);
        }

        DB::flushQueryLog();
        $this->inventory();
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $small,
            $large,
            "Query count grew from {$small} to {$large} as the catalogue grew."
        );
    }

    public function test_inventory_report_filters_by_availability_state(): void
    {
        $available = $this->item('Monoblock Chair', 200);
        $this->item('Round Table', 40);

        $this->release([['item' => $available, 'released' => 200, 'returned' => 0]]);

        $this->assertSame(1, $this->inventory(['availability_status' => 'FULLY_COMMITTED'])->count());
        $this->assertSame(1, $this->inventory(['availability_status' => 'ON_CUSTODY'])->count());
        $this->assertSame(1, $this->inventory(['availability_status' => 'AVAILABLE'])->count());
    }

    /* ------------------------------------------------------------------ */
    /* Equipment Utilization                                               */
    /* ------------------------------------------------------------------ */

    public function test_utilization_counts_only_physically_released_quantity(): void
    {
        $chair = $this->item('Monoblock Chair', 500);

        /* Approved but never released: no custody transaction at all. */
        $this->approvedOnly($chair, 90);

        /* Physically released. */
        $this->release([['item' => $chair, 'released' => 60, 'returned' => 0]]);

        $row = $this->utilization()->rows->firstWhere('item', 'Monoblock Chair');

        $this->assertSame('60', $row['released_quantity']);
        $this->assertSame('1', $row['transactions']);
    }

    public function test_custody_without_a_release_timestamp_contributes_no_utilization(): void
    {
        $chair = $this->item('Monoblock Chair', 500);

        $this->release(
            [['item' => $chair, 'released' => 50, 'returned' => 0]],
            custodyStatus: 'PREPARING_RELEASE',
            releasedAt: null
        );

        $row = $this->utilization()->rows->firstWhere('item', 'Monoblock Chair');

        $this->assertSame('0', $row['released_quantity']);
        $this->assertSame('No utilization', $row['utilization_state']);
    }

    public function test_utilization_aggregates_quantity_across_transactions(): void
    {
        $chair = $this->item('Monoblock Chair', 500);

        $this->release([['item' => $chair, 'released' => 40, 'returned' => 0]]);
        $this->release([['item' => $chair, 'released' => 25, 'returned' => 0]]);

        $row = $this->utilization()->rows->firstWhere('item', 'Monoblock Chair');

        $this->assertSame('65', $row['released_quantity']);
        $this->assertSame('2', $row['transactions']);
    }

    public function test_utilization_is_broken_down_by_the_three_canonical_divisions(): void
    {
        $chair = $this->item('Monoblock Chair', 500);

        $this->release([['item' => $chair, 'released' => 10, 'returned' => 0]], division: 'ACADEMIC');
        $this->release([['item' => $chair, 'released' => 20, 'returned' => 0]], division: 'ADMINISTRATION');
        $this->release(
            [['item' => $chair, 'released' => 30, 'returned' => 0]],
            division: 'RESEARCH_INNOVATION_COLLABORATION'
        );

        $dataset = $this->utilization();
        $row = $dataset->rows->firstWhere('item', 'Monoblock Chair');

        $this->assertSame('10', $row['division_academic']);
        $this->assertSame('20', $row['division_administration']);
        $this->assertSame('30', $row['division_research_innovation_collaboration']);
        $this->assertSame('60', $row['released_quantity']);

        /* Research is reported on its own, never folded into another column. */
        $this->assertContains('Research & Innovation', $dataset->columnLabels());
    }

    public function test_utilization_ranks_by_released_quantity(): void
    {
        $chair = $this->item('Monoblock Chair', 500);
        $table = $this->item('Round Table', 500);

        $this->release([['item' => $table, 'released' => 80, 'returned' => 0]]);
        $this->release([['item' => $chair, 'released' => 20, 'returned' => 0]]);

        $rows = $this->utilization()->rows;

        $this->assertSame('1', $rows->first()['rank']);
        $this->assertSame('Round Table', $rows->first()['item']);
        $this->assertSame('2', $rows->get(1)['rank']);
    }

    public function test_utilization_excludes_releases_outside_the_period(): void
    {
        $chair = $this->item('Monoblock Chair', 500);

        $this->release(
            [['item' => $chair, 'released' => 40, 'returned' => 0]],
            releasedAt: $this->from->copy()->subMonth()
        );

        $this->assertSame('0', $this->utilization()->rows->firstWhere('item', 'Monoblock Chair')['released_quantity']);
    }

    public function test_utilization_filters_by_division_and_equipment(): void
    {
        $chair = $this->item('Monoblock Chair', 500);
        $table = $this->item('Round Table', 500);

        $this->release([['item' => $chair, 'released' => 10, 'returned' => 0]], division: 'ACADEMIC');
        $this->release([['item' => $table, 'released' => 20, 'returned' => 0]], division: 'ADMINISTRATION');

        $this->assertSame(
            '10',
            $this->utilization(['division' => 'ACADEMIC'])
                ->rows->firstWhere('item', 'Monoblock Chair')['released_quantity']
        );

        $this->assertSame(
            '0',
            $this->utilization(['division' => 'ACADEMIC'])
                ->rows->firstWhere('item', 'Round Table')['released_quantity']
        );

        $equipmentOnly = $this->utilization(['equipment' => (string) $table->id]);

        $this->assertSame(1, $equipmentOnly->count());
        $this->assertSame('Round Table', $equipmentOnly->rows->first()['item']);
    }

    public function test_screen_and_csv_records_match_for_both_reports(): void
    {
        $chair = $this->item('Monoblock Chair', 500);
        $this->release([['item' => $chair, 'released' => 15, 'returned' => 0]]);

        foreach ([$this->inventory(), $this->utilization()] as $dataset) {
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

    private function inventory(array $input = []): ReportDataset
    {
        return $this->generate('inventory', $input);
    }

    private function utilization(array $input = []): ReportDataset
    {
        return $this->generate('utilization', $input);
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
            ['category_code' => 'INVU'],
            ['category_name' => 'Inventory Fixture', 'active' => true]
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
            'off_campus_allowed' => false,
            'laundry_required' => $laundryRequired,
            'provisional' => false,
            'active' => true,
        ]);
    }

    /** A request approved at the record level but never carried into custody. */
    private function approvedOnly(InventoryItem $item, int $quantity): void
    {
        [$version] = $this->requestAndVersion('ACADEMIC', 'College of Computer Studies', RequestStatus::ApprovedReadyForRelease);

        RequestItem::query()->create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => 'Piece',
            'requested_quantity' => $quantity,
            'approved_quantity' => $quantity,
        ]);
    }

    /**
     * @param  array<int, array{item: InventoryItem, released: int, returned: int}>  $lines
     */
    private function release(
        array $lines,
        string $division = 'ACADEMIC',
        string $custodyStatus = 'ACTIVE',
        ?Carbon $releasedAt = null,
    ): CustodyTransaction {
        $releasedAt = func_num_args() >= 4 ? $releasedAt : $this->from->copy()->addDays(3);

        [$request, $version, $borrower] = $this->requestAndVersion(
            $division,
            $division === 'ACADEMIC' ? 'College of Computer Studies' : 'Library',
            RequestStatus::ApprovedReadyForRelease
        );

        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-'.fake()->unique()->numberBetween(100000, 999999),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => $custodyStatus,
            'due_at' => $this->from->copy()->addDays(12)->endOfDay(),
            'released_at' => $releasedAt,
        ]);

        $custody->forceFill([
            'created_at' => $this->from->copy()->addDay(),
            'updated_at' => $this->from->copy()->addDay(),
        ])->save();

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
                'period_start' => $this->from->copy()->addDays(2)->startOfDay(),
                'period_end' => $this->from->copy()->addDays(12)->endOfDay(),
                'allocated_quantity' => $line['released'],
                'released_quantity' => $line['released'],
                'restored_quantity' => 0,
                'status' => 'RELEASED',
                'allocated_at' => $this->from->copy()->addDay(),
            ]);

            CustodyLine::query()->create([
                'custody_transaction_id' => $custody->id,
                'request_item_id' => $requestItem->id,
                'allocation_id' => $allocation->id,
                'approved_quantity' => $line['released'],
                'quantity_to_receive' => $line['released'],
                'actual_released_quantity' => $line['released'],
                'returned_quantity' => $line['returned'] ?? 0,
            ]);
        }

        return $custody->refresh();
    }

    /** @return array{0: BorrowingRequest, 1: RequestVersion, 2: User} */
    private function requestAndVersion(string $division, string $unit, RequestStatus $status): array
    {
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
            'status' => $status,
        ]);

        $request->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        $version = RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Utilization fixture activity',
            'location' => 'Campus',
            'division_code' => $division,
            'office_unit' => $unit,
            'schedule_date' => $createdAt->copy()->addDay()->toDateString(),
            'return_date' => $createdAt->copy()->addDays(11)->toDateString(),
            'needed_from' => $createdAt->copy()->addDay()->startOfDay(),
            'return_due_at' => $createdAt->copy()->addDays(11)->endOfDay(),
        ]);

        return [$request, $version, $borrower];
    }
}
