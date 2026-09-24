<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\AccountStatus;
use App\Models\BorrowingRequest;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\OrganizationalUnit;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Create Request must be borrower-centered and Division-labeled, not the
 * retired "Organizational Classification" wording. Division is always
 * read-only/derived server-side from the selected, authorized Office /
 * College / Unit - never trusted from client input - and the requesting
 * unit is restricted to the units ICTU has actually authorized for the
 * authenticated borrower (primary organizational_unit_id plus any
 * additional authorizedOrganizationalUnits() assignments).
 */
class CreateRequestOrganizationalUnitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures                                                            */
    /* ------------------------------------------------------------------ */

    private function unit(string $code): OrganizationalUnit
    {
        return OrganizationalUnit::query()->where('unit_code', $code)->firstOrFail();
    }

    /** @param  list<OrganizationalUnit>  $additional */
    private function borrower(OrganizationalUnit $primary, array $additional = []): User
    {
        $borrower = User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'account_status' => AccountStatus::Active,
            'organizational_unit_id' => $primary->id,
        ]);

        foreach ($additional as $unit) {
            $borrower->authorizedOrganizationalUnits()->attach($unit->id, [
                'assignment_type' => 'ADDITIONAL',
                'assigned_at' => now(),
            ]);
        }

        return $borrower->fresh();
    }

    private function borrowerWithNoUnit(): User
    {
        return User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'account_status' => AccountStatus::Active,
            'organizational_unit_id' => null,
        ]);
    }

    private function inventoryItem(): InventoryItem
    {
        $category = InventoryCategory::query()->firstOrCreate(
            ['category_code' => 'ORGUNIT-FIX'],
            ['category_name' => 'Org Unit Fixture', 'active' => true]
        );

        $measure = UnitOfMeasure::query()->firstOrCreate(
            ['unit_code' => 'PC'],
            ['unit_name' => 'Piece', 'active' => true]
        );

        return InventoryItem::query()->create([
            'category_id' => $category->id,
            'unit_id' => $measure->id,
            'unique_description' => 'Org Unit Fixture Item '.fake()->unique()->numberBetween(1, 99999),
            'total_quantity' => 10,
            'condition_code' => 'SERVICEABLE',
            'borrowable' => true,
            'off_campus_allowed' => false,
            'laundry_required' => false,
            'provisional' => false,
            'active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(int $requestingUnitId, InventoryItem $item, array $overrides = []): array
    {
        return array_merge([
            'purpose_event' => 'Organizational unit fixture request',
            'location' => 'Campus',
            'requesting_organizational_unit_id' => $requestingUnitId,
            'schedule_date' => now()->addDay()->toDateString(),
            'return_date' => now()->addDays(2)->toDateString(),
            'intent' => 'draft',
            'item_ids' => [$item->id],
            'quantities' => [$item->id => 1],
            'locations' => [$item->id => 'ON_CAMPUS'],
        ], $overrides);
    }

    /* ------------------------------------------------------------------ */
    /* 1. Terminology                                                      */
    /* ------------------------------------------------------------------ */

    public function test_create_request_page_uses_division_terminology_not_organizational_classification(): void
    {
        $chs = $this->unit('CHS');
        $borrower = $this->borrower($chs);

        $response = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('requests.create'));

        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringContainsString('Division', $content);
        $this->assertStringNotContainsString('Organizational Classification', $content);
        $this->assertStringNotContainsString('organizational classification', $content);
    }

    /* ------------------------------------------------------------------ */
    /* 2. One assigned unit -> locked unit + derived Division              */
    /* ------------------------------------------------------------------ */

    public function test_borrower_with_one_assigned_unit_sees_a_locked_unit_and_derived_division(): void
    {
        $chs = $this->unit('CHS');
        $borrower = $this->borrower($chs);

        $response = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('requests.create'));

        $response->assertOk();
        $content = $response->getContent();

        /* Static read-only block, not a fake/editable textbox. */
        $this->assertStringContainsString(
            '<span class="request-static-field" id="requesting-unit-display">College of Health Sciences (CHS)</span>',
            $content
        );

        $this->assertStringContainsString('id="division-display"', $content);
        $this->assertStringContainsString('>Academic</span>', $content);

        /* The view data behind the page: exactly one authorized unit. */
        $options = $response->viewData('requestingUnitOptions');
        $this->assertCount(1, $options);
        $this->assertSame($chs->id, $options[0]['id']);
        $this->assertSame('ACADEMIC', $options[0]['division_code']);
        $this->assertSame('Academic', $options[0]['division_label']);
    }

    /* ------------------------------------------------------------------ */
    /* 3. Multiple assigned units -> dropdown scoped to authorized units   */
    /* ------------------------------------------------------------------ */

    public function test_borrower_with_multiple_assigned_units_sees_only_authorized_units(): void
    {
        $chs = $this->unit('CHS');
        $ictu = $this->unit('ICTU');
        $unauthorizedCea = $this->unit('CEA');

        $borrower = $this->borrower($chs, [$ictu]);

        $response = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('requests.create'));

        $response->assertOk();

        $options = $response->viewData('requestingUnitOptions');
        $this->assertCount(2, $options);
        $this->assertEqualsCanonicalizing(
            [$chs->id, $ictu->id],
            array_column($options, 'id')
        );
        $this->assertNotContains($unauthorizedCea->id, array_column($options, 'id'));

        $byId = collect($options)->keyBy('id');
        $this->assertSame('ACADEMIC', $byId[$chs->id]['division_code']);
        $this->assertSame('Academic', $byId[$chs->id]['division_label']);
        $this->assertSame('ADMINISTRATION', $byId[$ictu->id]['division_code']);
        $this->assertSame('Administrative', $byId[$ictu->id]['division_label']);

        /* CEA is not authorized, so it must never appear in the markup at all. */
        $content = $response->getContent();
        $this->assertStringNotContainsString('College of Engineering and Architecture (CEA)', $content);

        /* Multiple units render a real <select>, not the single-unit static block. */
        $this->assertStringNotContainsString('id="requesting-unit-display"', $content);
        $this->assertStringContainsString('<select', $content);
    }

    public function test_two_assigned_units_in_the_same_division_both_report_that_division(): void
    {
        $chs = $this->unit('CHS');
        $cea = $this->unit('CEA');

        $borrower = $this->borrower($chs, [$cea]);

        $response = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('requests.create'));

        $options = collect($response->viewData('requestingUnitOptions'))->keyBy('id');

        $this->assertSame('ACADEMIC', $options[$chs->id]['division_code']);
        $this->assertSame('ACADEMIC', $options[$cea->id]['division_code']);
    }

    /* ------------------------------------------------------------------ */
    /* 4. Forged / unauthorized payload protection                         */
    /* ------------------------------------------------------------------ */

    public function test_forged_unauthorized_unit_id_is_rejected_on_submit(): void
    {
        $chs = $this->unit('CHS');
        $ictu = $this->unit('ICTU');
        $unauthorizedCea = $this->unit('CEA');

        $borrower = $this->borrower($chs, [$ictu]);
        $item = $this->inventoryItem();

        $response = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->post(route('requests.store'), $this->payload($unauthorizedCea->id, $item));

        $response->assertSessionHasErrors('requesting_organizational_unit_id');
        $this->assertSame(0, BorrowingRequest::query()->where('borrower_user_id', $borrower->id)->count());
    }

    public function test_a_nonexistent_unit_id_is_also_rejected_on_submit(): void
    {
        $chs = $this->unit('CHS');
        $borrower = $this->borrower($chs);
        $item = $this->inventoryItem();

        $response = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->post(route('requests.store'), $this->payload(999999, $item));

        $response->assertSessionHasErrors('requesting_organizational_unit_id');
        $this->assertSame(0, BorrowingRequest::query()->where('borrower_user_id', $borrower->id)->count());
    }

    /* ------------------------------------------------------------------ */
    /* 5. Division/unit are stored server-derived, never client-trusted    */
    /* ------------------------------------------------------------------ */

    public function test_submitted_request_stores_the_selected_authorized_unit_and_server_derived_division(): void
    {
        $chs = $this->unit('CHS');
        $ictu = $this->unit('ICTU');
        $borrower = $this->borrower($chs, [$ictu]);
        $item = $this->inventoryItem();

        /*
         * The borrower selects the AUTHORIZED but non-primary ICTU unit, and
         * the payload also forges a mismatched division_code. The server
         * must ignore the forged value and derive Division from the
         * validated, authorized unit that was actually selected.
         */
        $response = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->post(route('requests.store'), $this->payload($ictu->id, $item, [
                'division_code' => 'ACADEMIC',
            ]));

        $response->assertSessionHasNoErrors();

        $request = BorrowingRequest::query()->where('borrower_user_id', $borrower->id)->firstOrFail();
        $version = $request->currentVersion;

        $this->assertSame($ictu->id, $request->accountable_unit_id);
        $this->assertSame('ADMINISTRATION', $version->division_code);
        $this->assertSame('Information and Communications Technology Unit (ICTU)', $version->office_unit);
    }

    /* ------------------------------------------------------------------ */
    /* 6. Historical requests are not rewritten by a later reassignment    */
    /* ------------------------------------------------------------------ */

    public function test_a_later_reassignment_does_not_change_an_already_submitted_requests_recorded_division(): void
    {
        $chs = $this->unit('CHS');
        $ictu = $this->unit('ICTU');
        $borrower = $this->borrower($chs);
        $item = $this->inventoryItem();

        $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->post(route('requests.store'), $this->payload($chs->id, $item))
            ->assertSessionHasNoErrors();

        $request = BorrowingRequest::query()->where('borrower_user_id', $borrower->id)->firstOrFail();
        $this->assertSame('ACADEMIC', $request->currentVersion->division_code);
        $this->assertSame('College of Health Sciences (CHS)', $request->currentVersion->office_unit);

        /* ICTU later reassigns the borrower's primary unit. */
        $borrower->update(['organizational_unit_id' => $ictu->id]);

        $stillHistorical = $request->fresh('currentVersion');
        $this->assertSame('ACADEMIC', $stillHistorical->currentVersion->division_code);
        $this->assertSame('College of Health Sciences (CHS)', $stillHistorical->currentVersion->office_unit);
        $this->assertSame($chs->id, $stillHistorical->accountable_unit_id);
    }

    /* ------------------------------------------------------------------ */
    /* 7. No authorized unit at all                                        */
    /* ------------------------------------------------------------------ */

    public function test_a_borrower_with_no_authorized_unit_is_blocked_with_a_clear_message_and_no_free_text_fallback(): void
    {
        $borrower = $this->borrowerWithNoUnit();

        $response = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('requests.create'));

        $response->assertOk();
        $this->assertSame([], $response->viewData('requestingUnitOptions'));
        $this->assertStringContainsString(
            'No borrowing Office / College / Unit is authorized for this account. Contact ICTU.',
            $response->viewData('requestingUnitNotice')
        );

        $content = $response->getContent();
        $this->assertStringContainsString('No authorized Office / College / Unit', $content);
        $this->assertStringNotContainsString('<input type="text" name="requesting_organizational_unit_id"', $content);

        $item = $this->inventoryItem();
        $submit = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->post(route('requests.store'), $this->payload($this->unit('CHS')->id, $item));

        $submit->assertSessionHasErrors('requesting_organizational_unit_id');
        $this->assertSame(0, BorrowingRequest::query()->where('borrower_user_id', $borrower->id)->count());
    }

    /* ------------------------------------------------------------------ */
    /* 8. Unrelated "classification" terminology is untouched              */
    /* ------------------------------------------------------------------ */

    public function test_access_classification_wording_is_unaffected_by_the_division_rename(): void
    {
        $ictu = User::factory()->create([
            'access_classification' => AccessClassification::IctuMaintainer,
            'account_status' => AccountStatus::Active,
        ]);

        $response = $this->actingAs($ictu)->get(route('administration.users.create'));

        $response->assertOk();
        $response->assertSee('Access classification');
        $response->assertSee('Division');
    }
}
