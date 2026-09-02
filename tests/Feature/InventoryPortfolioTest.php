<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\Incident;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\OrganizationalUnit;
use App\Models\RequestItem;
use App\Models\RequestVersion;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * portfolio() exists only to answer the same question as availability() with a
 * fixed number of queries. If the two ever disagree, the optimisation has
 * changed the meaning of inventory, so every field is compared item by item
 * against a fixture that exercises each unavailable state.
 */
class InventoryPortfolioTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $inventory;

    private OrganizationalUnit $unit;

    private Carbon $from;

    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventory = app(InventoryService::class);

        $this->unit = OrganizationalUnit::query()->create([
            'unit_code' => 'PORTFOLIO',
            'unit_name' => 'Portfolio Fixture Unit',
            'unit_type' => 'OFFICE',
            'active' => true,
        ]);

        $this->from = Carbon::create(2026, 4, 1)->startOfDay();
        $this->to = Carbon::create(2026, 4, 30)->endOfDay();

        Carbon::setTestNow(Carbon::create(2026, 4, 15, 9));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function item(
        string $description,
        int $total = 100,
        string $condition = 'SERVICEABLE',
        bool $active = true,
        bool $laundry = false
    ): InventoryItem {
        $category = InventoryCategory::query()->firstOrCreate(
            ['category_code' => 'PORTFOLIO'],
            ['category_name' => 'Portfolio Category', 'active' => true]
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
            'condition_code' => $condition,
            'borrowable' => true,
            'off_campus_allowed' => false,
            'laundry_required' => $laundry,
            'provisional' => false,
            'active' => $active,
        ]);
    }

    private function borrower(): User
    {
        return User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
        ]);
    }

    /** A request carrying one line for the given item. */
    private function requestItemFor(InventoryItem $item, int $quantity): RequestItem
    {
        $borrower = $this->borrower();
        $createdAt = Carbon::create(2026, 3, 20, 10);

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-PF-'.fake()->unique()->numberBetween(1000, 999999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $this->unit->id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
        ]);

        $request->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        $version = RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Portfolio fixture',
            'location' => 'Campus',
            'division_code' => 'ACADEMIC',
            'office_unit' => 'College of Computer Studies',
            'schedule_date' => '2026-04-05',
            'return_date' => '2026-04-08',
            'needed_from' => Carbon::create(2026, 4, 5)->startOfDay(),
            'return_due_at' => Carbon::create(2026, 4, 8)->endOfDay(),
        ]);

        return RequestItem::query()->create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => 'Piece',
            'requested_quantity' => $quantity,
            'approved_quantity' => $quantity,
        ]);
    }

    /**
     * Five items, each in a different unavailable state, so every branch of the
     * balance is exercised at once.
     *
     * @return \Illuminate\Support\Collection<int, InventoryItem>
     */
    private function seedEveryUnavailableState(): \Illuminate\Support\Collection
    {
        /* 1. Untouched. */
        $clean = $this->item('Clean Item', 50);

        /* 2. Reserved for a period overlapping the window. */
        $reserved = $this->item('Reserved Item', 40);
        Allocation::query()->create([
            'request_item_id' => $this->requestItemFor($reserved, 10)->id,
            'period_start' => Carbon::create(2026, 4, 5)->startOfDay(),
            'period_end' => Carbon::create(2026, 4, 8)->endOfDay(),
            'allocated_quantity' => 10,
            'released_quantity' => 0,
            'restored_quantity' => 0,
            'status' => 'ACTIVE',
            'allocated_at' => Carbon::create(2026, 3, 25),
        ]);

        /* 3. Physically out on custody. */
        $borrowed = $this->item('Borrowed Item', 30);
        $borrowedRequestItem = $this->requestItemFor($borrowed, 12);
        $borrowedAllocation = Allocation::query()->create([
            'request_item_id' => $borrowedRequestItem->id,
            'period_start' => Carbon::create(2026, 4, 5)->startOfDay(),
            'period_end' => Carbon::create(2026, 4, 8)->endOfDay(),
            'allocated_quantity' => 12,
            'released_quantity' => 12,
            'restored_quantity' => 0,
            'status' => 'RELEASED',
            'allocated_at' => Carbon::create(2026, 3, 25),
        ]);
        $borrowedCustody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-PF-BORROWED',
            'request_id' => $borrowedRequestItem->version->request_id,
            'request_version_id' => $borrowedRequestItem->request_version_id,
            'borrower_user_id' => $this->borrower()->id,
            'status' => 'ACTIVE',
            'due_at' => Carbon::create(2026, 4, 8)->endOfDay(),
            'released_at' => Carbon::create(2026, 4, 5),
        ]);
        CustodyLine::query()->create([
            'custody_transaction_id' => $borrowedCustody->id,
            'request_item_id' => $borrowedRequestItem->id,
            'allocation_id' => $borrowedAllocation->id,
            'approved_quantity' => 12,
            'quantity_to_receive' => 12,
            'actual_released_quantity' => 12,
            'returned_quantity' => 0,
        ]);

        /* 4. Returned linen still with Laundry Operations. */
        $linen = $this->item('Linen Item', 20, laundry: true);
        $linenRequestItem = $this->requestItemFor($linen, 8);
        $linenAllocation = Allocation::query()->create([
            'request_item_id' => $linenRequestItem->id,
            'period_start' => Carbon::create(2026, 3, 21)->startOfDay(),
            'period_end' => Carbon::create(2026, 3, 24)->endOfDay(),
            'allocated_quantity' => 8,
            'released_quantity' => 8,
            'restored_quantity' => 0,
            'status' => 'RELEASED',
            'allocated_at' => Carbon::create(2026, 3, 21),
        ]);
        $linenCustody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-PF-LINEN',
            'request_id' => $linenRequestItem->version->request_id,
            'request_version_id' => $linenRequestItem->request_version_id,
            'borrower_user_id' => $this->borrower()->id,
            'status' => 'CLOSED',
            'due_at' => Carbon::create(2026, 3, 24)->endOfDay(),
            'released_at' => Carbon::create(2026, 3, 21),
            'closed_at' => Carbon::create(2026, 3, 24),
        ]);
        $linenLine = CustodyLine::query()->create([
            'custody_transaction_id' => $linenCustody->id,
            'request_item_id' => $linenRequestItem->id,
            'allocation_id' => $linenAllocation->id,
            'approved_quantity' => 8,
            'quantity_to_receive' => 8,
            'actual_released_quantity' => 8,
            'returned_quantity' => 8,
        ]);
        $returnTransactionId = DB::table('return_transactions')->insertGetId([
            'return_no' => 'RT-PF-LINEN',
            'custody_transaction_id' => $linenCustody->id,
            'received_by_user_id' => $this->borrower()->id,
            'return_type' => 'NORMAL',
            'received_at' => Carbon::create(2026, 3, 24),
            'status' => 'INSPECTED',
            'created_at' => Carbon::create(2026, 3, 24),
            'updated_at' => Carbon::create(2026, 3, 24),
        ]);
        DB::table('return_lines')->insert([
            'return_transaction_id' => $returnTransactionId,
            'custody_line_id' => $linenLine->id,
            'quantity_received' => 8,
            'condition_code' => 'SERVICEABLE',
            'disposition_state' => 'LAUNDRY',
            'created_at' => Carbon::create(2026, 3, 24),
            'updated_at' => Carbon::create(2026, 3, 24),
        ]);

        /* 5. Units held by an incident. */
        $damaged = $this->item('Damaged Item', 25);
        $damagedRequestItem = $this->requestItemFor($damaged, 5);
        $damagedAllocation = Allocation::query()->create([
            'request_item_id' => $damagedRequestItem->id,
            'period_start' => Carbon::create(2026, 3, 21)->startOfDay(),
            'period_end' => Carbon::create(2026, 3, 24)->endOfDay(),
            'allocated_quantity' => 5,
            'released_quantity' => 5,
            'restored_quantity' => 0,
            'status' => 'RELEASED',
            'allocated_at' => Carbon::create(2026, 3, 21),
        ]);
        $damagedCustody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-PF-DAMAGED',
            'request_id' => $damagedRequestItem->version->request_id,
            'request_version_id' => $damagedRequestItem->request_version_id,
            'borrower_user_id' => $this->borrower()->id,
            'status' => 'INCIDENT_OPEN',
            'due_at' => Carbon::create(2026, 3, 24)->endOfDay(),
            'released_at' => Carbon::create(2026, 3, 21),
        ]);
        $damagedLine = CustodyLine::query()->create([
            'custody_transaction_id' => $damagedCustody->id,
            'request_item_id' => $damagedRequestItem->id,
            'allocation_id' => $damagedAllocation->id,
            'approved_quantity' => 5,
            'quantity_to_receive' => 5,
            'actual_released_quantity' => 5,
            'returned_quantity' => 5,
        ]);
        $incident = Incident::query()->create([
            'incident_no' => 'INC-PF-1',
            'custody_transaction_id' => $damagedCustody->id,
            'borrower_user_id' => $damagedCustody->borrower_user_id,
            'reported_by_user_id' => $this->borrower()->id,
            'incident_type' => 'DAMAGE',
            'status' => 'OPEN',
            'reported_at' => Carbon::create(2026, 3, 25),
        ]);
        DB::table('incident_lines')->insert([
            'incident_id' => $incident->id,
            'custody_line_id' => $damagedLine->id,
            'quantity' => 4,
            'observed_condition' => 'LOST',
            'disposition_state' => 'LOST',
            'created_at' => Carbon::create(2026, 3, 25),
            'updated_at' => Carbon::create(2026, 3, 25),
        ]);

        /* 6. Not serviceable and not active, so nothing is usable. */
        $condemned = $this->item('Condemned Item', 15, condition: 'CONDEMNED');
        $inactive = $this->item('Inactive Item', 15, active: false);

        return collect([
            $clean, $reserved, $borrowed, $linen, $damaged, $condemned, $inactive,
        ]);
    }

    public function test_portfolio_matches_availability_for_every_item_and_field(): void
    {
        $items = $this->seedEveryUnavailableState();

        $batched = $this->inventory->portfolio($items, $this->from, $this->to);

        foreach ($items as $item) {
            $single = $this->inventory->availability($item, $this->from, $this->to);
            $batch = $batched[$item->id];

            foreach ([
                'total', 'allocated', 'reserved', 'borrowed', 'borrowed_for_period',
                'laundry', 'incident', 'damaged_maintenance', 'lost', 'stolen',
                'destroyed', 'condemned', 'current_available', 'borrower_available',
                'available',
            ] as $field) {
                $this->assertEqualsWithDelta(
                    (float) $single[$field],
                    (float) $batch[$field],
                    0.0001,
                    "{$item->unique_description}: {$field} disagrees between availability() and portfolio()."
                );
            }
        }
    }

    public function test_portfolio_returns_nothing_for_an_empty_collection(): void
    {
        $this->assertSame([], $this->inventory->portfolio(collect(), $this->from, $this->to));
    }

    public function test_portfolio_query_count_does_not_grow_with_the_catalogue(): void
    {
        $this->seedEveryUnavailableState();

        $items = InventoryItem::query()->get();
        $this->assertGreaterThan(5, $items->count());

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->inventory->portfolio($items, $this->from, $this->to);
        $batched = count(DB::getQueryLog());

        DB::flushQueryLog();
        foreach ($items as $item) {
            $this->inventory->availability($item, $this->from, $this->to);
        }
        $perItem = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            $perItem,
            $batched,
            'The batched balance must issue fewer queries than one call per item.'
        );

        $this->assertLessThanOrEqual(
            10,
            $batched,
            'The batched balance must stay a fixed handful of queries.'
        );
    }

    public function test_linen_awaiting_laundry_is_excluded_from_usable_stock(): void
    {
        $items = $this->seedEveryUnavailableState();
        $linen = $items->firstWhere('unique_description', 'Linen Item');

        $balance = $this->inventory->portfolio($items, $this->from, $this->to)[$linen->id];

        $this->assertSame(8.0, $balance['laundry']);
        $this->assertSame(
            12.0,
            $balance['current_available'],
            '20 in stock minus 8 still with Laundry Operations.'
        );
    }
}
