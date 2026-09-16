<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use App\Models\OrganizationalUnit;
use App\Models\RequestVersion;
use App\Models\User;
use App\Reports\ReportFilters;
use App\Services\AnalyticsService;
use App\Support\OrganizationalStructure;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class OrganizationalStructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.seed_demo_users', true);
        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_three_official_classifications_use_stable_codes_and_display_labels(): void
    {
        $expected = [
            OrganizationalUnit::DIVISION_ADMINISTRATION => 'Administrative',
            OrganizationalUnit::DIVISION_ACADEMIC => 'Academic',
            OrganizationalUnit::DIVISION_RIC => 'Research, Innovation, & Collaboration',
        ];

        $this->assertSame($expected, OrganizationalStructure::divisions());
        $this->assertSame(array_keys($expected), OrganizationalStructure::divisionCodes());
        $this->assertSame($expected, AnalyticsService::divisions());

        foreach ($expected as $code => $label) {
            $classification = OrganizationalUnit::query()
                ->where('unit_code', $code)
                ->firstOrFail();

            $this->assertSame('ORGANIZATIONAL_CLASSIFICATION', $classification->unit_type);
            $this->assertSame($label, $classification->unit_name);
            $this->assertSame($code, $classification->divisionCode());
            $this->assertSame($label, $classification->divisionLabel());
        }
    }

    public function test_every_final_selectable_leaf_resolves_exactly_one_classification(): void
    {
        $expected = [
            'BOARDSEC' => OrganizationalUnit::DIVISION_ADMINISTRATION,
            'IAU' => OrganizationalUnit::DIVISION_ADMINISTRATION,
            'IPDU' => OrganizationalUnit::DIVISION_ADMINISTRATION,
            'SPMU' => OrganizationalUnit::DIVISION_ADMINISTRATION,
            'ICTU' => OrganizationalUnit::DIVISION_ADMINISTRATION,
            'CCS' => OrganizationalUnit::DIVISION_ACADEMIC,
            'CTHBM' => OrganizationalUnit::DIVISION_ACADEMIC,
            'GRADSCHOOL' => OrganizationalUnit::DIVISION_ACADEMIC,
            'STUDENT_AFFAIRS' => OrganizationalUnit::DIVISION_ACADEMIC,
            'RDSO' => OrganizationalUnit::DIVISION_RIC,
            'ECSO' => OrganizationalUnit::DIVISION_RIC,
            'REB' => OrganizationalUnit::DIVISION_RIC,
        ];

        foreach ($expected as $unitCode => $classificationCode) {
            $unit = OrganizationalUnit::query()->where('unit_code', $unitCode)->firstOrFail();

            $this->assertTrue($unit->active);
            $this->assertTrue($unit->isSelectable(), "{$unitCode} must be selectable.");
            $this->assertSame($classificationCode, $unit->divisionCode());
        }

        foreach (OrganizationalUnit::query()->activeSelectable()->get() as $unit) {
            $this->assertContains($unit->divisionCode(), array_keys(OrganizationalStructure::divisions()));
        }
    }

    public function test_the_required_branch_hierarchy_is_preserved_above_selectable_leaves(): void
    {
        $this->assertSame(
            ['SPMU', 'CHIEF_ADMIN_OFFICE', 'ADMIN_FINANCE', 'ADMINISTRATION', 'CSPC'],
            $this->ancestorCodes('SPMU')
        );
        $this->assertSame(
            ['ICTU', 'SUPERVISING_ADMIN_OFFICE', 'ADMIN_FINANCE', 'ADMINISTRATION', 'CSPC'],
            $this->ancestorCodes('ICTU')
        );
        $this->assertSame(
            ['CCS', 'COLLEGES', 'ACADEMIC', 'CSPC'],
            $this->ancestorCodes('CCS')
        );
        $this->assertSame(
            ['CTHBM', 'COLLEGES', 'ACADEMIC', 'CSPC'],
            $this->ancestorCodes('CTHBM')
        );
        $this->assertSame(
            ['IAU', 'OPRES', 'ADMINISTRATION', 'CSPC'],
            $this->ancestorCodes('IAU')
        );

        $officeOfThePresident = OrganizationalUnit::query()->where('unit_code', 'OPRES')->firstOrFail();
        $this->assertFalse($officeOfThePresident->isSelectable());
        $this->assertSame(OrganizationalUnit::DIVISION_ADMINISTRATION, $officeOfThePresident->divisionCode());
    }

    public function test_user_administration_and_account_settings_use_official_classification_and_leaf_labels(): void
    {
        $ictu = $this->user(AccessClassification::IctuMaintainer);
        $borrower = $this->user(AccessClassification::BorrowerOnly);

        $this->actingAs($ictu)
            ->get(route('administration.users.create'))
            ->assertOk()
            ->assertSee('Organizational Classification')
            ->assertSee('Research, Innovation, &amp; Collaboration', false)
            ->assertSee('Supply and Property Management Unit (SPMU)')
            ->assertDontSee('Office of the Vice President for Academic Affairs');

        $this->actingAs($borrower)
            ->get(route('profile.show'))
            ->assertOk()
            ->assertSee('Organizational Classification')
            ->assertSee('Academic')
            ->assertSee('College of Computer Studies (CCS)');
    }

    public function test_reports_and_analytics_read_active_units_from_the_same_master_hierarchy(): void
    {
        $units = OrganizationalStructure::unitsByDivision();

        $this->assertContains('Supply and Property Management Unit (SPMU)', $units[OrganizationalUnit::DIVISION_ADMINISTRATION]);
        $this->assertContains('College of Computer Studies (CCS)', $units[OrganizationalUnit::DIVISION_ACADEMIC]);
        $this->assertContains('Research Development Services Office (RDSO)', $units[OrganizationalUnit::DIVISION_RIC]);
        $this->assertNotContains('Office of the Vice President for Academic Affairs', $units[OrganizationalUnit::DIVISION_ADMINISTRATION]);

        $filters = ReportFilters::fromRequest(
            Request::create('/reports', 'GET', [
                'division' => OrganizationalUnit::DIVISION_ACADEMIC,
                'unit' => 'College of Computer Studies (CCS)',
            ]),
            'borrowing',
            Carbon::parse('2026-04-01')->startOfDay(),
            Carbon::parse('2026-04-30')->endOfDay(),
            'month',
        );

        $this->assertSame(OrganizationalUnit::DIVISION_ACADEMIC, $filters->get('division'));
        $this->assertSame('College of Computer Studies (CCS)', $filters->get('unit'));
        $this->assertSame([], $filters->rejected());
    }

    public function test_existing_request_references_and_snapshots_remain_readable_when_a_unit_is_historical_only(): void
    {
        $borrower = $this->user(AccessClassification::BorrowerOnly);
        $legacy = OrganizationalUnit::query()->firstOrCreate(
            ['unit_code' => 'OVPAA'],
            [
                'unit_name' => 'Office of the Vice President for Academic Affairs',
                'unit_type' => 'ADMINISTRATIVE_UNIT',
                'active' => false,
            ],
        );

        $request = BorrowingRequest::query()->create([
            'request_no' => 'ORG-HISTORY-0001',
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $legacy->id,
            'current_version_no' => 1,
            'status' => RequestStatus::Draft,
        ]);

        RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Historical organizational-reference check',
            'location' => 'CSPC',
            'division_code' => OrganizationalUnit::DIVISION_ADMINISTRATION,
            'office_unit' => 'Office of the Vice President for Academic Affairs',
            'schedule_date' => '2026-05-10',
            'return_date' => '2026-05-11',
            'needed_from' => '2026-05-10 00:00:00',
            'return_due_at' => '2026-05-11 23:59:59',
            'created_by_user_id' => $borrower->id,
        ]);

        $this->assertFalse($legacy->active);

        $loaded = BorrowingRequest::query()
            ->with(['accountableUnit', 'currentVersion'])
            ->findOrFail($request->id);

        $this->assertSame($legacy->id, $loaded->accountableUnit?->id);
        $this->assertSame('Office of the Vice President for Academic Affairs', $loaded->currentVersion?->office_unit);
        $this->assertSame(OrganizationalUnit::DIVISION_ADMINISTRATION, $loaded->currentVersion?->division_code);
    }

    /** @return list<string> */
    private function ancestorCodes(string $unitCode): array
    {
        $codes = [];
        $unit = OrganizationalUnit::query()->where('unit_code', $unitCode)->firstOrFail();

        while ($unit) {
            $codes[] = $unit->unit_code;
            $unit = $unit->parent;
        }

        return $codes;
    }

    private function user(AccessClassification $classification): User
    {
        return User::query()
            ->where('access_classification', $classification->value)
            ->firstOrFail();
    }
}
