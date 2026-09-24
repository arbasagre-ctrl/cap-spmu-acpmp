<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\BorrowerRestriction;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\GeneratedDocument;
use App\Models\Incident;
use App\Models\IncidentLine;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\OrganizationalUnit;
use App\Models\RequestItem;
use App\Models\RequestVersion;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for the RESTRICTION_NOTICE document (restrictions.notice route,
 * AccountabilityController::restrictionNotice(), DocumentService::
 * restrictionNotice()) - previously entirely untested despite being a real,
 * borrower- and SPMU-accessible generated document.
 */
class RestrictionNoticeDocumentTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationalUnit $unit;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        Carbon::setTestNow(Carbon::create(2026, 9, 21, 9));
        $this->now = Carbon::create(2026, 9, 21, 9);

        $this->unit = OrganizationalUnit::query()->create([
            'unit_code' => 'RESTNOTFX',
            'unit_name' => 'Restriction Notice Test Unit',
            'unit_type' => 'OFFICE',
            'active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_borrower_can_generate_and_view_their_own_restriction_notice(): void
    {
        [$restriction, $borrower] = $this->propertyRestriction('BORROWER-VIEW');

        $response = $this->actingAs($borrower)
            ->withSession(['active_workspace' => 'BORROWER'])
            ->get(route('restrictions.notice', $restriction));

        $document = GeneratedDocument::query()
            ->where('subject_type', BorrowerRestriction::class)
            ->where('subject_id', $restriction->id)
            ->where('document_type', 'RESTRICTION_NOTICE')
            ->where('status', 'FINAL')
            ->firstOrFail();

        $response->assertRedirect(route('documents.preview', $document));
    }

    public function test_spmu_can_generate_and_view_a_borrowers_restriction_notice(): void
    {
        [$restriction] = $this->propertyRestriction('SPMU-VIEW');
        $spmu = User::where('access_classification', AccessClassification::SpmuHead->value)->firstOrFail();

        $response = $this->actingAs($spmu)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('restrictions.notice', $restriction));

        $document = GeneratedDocument::query()
            ->where('subject_type', BorrowerRestriction::class)
            ->where('subject_id', $restriction->id)
            ->where('document_type', 'RESTRICTION_NOTICE')
            ->firstOrFail();

        $response->assertRedirect(route('documents.preview', $document));
    }

    public function test_another_borrower_cannot_access_someone_elses_restriction_notice(): void
    {
        [$restriction] = $this->propertyRestriction('OTHER-BORROWER');

        $otherBorrower = User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
        ]);

        $this->actingAs($otherBorrower)
            ->withSession(['active_workspace' => 'BORROWER'])
            ->get(route('restrictions.notice', $restriction))
            ->assertForbidden();

        $this->assertDatabaseMissing('generated_documents', [
            'subject_type' => BorrowerRestriction::class,
            'subject_id' => $restriction->id,
        ]);
    }

    public function test_restriction_notice_is_unavailable_for_a_restriction_without_a_linked_incident(): void
    {
        $borrower = User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
        ]);

        $restriction = BorrowerRestriction::query()->create([
            'borrower_user_id' => $borrower->id,
            'custody_transaction_id' => null,
            'incident_id' => null,
            'restriction_type' => 'SANCTION_SUSPENSION',
            'reason' => 'Borrowing suspension in effect.',
            'status' => 'ACTIVE',
            'effective_from' => $this->now,
        ]);

        $this->actingAs($borrower)
            ->withSession(['active_workspace' => 'BORROWER'])
            ->get(route('restrictions.notice', $restriction))
            ->assertNotFound();
    }

    public function test_repeated_access_reuses_the_existing_final_document_instead_of_regenerating(): void
    {
        [$restriction, $borrower] = $this->propertyRestriction('REUSE');

        $this->actingAs($borrower)
            ->withSession(['active_workspace' => 'BORROWER'])
            ->get(route('restrictions.notice', $restriction))
            ->assertRedirect();

        $this->actingAs($borrower)
            ->withSession(['active_workspace' => 'BORROWER'])
            ->get(route('restrictions.notice', $restriction))
            ->assertRedirect();

        $this->assertSame(
            1,
            GeneratedDocument::query()
                ->where('subject_type', BorrowerRestriction::class)
                ->where('subject_id', $restriction->id)
                ->where('document_type', 'RESTRICTION_NOTICE')
                ->count()
        );
    }

    /**
     * @return array{0:BorrowerRestriction,1:User}
     */
    private function propertyRestriction(string $suffix): array
    {
        $category = InventoryCategory::query()->firstOrCreate(
            ['category_code' => 'RESTNOTFX'],
            ['category_name' => 'Restriction Notice Fixture', 'active' => true]
        );
        $measure = UnitOfMeasure::query()->firstOrCreate(
            ['unit_code' => 'PC'],
            ['unit_name' => 'Piece', 'active' => true]
        );
        $item = InventoryItem::query()->firstOrCreate(
            ['unique_description' => 'Restriction Notice Fixture Item '.$suffix],
            [
                'category_id' => $category->id,
                'unit_id' => $measure->id,
                'total_quantity' => 10,
                'condition_code' => 'SERVICEABLE',
                'borrowable' => true,
                'off_campus_allowed' => false,
                'laundry_required' => false,
                'provisional' => false,
                'active' => true,
            ]
        );

        $borrower = User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
        ]);
        $officer = User::where('access_classification', AccessClassification::SpmuOfficer->value)->firstOrFail();

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-RESTNOT-'.$suffix,
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $this->unit->id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
        ]);

        $version = RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Restriction notice fixture',
            'location' => 'Campus',
            'division_code' => 'ACADEMIC',
            'office_unit' => 'Test Office',
            'schedule_date' => $this->now->copy()->addDay()->toDateString(),
            'return_date' => $this->now->copy()->addDays(5)->toDateString(),
            'needed_from' => $this->now->copy()->addDay(),
            'return_due_at' => $this->now->copy()->addDays(5)->endOfDay(),
        ]);

        $requestItem = RequestItem::query()->create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => 'Piece',
            'requested_quantity' => 1,
            'approved_quantity' => 1,
        ]);
        $allocation = Allocation::query()->create([
            'request_item_id' => $requestItem->id,
            'period_start' => $this->now->copy()->addDay(),
            'period_end' => $this->now->copy()->addDays(5),
            'allocated_quantity' => 1,
            'released_quantity' => 1,
            'restored_quantity' => 0,
            'status' => 'RELEASED',
            'allocated_at' => $this->now->copy()->addDay(),
        ]);
        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-RESTNOT-'.$suffix,
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => 'INCIDENT_OPEN',
            'released_at' => $this->now->copy()->addDay(),
            'due_at' => $this->now->copy()->addDays(5)->endOfDay(),
        ]);
        $line = CustodyLine::query()->create([
            'custody_transaction_id' => $custody->id,
            'request_item_id' => $requestItem->id,
            'allocation_id' => $allocation->id,
            'approved_quantity' => 1,
            'quantity_to_receive' => 1,
            'actual_released_quantity' => 1,
            'returned_quantity' => 0,
        ]);

        $incident = Incident::query()->create([
            'incident_no' => 'INC-RESTNOT-'.$suffix,
            'custody_transaction_id' => $custody->id,
            'borrower_user_id' => $borrower->id,
            'reported_by_user_id' => $officer->id,
            'incident_type' => 'DAMAGED',
            'reported_at' => $this->now,
            'status' => 'OPEN',
        ]);

        IncidentLine::query()->create([
            'incident_id' => $incident->id,
            'custody_line_id' => $line->id,
            'quantity' => 1,
            'observed_condition' => 'DAMAGED',
            'disposition_state' => 'DAMAGED_MAINTENANCE',
        ]);

        $restriction = BorrowerRestriction::query()->create([
            'borrower_user_id' => $borrower->id,
            'custody_transaction_id' => $custody->id,
            'incident_id' => $incident->id,
            'restriction_type' => 'UNRESOLVED_INCIDENT',
            'reason' => 'Unresolved DAMAGED incident.',
            'status' => 'ACTIVE',
            'effective_from' => $this->now,
            'imposed_by_user_id' => $officer->id,
        ]);

        return [$restriction->fresh(), $borrower];
    }
}
