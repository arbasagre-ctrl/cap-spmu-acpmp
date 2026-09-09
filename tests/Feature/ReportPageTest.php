<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\AuditEvent;
use App\Models\BorrowingRequest;
use App\Models\OrganizationalUnit;
use App\Models\RequestVersion;
use App\Models\User;
use App\Reports\ReportExportOptions;
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

    public function test_reports_page_starts_with_configuration_and_no_generated_document(): void
    {
        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index'));

        $response->assertOk();
        $response->assertSee('Select your report options and click Generate Report to preview the report.', false);
        $response->assertDontSee('CAMARINES SUR POLYTECHNIC COLLEGES', false);
        $response->assertDontSee('Export / Print', false);
        $response->assertDontSee('Web record navigation', false);
        $response->assertDontSee('For analysis, insights, and forecasting, use the Analytics module.', false);
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

    public function test_generated_reports_page_shows_the_analytics_boundary_note(): void
    {
        $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index', ['generated' => 1]))
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

    public function test_spmu_head_cannot_see_or_open_the_ictu_audit_trail(): void
    {
        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index'));

        $response->assertDontSee('View related audit history', false);
        $response->assertDontSee(route('reports.audit'), false);

        $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.audit'))
            ->assertForbidden();
    }

    public function test_ictu_maintainer_can_open_the_audit_trail(): void
    {
        $ictu = User::factory()->create([
            'access_classification' => AccessClassification::IctuMaintainer,
            'full_name' => 'ICTU Maintainer',
        ]);

        $this->actingAs($ictu)
            ->withSession(['active_workspace' => 'ICTU'])
            ->get(route('reports.audit'))
            ->assertOk()
            ->assertSee('Audit Trail', false)
            ->assertSee('System administration', false)
            ->assertDontSee('ICTU system administration', false);
    }

    public function test_spmu_administration_page_does_not_link_to_the_ictu_audit_trail(): void
    {
        $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('administration.index'))
            ->assertOk()
            ->assertDontSee('Open audit trail', false)
            ->assertDontSee('Full audit', false)
            ->assertDontSee('Recent attributable actions', false)
            ->assertDontSee(route('reports.audit'), false);
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
            ->get(route('reports.index', ['report' => 'borrowing', 'academic_period' => 'month', 'generated' => 1]));

        $response->assertOk();

        /* Institutional document text, not dashboard metadata cards. */
        $response->assertSee('Prepared by', false);
        $response->assertSee('Date Generated', false);
        $response->assertSee('SPMU Head', false);

        $response->assertDontSee('Records Found', false);
        $response->assertDontSee('Generated By', false);
        $response->assertDontSee('Generated On', false);
    }

    public function test_preview_renders_the_institutional_document(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies');

        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index', ['report' => 'borrowing', 'academic_period' => 'month', 'generated' => 1]));

        $response->assertSee('Republic of the Philippines', false);
        $response->assertSee('CAMARINES SUR POLYTECHNIC COLLEGES', false);
        $response->assertSee('Nabua, Camarines Sur', false);
        $response->assertSee('BORROWING ACTIVITY REPORT', false);
        $response->assertSee('For the period', false);
        $response->assertSee('This is a system-generated report. No signature is required.', false);

        /* No document code belongs in the institutional header. */
        $response->assertDontSee('CSPC-F-SPMU', false);
    }

    public function test_inventory_report_states_an_as_of_date_not_a_range(): void
    {
        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index', ['report' => 'inventory', 'academic_period' => 'month', 'generated' => 1]));

        $response->assertSee('INVENTORY STATUS REPORT', false);
        $response->assertSee('As of '.now()->endOfMonth()->format('d F Y'), false);
        $response->assertDontSee('For the period', false);
    }

    public function test_report_options_dialog_offers_only_supported_formats(): void
    {
        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index', ['report' => 'borrowing', 'generated' => 1]));

        $response->assertSee('Report Options', false);
        $response->assertSee('Export / Print', false);

        foreach (ReportExportOptions::FORMATS as $value => $label) {
            $response->assertSee('value="'.$value.'"', false);
        }

        /* Page setup and content toggles, not report-engine internals. */
        $response->assertSee('Include report summary', false);
        $response->assertSee('Repeat table headers on each PDF/printed page', false);
        $response->assertSee('Export XLSX', false);
        $response->assertSee('Export CSV', false);
        $response->assertSee('Print Report', false);
        $response->assertDontSee('Max Title Height', false);
        $response->assertDontSee('Max Row Height', false);
    }

    public function test_empty_report_shows_its_own_empty_state_not_a_broken_table(): void
    {
        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index', ['report' => 'custody', 'generated' => 1]));

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
                'generated' => 1,
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
                'generated' => 1,
            ]));

        $response->assertOk();
        $response->assertSee('Web record navigation', false);
        $response->assertSee('Showing 1–10 of 12 records', false);
        $response->assertSee('<dt>Total requests</dt>', false);
        $response->assertSee('<dd>12</dd>', false);

        /* Every page link carries the report, the period and the filter. */
        $response->assertSee('report=borrowing', false);
        $response->assertSee('academic_period=month', false);
        $response->assertSee('division=ACADEMIC', false);
        $response->assertSee('generated=1', false);
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
        $response->assertSee('BORROWING ACTIVITY REPORT', false);
        $response->assertSee('Prepared by', false);
        $response->assertSee('Date Generated', false);

        /* The institutional header, without any document code. */
        $response->assertSee('Republic of the Philippines', false);
        $response->assertSee('CAMARINES SUR POLYTECHNIC COLLEGES', false);
        $response->assertSee('Nabua, Camarines Sur', false);
        $response->assertDontSee('CSPC-F-SPMU', false);
        $response->assertDontSee('Report builder', false);
        $response->assertDontSee('Export / Print', false);
        $response->assertDontSee('Web record navigation', false);
        $response->assertDontSee('<aside', false);

        /* Print is not paginated: every record is on the page. */
        $this->assertSame(
            12,
            substr_count($response->getContent(), 'Reports page fixture activity')
        );
    }

    public function test_csv_export_is_recorded_in_the_audit_trail(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies');

        $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.export', [
                'type' => 'borrowing',
                'format' => 'csv',
                'academic_period' => 'month',
            ]))
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
