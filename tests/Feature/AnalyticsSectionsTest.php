<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Http\Controllers\AnalyticsController;
use App\Models\BorrowingRequest;
use App\Models\OrganizationalUnit;
use App\Models\RequestVersion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every Analytics section must render on its own, with and without data, and
 * must never leak into Reports. These are rendering guarantees: the figures
 * themselves are pinned by AnalyticsServiceTest and ForecastServiceTest.
 */
class AnalyticsSectionsTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationalUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unit = OrganizationalUnit::query()->create([
            'unit_code' => 'SECTIONS',
            'unit_name' => 'Sections Fixture Unit',
            'unit_type' => 'OFFICE',
            'active' => true,
        ]);

        Carbon::setTestNow(Carbon::create(2026, 4, 20, 9));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function spmuHead(): User
    {
        return User::factory()->create([
            'access_classification' => AccessClassification::SpmuHead,
            'organizational_unit_id' => $this->unit->id,
        ]);
    }

    private function request(string $division, string $unit, Carbon $createdAt): void
    {
        $borrower = User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
        ]);

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-SEC-'.fake()->unique()->numberBetween(1000, 999999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $this->unit->id,
            'current_version_no' => 1,
            'status' => RequestStatus::UnderSpmu,
        ]);

        $request->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Sections fixture',
            'location' => 'Campus',
            'division_code' => $division,
            'office_unit' => $unit,
            'schedule_date' => $createdAt->copy()->addDay()->toDateString(),
            'return_date' => $createdAt->copy()->addDays(3)->toDateString(),
            'needed_from' => $createdAt->copy()->addDay()->startOfDay(),
            'return_due_at' => $createdAt->copy()->addDays(3)->endOfDay(),
        ]);
    }

    public function test_every_section_renders_on_an_empty_database(): void
    {
        $head = $this->spmuHead();

        foreach (array_keys(AnalyticsController::SECTIONS) as $section) {
            $this->actingAs($head)
                ->get(route('analytics.index', ['section' => $section, 'academic_period' => 'month']))
                ->assertOk()
                ->assertSee('Analytics');
        }
    }

    public function test_an_unknown_section_falls_back_to_overview(): void
    {
        $this->actingAs($this->spmuHead())
            ->get(route('analytics.index', ['section' => 'not-a-section']))
            ->assertOk()
            ->assertSee('What You Need to Know');
    }

    public function test_overview_shows_the_four_headline_figures(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies', Carbon::create(2026, 4, 5, 10));

        $this->actingAs($this->spmuHead())
            ->get(route('analytics.index', ['section' => 'overview', 'academic_period' => 'month']))
            ->assertOk()
            ->assertSee('Requests')
            ->assertSee('Currently Out')
            ->assertSee('Need Follow-up')
            ->assertSee('Low Availability')
            ->assertSee('Borrower Activity')
            ->assertSee('Most Active Units');
    }

    public function test_borrowers_reports_the_three_canonical_divisions(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies', Carbon::create(2026, 4, 5, 10));
        $this->request('ADMINISTRATION', 'Student Affairs and Services', Carbon::create(2026, 4, 6, 10));
        $this->request(
            'RESEARCH_INNOVATION_COLLABORATION',
            'Research and Development Services Office (RDSO)',
            Carbon::create(2026, 4, 7, 10)
        );

        $this->actingAs($this->spmuHead())
            ->get(route('analytics.index', ['section' => 'borrowers', 'academic_period' => 'month']))
            ->assertOk()
            ->assertSee('Academic')
            ->assertSee('Administrative')
            ->assertSee('Research &amp; Innovation', false)
            ->assertSee('College of Computer Studies')
            ->assertSee('Student Affairs and Services')
            ->assertSee('Research and Development Services Office (RDSO)');
    }

    public function test_equipment_keeps_requested_and_released_apart(): void
    {
        $this->actingAs($this->spmuHead())
            ->get(route('analytics.index', ['section' => 'equipment', 'academic_period' => 'month']))
            ->assertOk()
            ->assertSee('Most Requested Equipment')
            ->assertSee('Actually Released')
            ->assertSee('No equipment was requested during this period.')
            ->assertSee('No equipment was physically released during this period.');
    }

    public function test_returns_shows_its_four_figures_and_a_zero_state(): void
    {
        $this->actingAs($this->spmuHead())
            ->get(route('analytics.index', ['section' => 'returns', 'academic_period' => 'month']))
            ->assertOk()
            ->assertSee('Returned On Time')
            ->assertSee('Returned Late')
            ->assertSee('Currently Overdue')
            ->assertSee('Open Accountability')
            ->assertSee('No completed returns are available for this period yet.');
    }

    public function test_forecast_withholds_numbers_without_enough_history(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies', Carbon::create(2026, 4, 5, 10));

        $this->actingAs($this->spmuHead())
            ->get(route('analytics.index', ['section' => 'forecast', 'academic_period' => 'month']))
            ->assertOk()
            ->assertSee('Not enough historical data to generate a reliable forecast.')
            ->assertSee('Known scheduled demand')
            ->assertSee('Forecast Basis')
            /* A withheld forecast must not still show headline predictions. */
            ->assertDontSee('Expected Requests')
            ->assertDontSee('Busiest Unit');
    }

    public function test_forecast_shows_predictions_once_history_supports_them(): void
    {
        foreach ([
            [Carbon::create(2026, 3, 10, 10), 6],
            [Carbon::create(2026, 2, 10, 10), 4],
            [Carbon::create(2026, 1, 10, 10), 2],
        ] as [$when, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $this->request('ACADEMIC', 'College of Computer Studies', $when->copy()->addHours($i));
            }
        }

        $this->actingAs($this->spmuHead())
            ->get(route('analytics.index', ['section' => 'forecast', 'academic_period' => 'month']))
            ->assertOk()
            ->assertSee('Expected Requests')
            ->assertSee('Forecast period')
            ->assertSee('Borrowing Demand Forecast')
            ->assertSee('Forecast Basis')
            ->assertDontSee('Not enough historical data to generate a reliable forecast.');
    }

    public function test_switching_section_preserves_the_reporting_period_and_filters(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies', Carbon::create(2026, 4, 5, 10));

        $response = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'overview',
            'academic_period' => 'week',
            'group' => 'ACADEMIC',
        ]));

        $response->assertOk();

        /* Every tab link must carry the active period and group forward. */
        $response->assertSee('academic_period=week', false);
        $response->assertSee('group=ACADEMIC', false);
    }

    public function test_analytics_stays_closed_to_everyone_but_the_spmu_head(): void
    {
        $borrower = User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
        ]);

        $this->actingAs($borrower)
            ->get(route('analytics.index'))
            ->assertForbidden();
    }

    public function test_analytics_does_not_absorb_the_reports_module(): void
    {
        $response = $this->actingAs($this->spmuHead())->get(route('analytics.index'));

        $response->assertOk();

        /* Reports keeps its own page; Analytics must not rebuild its controls. */
        $response->assertDontSee('Generate Report');
        $response->assertDontSee('Export CSV');
    }
}
