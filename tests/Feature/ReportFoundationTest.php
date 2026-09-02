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
use App\Models\OrganizationalUnit;
use App\Models\RequestItem;
use App\Models\RequestVersion;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Reports\ReportCatalogue;
use App\Reports\ReportFilters;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Stage 1 foundation: catalogue, filter validation, reporting period,
 * generated-report metadata, and the three reports migrated onto the shared
 * dataset pipeline.
 *
 * The point of the pipeline is that one query serves screen, CSV and print,
 * so the last test here compares the CSV bytes against the dataset the screen
 * renders rather than trusting that two code paths agree.
 */
class ReportFoundationTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationalUnit $unit;

    private Carbon $from;

    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unit = OrganizationalUnit::query()->create([
            'unit_code' => 'RPT',
            'unit_name' => 'Reporting Fixture Unit',
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
    /* Catalogue                                                           */
    /* ------------------------------------------------------------------ */

    public function test_catalogue_offers_the_eight_formal_report_types(): void
    {
        $this->assertSame(
            [
                'borrowing',
                'approval',
                'custody',
                'returns',
                'inventory',
                'utilization',
                'laundry',
                'gate-pass',
            ],
            ReportCatalogue::keys()
        );
    }

    public function test_report_types_are_grouped_for_the_builder(): void
    {
        $this->assertSame(
            ['Borrowing', 'Custody & Return', 'Assets', 'Special Operations'],
            array_keys(ReportCatalogue::grouped())
        );
    }

    public function test_every_consolidated_report_key_still_resolves_to_its_destination(): void
    {
        /*
         * The reports these keys named were merged into the formal eight,
         * not discarded. An old bookmark must still open the report that now
         * carries that information rather than failing.
         */
        $expected = [
            'requests' => 'borrowing',
            'review-turnaround' => 'approval',
            'overdue' => 'returns',
            'accountability' => 'returns',
            'compliance' => 'returns',
            'borrowers' => 'borrowing',
        ];

        foreach ($expected as $legacy => $destination) {
            $this->assertSame(
                $destination,
                ReportCatalogue::resolveKey($legacy),
                "Consolidated report [{$legacy}] lost its destination."
            );
        }
    }

    public function test_unknown_report_type_falls_back_to_the_default(): void
    {
        $this->assertSame('borrowing', ReportCatalogue::resolveKey('not-a-report'));
        $this->assertSame('borrowing', ReportCatalogue::resolveKey(null));
        $this->assertSame('inventory', ReportCatalogue::resolveKey('inventory'));
    }

    public function test_every_report_declares_an_empty_state_sentence(): void
    {
        foreach (ReportCatalogue::keys() as $key) {
            $this->assertNotSame(
                '',
                trim(ReportCatalogue::emptyMessage($key)),
                "Report [{$key}] has no empty-state message."
            );
        }
    }

    public function test_each_report_only_declares_filters_the_catalogue_defines(): void
    {
        $defined = array_keys(ReportCatalogue::filterDefinitions());

        foreach (ReportCatalogue::keys() as $key) {
            foreach (ReportCatalogue::definition($key)['filters'] as $filter) {
                $this->assertContains(
                    $filter,
                    $defined,
                    "Report [{$key}] declares undefined filter [{$filter}]."
                );
            }
        }
    }

    public function test_every_report_is_served_by_the_shared_dataset_pipeline(): void
    {
        /*
         * One builder per report is what keeps screen, CSV and print from
         * implementing different business rules.
         */
        foreach (ReportCatalogue::keys() as $key) {
            $this->assertTrue(
                ReportCatalogue::isMigrated($key),
                "Report [{$key}] has no builder and would fall back to ad-hoc queries."
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Filter validation                                                   */
    /* ------------------------------------------------------------------ */

    public function test_division_outside_the_organizational_structure_is_rejected(): void
    {
        $filters = $this->filters('borrowing', ['division' => 'MARKETING']);

        $this->assertNull($filters->get('division'));
        $this->assertArrayHasKey('division', $filters->rejected());
    }

    public function test_unit_from_another_division_is_rejected(): void
    {
        /* Library is an Administration unit, not an Academic one. */
        $filters = $this->filters('borrowing', [
            'division' => 'ACADEMIC',
            'unit' => 'Library',
        ]);

        $this->assertSame('ACADEMIC', $filters->get('division'));
        $this->assertNull($filters->get('unit'));
        $this->assertArrayHasKey('unit', $filters->rejected());
    }

    public function test_unit_belonging_to_the_selected_division_is_accepted(): void
    {
        $filters = $this->filters('borrowing', [
            'division' => 'ACADEMIC',
            'unit' => 'College of Computer Studies',
        ]);

        $this->assertSame('College of Computer Studies', $filters->get('unit'));
        $this->assertSame([], $filters->rejected());
    }

    public function test_filters_that_do_not_apply_to_the_report_are_ignored(): void
    {
        /* Inventory declares equipment/availability only, never division. */
        $filters = $this->filters('inventory', ['division' => 'ACADEMIC']);

        $this->assertNull($filters->get('division'));
        $this->assertSame([], $filters->rejected());
    }

    public function test_applied_filters_persist_for_description_and_links(): void
    {
        $filters = $this->filters('borrowing', [
            'division' => 'ACADEMIC',
            'unit' => 'College of Computer Studies',
            'status' => 'UNDER_SPMU',
        ]);

        $this->assertSame(
            [
                'Division' => 'Academic',
                'Office / Unit' => 'College of Computer Studies',
                'Status' => 'Under SPMU Review',
            ],
            $filters->describe()
        );

        $query = $filters->toQuery();

        $this->assertSame('borrowing', $query['report']);
        $this->assertSame('ACADEMIC', $query['division']);
        $this->assertSame('UNDER_SPMU', $query['status']);
    }

    /* ------------------------------------------------------------------ */
    /* Reporting period                                                    */
    /* ------------------------------------------------------------------ */

    public function test_reporting_period_comes_from_the_shared_service(): void
    {
        $resolved = app(\App\Services\ReportingPeriodService::class)->resolve(
            Request::create('/reports', 'GET', ['academic_period' => 'month']),
            collect(),
            null
        );

        [$from, $to, , $selection] = $resolved;

        $this->assertSame('month', $selection);
        $this->assertSame(now()->startOfMonth()->toDateString(), $from->toDateString());
        $this->assertSame(now()->endOfMonth()->toDateString(), $to->toDateString());
    }

    /* ------------------------------------------------------------------ */
    /* Metadata                                                            */
    /* ------------------------------------------------------------------ */

    public function test_generated_report_carries_its_provenance_metadata(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies', $this->from->copy()->addDays(2));

        $head = User::factory()->create([
            'access_classification' => AccessClassification::SpmuHead,
            'full_name' => 'Head Of SPMU',
        ]);

        $dataset = app(ReportService::class)->generate(
            $this->filters('borrowing', ['division' => 'ACADEMIC']),
            $head
        );

        $meta = $dataset->meta;

        $this->assertSame('Borrowing Activity Report', $meta['report_name']);
        $this->assertSame('01 Apr 2026 – 30 Apr 2026', $meta['period_label']);
        $this->assertSame(['Division' => 'Academic'], $meta['applied_filters']);
        $this->assertSame('Head Of SPMU', $meta['generated_by']);
        $this->assertSame(1, $dataset->count());
        $this->assertNotEmpty($meta['generated_at']);
    }

    /* ------------------------------------------------------------------ */
    /* Borrowing Activity                                                  */
    /* ------------------------------------------------------------------ */

    public function test_borrowing_activity_reports_the_operational_status_not_the_request_column(): void
    {
        /*
         * The request row stays APPROVED_READY_FOR_RELEASE after approval
         * while custody moves on. The report must follow custody.
         */
        $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            $this->from->copy()->addDays(3),
            RequestStatus::ApprovedReadyForRelease,
            custody: [
                'status' => 'ACTIVE',
                'released_at' => $this->from->copy()->addDays(4),
            ]
        );

        $dataset = app(ReportService::class)->generate($this->filters('borrowing'));

        $this->assertSame(1, $dataset->count());
        $this->assertSame('Released / On Custody', $dataset->rows->first()['status']);
    }

    public function test_borrowing_activity_excludes_requests_outside_the_period(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies', $this->from->copy()->addDays(2));
        $this->request('ACADEMIC', 'College of Computer Studies', $this->from->copy()->subMonth());

        $dataset = app(ReportService::class)->generate($this->filters('borrowing'));

        $this->assertSame(1, $dataset->count());
    }

    public function test_borrowing_activity_filters_by_division_unit_and_status(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies', $this->from->copy()->addDay());
        $this->request('ACADEMIC', 'Graduate School', $this->from->copy()->addDays(2));
        $this->request('ADMINISTRATION', 'Library', $this->from->copy()->addDays(3));

        $service = app(ReportService::class);

        $this->assertSame(
            2,
            $service->generate($this->filters('borrowing', ['division' => 'ACADEMIC']))->count()
        );

        $this->assertSame(
            1,
            $service->generate($this->filters('borrowing', [
                'division' => 'ACADEMIC',
                'unit' => 'Graduate School',
            ]))->count()
        );

        $this->assertSame(
            3,
            $service->generate($this->filters('borrowing', ['status' => 'UNDER_SPMU']))->count()
        );

        $this->assertSame(
            0,
            $service->generate($this->filters('borrowing', ['status' => 'COMPLETED']))->count()
        );
    }

    /* ------------------------------------------------------------------ */
    /* Inventory                                                           */
    /* ------------------------------------------------------------------ */

    public function test_inventory_status_report_lists_active_items_with_balances(): void
    {
        $this->item('Monoblock Chair', 120);
        $this->item('Round Table', 40);

        $dataset = app(ReportService::class)->generate($this->filters('inventory'));

        $this->assertSame(2, $dataset->count());

        $chair = $dataset->rows->firstWhere('item', 'Monoblock Chair');

        $this->assertSame('120', $chair['total']);
        $this->assertContains('Available', $dataset->columnLabels());
    }

    public function test_inventory_status_report_filters_to_one_equipment_item(): void
    {
        $chair = $this->item('Monoblock Chair', 120);
        $this->item('Round Table', 40);

        $dataset = app(ReportService::class)->generate(
            $this->filters('inventory', ['equipment' => (string) $chair->id])
        );

        $this->assertSame(1, $dataset->count());
        $this->assertSame('Monoblock Chair', $dataset->rows->first()['item']);
    }

    /* ------------------------------------------------------------------ */
    /* Custody                                                             */
    /* ------------------------------------------------------------------ */

    public function test_custody_report_lists_transactions_touching_the_period(): void
    {
        $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            $this->from->copy()->addDays(2),
            RequestStatus::ApprovedReadyForRelease,
            custody: [
                'status' => 'ACTIVE',
                'released_at' => $this->from->copy()->addDays(3),
            ]
        );

        /* No custody record: approval alone must not appear here. */
        $this->request('ACADEMIC', 'Graduate School', $this->from->copy()->addDays(4));

        $dataset = app(ReportService::class)->generate($this->filters('custody'));

        $this->assertSame(1, $dataset->count());
        $this->assertSame('Released / On Custody', $dataset->rows->first()['status']);
    }

    public function test_custody_report_filters_by_custody_status(): void
    {
        $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            $this->from->copy()->addDays(2),
            RequestStatus::ApprovedReadyForRelease,
            custody: [
                'status' => 'ACTIVE',
                'released_at' => $this->from->copy()->addDays(3),
            ]
        );

        $service = app(ReportService::class);

        $this->assertSame(
            1,
            $service->generate($this->filters('custody', ['custody_status' => 'ACTIVE']))->count()
        );

        $this->assertSame(
            0,
            $service->generate($this->filters('custody', ['custody_status' => 'CLOSED']))->count()
        );
    }

    /* ------------------------------------------------------------------ */
    /* Shared dataset: screen == CSV == print                              */
    /* ------------------------------------------------------------------ */

    public function test_screen_csv_and_print_render_identical_records(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies', $this->from->copy()->addDay());
        $this->request('ADMINISTRATION', 'Library', $this->from->copy()->addDays(2));

        $service = app(ReportService::class);
        $dataset = $service->generate($this->filters('borrowing'));

        /* Print renders the same dataset object the screen does. */
        $screenRecords = $dataset->records();

        $handle = fopen('php://memory', 'r+');
        $service->writeCsv($dataset, $handle);
        rewind($handle);

        $csv = [];
        while (($line = fgetcsv($handle)) !== false) {
            $csv[] = $line;
        }
        fclose($handle);

        /* Drop the metadata preamble: everything up to and including the
           column-label row belongs to provenance, not to the records. */
        $headerIndex = null;
        foreach ($csv as $index => $line) {
            if ($line === $dataset->columnLabels()) {
                $headerIndex = $index;

                break;
            }
        }

        $this->assertNotNull($headerIndex, 'CSV did not contain the column header row.');

        $csvRecords = array_values(array_slice($csv, $headerIndex + 1));

        $this->assertSame($screenRecords, $csvRecords);
        $this->assertCount($dataset->count(), $csvRecords);
    }

    /* ------------------------------------------------------------------ */
    /* Fixture                                                             */
    /* ------------------------------------------------------------------ */

    /** @param array<string, string> $input */
    private function filters(string $report, array $input = []): ReportFilters
    {
        return ReportFilters::fromRequest(
            Request::create('/reports', 'GET', $input),
            $report,
            $this->from,
            $this->to,
            'month'
        );
    }

    private function borrower(): User
    {
        return User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
        ]);
    }

    private function item(string $description, int $total = 100): InventoryItem
    {
        $category = InventoryCategory::query()->firstOrCreate(
            ['category_code' => 'RPT'],
            ['category_name' => 'Reporting Fixture', 'active' => true]
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
            'laundry_required' => false,
            'provisional' => false,
            'active' => true,
        ]);
    }

    /**
     * One filed request, optionally carried into custody.
     *
     * @param  array<int, array{item: InventoryItem, released: int}>  $lines
     */
    private function request(
        string $division,
        string $unit,
        Carbon $createdAt,
        RequestStatus $status = RequestStatus::UnderSpmu,
        array $lines = [],
        ?array $custody = null
    ): BorrowingRequest {
        $borrower = $this->borrower();

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-'.$createdAt->format('YmdHis').'-'.fake()->unique()->numberBetween(1000, 99999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $this->unit->id,
            'current_version_no' => 1,
            'status' => $status,
        ]);

        $request->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        $version = RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Reporting fixture activity',
            'location' => 'Campus',
            'division_code' => $division,
            'office_unit' => $unit,
            'schedule_date' => $createdAt->copy()->addDay()->toDateString(),
            'return_date' => $createdAt->copy()->addDays(3)->toDateString(),
            'needed_from' => $createdAt->copy()->addDay()->startOfDay(),
            'return_due_at' => $createdAt->copy()->addDays(3)->endOfDay(),
        ]);

        if ($custody === null) {
            return $request;
        }

        $transaction = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-'.$createdAt->format('Ymd').'-'.fake()->unique()->numberBetween(1000, 99999),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => $custody['status'],
            'due_at' => $custody['due_at'] ?? $createdAt->copy()->addDays(3)->endOfDay(),
            'released_at' => $custody['released_at'] ?? null,
            'closed_at' => $custody['closed_at'] ?? null,
        ]);

        $transaction->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
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
                'period_start' => $createdAt->copy()->addDay()->startOfDay(),
                'period_end' => $createdAt->copy()->addDays(3)->endOfDay(),
                'allocated_quantity' => $line['released'],
                'released_quantity' => $line['released'],
                'restored_quantity' => 0,
                'status' => 'RELEASED',
                'allocated_at' => $createdAt,
            ]);

            CustodyLine::query()->create([
                'custody_transaction_id' => $transaction->id,
                'request_item_id' => $requestItem->id,
                'allocation_id' => $allocation->id,
                'actual_released_quantity' => $line['released'],
                'returned_quantity' => 0,
            ]);
        }

        return $request;
    }
}
