<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\AuditEvent;
use App\Models\BorrowingRequest;
use App\Models\OrganizationalUnit;
use App\Models\RequestVersion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Reports page as a product surface.
 *
 * These tests guard the module boundaries the redesign is built on: Reports
 * generates official records, Analytics interprets them, and the Audit Trail
 * says who did what. Each of the three is its own destination, and none of
 * them borrows another's controls.
 */
class ReportPageTest extends TestCase
{
    use RefreshDatabase;

    private User $head;

    private OrganizationalUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 4, 20, 9));

        $this->unit = OrganizationalUnit::query()->create([
            'unit_code' => 'PAGE',
            'unit_name' => 'Reports Page Unit',
            'unit_type' => 'OFFICE',
            'active' => true,
        ]);

        $this->head = User::factory()->create([
            'access_classification' => AccessClassification::SpmuHead,
            'full_name' => 'SPMU Head',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* Page and builder                                                    */
    /* ------------------------------------------------------------------ */

    public function test_reports_page_renders_the_report_builder(): void
    {
        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index'));

        $response->assertOk();
        $response->assertSee('Report builder', false);
        $response->assertSee('Generate detailed operational reports for review, documentation, printing, and export.', false);
        $response->assertSee('Generate Report', false);
        $response->assertSee('More Filters', false);
    }

    public function test_builder_groups_the_report_types(): void
    {
        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index'));

        foreach (['Borrowing', 'Custody &amp; Return', 'Assets', 'Special Operations'] as $group) {
            $response->assertSee('<optgroup label="'.$group.'">', false);
        }
    }

    public function test_resolved_reporting_period_is_shown(): void
    {
        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index', ['academic_period' => 'month']));

        $response->assertOk();
        $response->assertSee('Resolved Period', false);
        $response->assertSee(now()->startOfMonth()->format('d M Y').' – '.now()->endOfMonth()->format('d M Y'), false);
    }

    public function test_reports_page_shows_the_analytics_boundary_note(): void
    {
        $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index'))
            ->assertSee('For analysis, insights, and forecasting, use the Analytics module.', false);
    }

    public function test_reports_page_carries_no_predictive_analytics(): void
    {
        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index'));

        foreach ([
            'Expected Requests',
            'Main Borrower Group',
            'Busiest Forecast Unit',
            'Equipment Shortage Forecast',
            'Expected Busy Period',
            'What You Need to Know',
        ] as $analyticsOnly) {
            $response->assertDontSee($analyticsOnly, false);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Module separation                                                   */
    /* ------------------------------------------------------------------ */

    public function test_reports_header_does_not_carry_audit_trail_or_delivery_buttons(): void
    {
        /*
         * These dedicated modules do not belong among the report-generation
         * actions in the page header.
         */
        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index'));

        $response->assertDontSee('<a class="button secondary" href="'.route('reports.audit').'"', false);
        $response->assertDontSee('<a class="button secondary" href="'.route('reports.notifications').'"', false);
    }

    public function test_spmu_head_sidebar_hides_audit_trail_and_delivery_records(): void
    {
        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index'));

        $response->assertDontSee('<span>Audit Trail</span>', false);
        $response->assertDontSee('<span>Delivery Records</span>', false);
    }

    public function test_reports_links_to_the_existing_audit_trail_rather_than_repeating_it(): void
    {
        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index'));

        $response->assertSee('View related audit history', false);

        /* The link points at the dedicated module, which still renders. */
        $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.audit'))
            ->assertOk()
            ->assertSee('Audit trail', false);
    }

    public function test_delivery_is_not_offered_as_a_report_action(): void
    {
        /*
         * Nothing in the system can distribute a generated report, so Reports
         * does not offer a Deliver action. "Delivery Records" remains what it
         * has always been: the notification-attempt log, on its own page.
         */
        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index'));

        $response->assertDontSee('Deliver Report', false);
        $response->assertDontSee('>Deliver<', false);
    }

    public function test_analytics_remains_a_separate_module(): void
    {
        $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('analytics.index'))
            ->assertOk();

        /* Old ?tab=analytics links forward rather than rendering Reports. */
        $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index', ['tab' => 'analytics']))
            ->assertRedirect();
    }

    /* ------------------------------------------------------------------ */
    /* Generated report                                                    */
    /* ------------------------------------------------------------------ */

    public function test_generated_report_states_its_provenance(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies');

        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index', ['report' => 'borrowing', 'academic_period' => 'month']));

        $response->assertOk();
        $response->assertSee('Records Found', false);
        $response->assertSee('Applied Filters', false);
        $response->assertSee('Generated By', false);
        $response->assertSee('Generated On', false);
        $response->assertSee('SPMU Head', false);
    }

    public function test_export_and_print_are_offered_on_the_generated_report(): void
    {
        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index', ['report' => 'borrowing']));

        $response->assertSee('Export CSV', false);
        $response->assertSee('Print', false);
    }

    public function test_empty_report_shows_its_own_empty_state_not_a_broken_table(): void
    {
        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index', ['report' => 'custody']));

        $response->assertOk();
        $response->assertSee('No released/custody records were found for this period.', false);
        $response->assertDontSee('<table class="report-table">', false);
    }

    /* ------------------------------------------------------------------ */
    /* Filters, validation, pagination                                     */
    /* ------------------------------------------------------------------ */

    public function test_invalid_report_type_does_not_crash_the_page(): void
    {
        $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index', ['report' => 'not-a-report']))
            ->assertOk()
            ->assertSee('Borrowing Activity Report', false);
    }

    public function test_invalid_division_and_unit_pair_does_not_crash_the_page(): void
    {
        $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index', [
                'report' => 'borrowing',
                'division' => 'NOT_A_DIVISION',
                'unit' => 'Nowhere Office',
            ]))
            ->assertOk()
            ->assertSee('were not recognised and were ignored', false);
    }

    public function test_pagination_preserves_report_period_and_filters(): void
    {
        /* Twelve records over a page size of ten forces a second page. */
        foreach (range(1, 12) as $index) {
            $this->request('ACADEMIC', 'College of Computer Studies');
        }

        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index', [
                'report' => 'borrowing',
                'academic_period' => 'month',
                'division' => 'ACADEMIC',
            ]));

        $response->assertOk();
        $response->assertSee('Showing 1–10 of 12 records', false);

        /* Every page link carries the report, the period and the filter. */
        $response->assertSee('report=borrowing', false);
        $response->assertSee('academic_period=month', false);
        $response->assertSee('division=ACADEMIC', false);
    }

    /* ------------------------------------------------------------------ */
    /* Print and export                                                    */
    /* ------------------------------------------------------------------ */

    public function test_print_view_renders_the_whole_record_set_with_metadata(): void
    {
        foreach (range(1, 12) as $index) {
            $this->request('ACADEMIC', 'College of Computer Studies');
        }

        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.print', [
                'type' => 'borrowing',
                'academic_period' => 'month',
            ]));

        $response->assertOk();
        $response->assertSee('Borrowing Activity Report', false);
        $response->assertSee('Records Found', false);
        $response->assertSee('Generated By', false);
        $response->assertSee('Supply and Property Management Unit', false);

        /* Print is not paginated: all twelve records are on the page. */
        $response->assertSee('12 records in this report.', false);
    }

    public function test_csv_export_is_recorded_in_the_audit_trail(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies');

        $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.export', ['type' => 'borrowing', 'academic_period' => 'month']))
            ->assertOk();

        $this->assertDatabaseHas('audit_events', [
            'action_code' => 'report.exported',
            'actor_user_id' => $this->head->id,
        ]);

        $this->assertNotNull(
            AuditEvent::query()->where('action_code', 'report.exported')->first()?->reason
        );
    }

    /* ------------------------------------------------------------------ */
    /* Fixture                                                             */
    /* ------------------------------------------------------------------ */

    private function request(string $division, string $unit): BorrowingRequest
    {
        $createdAt = now()->copy()->subDays(2);

        $borrower = User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
        ]);

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-'.fake()->unique()->numberBetween(100000, 999999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $this->unit->id,
            'current_version_no' => 1,
            'status' => RequestStatus::UnderSpmu,
        ]);

        $request->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Reports page fixture activity',
            'location' => 'Campus',
            'division_code' => $division,
            'office_unit' => $unit,
            'schedule_date' => $createdAt->copy()->addDay()->toDateString(),
            'return_date' => $createdAt->copy()->addDays(3)->toDateString(),
            'needed_from' => $createdAt->copy()->addDay()->startOfDay(),
            'return_due_at' => $createdAt->copy()->addDays(3)->endOfDay(),
        ]);

        return $request;
    }
}
