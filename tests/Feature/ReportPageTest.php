<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\AuditEvent;
use App\Models\BorrowingRequest;
use App\Models\OrganizationalUnit;
use App\Models\RequestVersion;
use App\Models\User;
use App\Reports\ReportCatalogue;
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
        $response->assertSee('Preview official operational reports, then print or export the exact records you reviewed.', false);
        $response->assertSee('Preview Report', false);
        $response->assertSee('Filters', false);
        $response->assertDontSee('More Filters', false);
    }

    public function test_reports_page_starts_with_configuration_and_no_generated_document(): void
    {
        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index'));

        $response->assertOk();
        $response->assertSee('Choose the report options above, then select Preview Report to review the records here.', false);
        $response->assertDontSee('CAMARINES SUR POLYTECHNIC COLLEGES', false);
        $response->assertDontSee('Export / Print', false);
        $response->assertDontSee('Web record navigation', false);
        $response->assertDontSee('For analysis, insights, and forecasting, use the Analytics module.', false);
    }


    public function test_preview_button_submits_the_generated_flag_and_filters_are_visible(): void
    {
        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index', ['report' => 'approval']));

        $response->assertOk();
        $response->assertSee('name="generated"', false);
        $response->assertSee('value="1"', false);
        $response->assertSee('Preview Report', false);
        $response->assertSee('AO Verification', false);
        $response->assertSee('Admin Decision', false);
        $response->assertDontSee('<details class="report-more-filters"', false);
    }

    public function test_applied_filters_show_a_clear_filters_action(): void
    {
        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index', [
                'report' => 'approval',
                'academic_period' => 'month',
                'division' => 'ACADEMIC',
                'generated' => 1,
            ]));

        $response->assertOk();
        $response->assertSee('1 applied', false);
        $response->assertSee('Clear filters', false);
        $response->assertDontSee('Applied filters:', false);
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
        $response->assertSee('Date Range', false);
        $response->assertSee(now()->startOfMonth()->format('d M Y').' – '.now()->endOfMonth()->format('d M Y'), false);
    }

    public function test_generated_reports_page_shows_the_analytics_boundary_note(): void
    {
        /* The boundary note belongs to an actual rendered report, not an empty preview. */
        $this->request('ACADEMIC', 'College of Computer Studies');

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
            'Priority Insights',
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
            ->assertDontSee('ICTU system administration', false)
            /* Under the 500-row display cap, no "latest N of total" caveat is shown. */
            ->assertDontSee('Showing latest', false);
    }

    public function test_audit_trail_past_the_display_cap_states_it_is_showing_only_the_latest_records(): void
    {
        $ictu = User::factory()->create([
            'access_classification' => AccessClassification::IctuMaintainer,
            'full_name' => 'ICTU Maintainer',
        ]);

        $now = now();

        $rows = [];

        foreach (range(1, 510) as $index) {
            $rows[] = [
                'actor_user_id' => $ictu->id,
                'action_code' => 'audit_cap_fixture.recorded',
                'record_type' => User::class,
                'record_id' => $ictu->id,
                'occurred_at' => $now->copy()->subMinutes($index),
                'reason' => 'Audit trail 500-row display cap fixture.',
                'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        \Illuminate\Support\Facades\DB::table('audit_events')->insert($rows);

        /*
         * The display cap only ever hides rows, it never deletes or
         * rewrites them: every one of the 510 rows inserted above is still
         * in audit_events after the page renders.
         */
        $this->actingAs($ictu)
            ->withSession(['active_workspace' => 'ICTU'])
            ->get(route('reports.audit'))
            ->assertOk()
            ->assertSee('Showing latest 500 of 510 total records.', false);

        $this->assertSame(510, \App\Models\AuditEvent::query()->count());
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
        /* A formal document is intentionally not rendered for a zero-record preview. */
        $category = \App\Models\InventoryCategory::query()->create([
            'category_code' => 'PAGE-INV',
            'category_name' => 'Reports Page Inventory',
            'active' => true,
        ]);
        $measure = \App\Models\UnitOfMeasure::query()->create([
            'unit_code' => 'PAGE-PC',
            'unit_name' => 'Piece',
            'active' => true,
        ]);
        \App\Models\InventoryItem::query()->create([
            'category_id' => $category->id,
            'unit_id' => $measure->id,
            'unique_description' => 'Reports page inventory fixture',
            'total_quantity' => 1,
            'condition_code' => 'SERVICEABLE',
            'borrowable' => true,
            'off_campus_allowed' => false,
            'laundry_required' => false,
            'provisional' => false,
            'active' => true,
        ]);

        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index', ['report' => 'inventory', 'academic_period' => 'month', 'generated' => 1]));

        $response->assertSee('INVENTORY STATUS REPORT', false);
        $response->assertSee('As of '.now()->endOfMonth()->format('d F Y'), false);
        $response->assertDontSee('For the period', false);
    }

    public function test_report_options_dialog_offers_only_supported_formats(): void
    {
        /* Export controls are only offered after a preview has real records. */
        $this->request('ACADEMIC', 'College of Computer Studies');

        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index', ['report' => 'borrowing', 'generated' => 1]));

        $response->assertSee('Report Options', false);
        $response->assertSee('Export / Print', false);

        foreach (ReportExportOptions::FORMATS as $value => $label) {
            $response->assertSee('value="'.$value.'"', false);
        }

        /* Universal output controls: only relevant settings stay user-editable. */
        $response->assertSee('Include report summary', false);
        $response->assertSee('Automatic (Recommended)', false);
        $response->assertSee('Advanced Print Settings', false);
        $response->assertSee('Included automatically in printed and exported reports', false);
        $response->assertSee('Export PDF', false);
        $response->assertSee('Export Word', false);
        $response->assertSee('Export Excel', false);
        $response->assertSee('Export CSV', false);
        $response->assertSee('Open Print Preview', false);
        $response->assertDontSee('Include generated-by information', false);
        $response->assertDontSee('Include system-generated footer', false);
        $response->assertDontSee('Repeat table headers on each PDF/printed page', false);
        $response->assertDontSee('Max Title Height', false);
        $response->assertDontSee('Max Row Height', false);
    }

    public function test_empty_report_shows_its_own_empty_state_not_a_broken_table(): void
    {
        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index', ['report' => 'custody', 'generated' => 1]));

        $response->assertOk();
        $response->assertSee('No matching records', false);
        $response->assertSee('No released/custody records were found for this period.', false);
        $response->assertSee('Change Period or Filters', false);
        $response->assertDontSee('Export / Print', false);
        $response->assertDontSee('<article class="doc-sheet">', false);
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

    public function test_transaction_reports_offer_a_borrower_filter_while_asset_reports_do_not(): void
    {
        foreach ([
            'borrowing',
            'approval',
            'custody',
            'returns',
            'accountability-cases',
            'billing-settlement',
            'laundry',
            'gate-pass',
        ] as $report) {
            $this->assertArrayHasKey(
                'borrower',
                ReportCatalogue::filtersFor($report),
                "{$report} should support borrower filtering."
            );
        }

        $this->assertArrayNotHasKey('borrower', ReportCatalogue::filtersFor('inventory'));
        $this->assertArrayNotHasKey('borrower', ReportCatalogue::filtersFor('utilization'));
    }

    public function test_borrower_filter_limits_the_report_and_is_written_into_report_metadata(): void
    {
        $target = User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
            'full_name' => 'Target Borrower',
            'email' => 'target.borrower@cspc.edu.ph',
        ]);

        $other = User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
            'full_name' => 'Other Borrower',
            'email' => 'other.borrower@cspc.edu.ph',
        ]);

        $targetRequest = $this->request('ACADEMIC', 'College of Computer Studies', $target);
        $otherRequest = $this->request('ACADEMIC', 'College of Computer Studies', $other);

        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index', [
                'report' => 'borrowing',
                'academic_period' => 'month',
                'borrower' => (string) $target->id,
                'generated' => 1,
            ]));

        $response->assertOk();
        $response->assertSee((string) $targetRequest->request_no, false);
        $response->assertDontSee((string) $otherRequest->request_no, false);
        $response->assertSee('Borrower:', false);
        $response->assertSee('Target Borrower', false);
        $response->assertDontSee('target.borrower@cspc.edu.ph', false);
        $response->assertSee('name="borrower" value="'.$target->id.'"', false);
    }

    public function test_pagination_preserves_report_period_and_filters(): void
    {
        /* Twelve records over a page size of ten forces a second page. */
        $borrower = User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
        ]);

        foreach (range(1, 12) as $index) {
            $this->request('ACADEMIC', 'College of Computer Studies', $borrower);
        }

        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index', [
                'report' => 'borrowing',
                'academic_period' => 'month',
                'division' => 'ACADEMIC',
                'borrower' => (string) $borrower->id,
                'generated' => 1,
            ]));

        $response->assertOk();
        $response->assertSee('Web record navigation', false);
        $response->assertSee('Showing 1–10 of 12 records', false);
        $response->assertSee('aria-label="Report summary values"', false);
        $response->assertSee('Total requests', false);

        /* Every page link carries the report, the period and the filter. */
        $response->assertSee('report=borrowing', false);
        $response->assertSee('academic_period=month', false);
        $response->assertSee('division=ACADEMIC', false);
        $response->assertSee('borrower='.$borrower->id, false);
        $response->assertSee('generated=1', false);
    }

    /* ------------------------------------------------------------------ */
    /* Print and export                                                    */
    /* ------------------------------------------------------------------ */

    public function test_formal_document_shows_a_single_compact_report_scope_line(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies');

        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.print', [
                'type' => 'borrowing',
                'academic_period' => 'month',
                'division' => 'ACADEMIC',
                'unit' => 'College of Computer Studies',
            ]));

        $response->assertOk();
        $response->assertSee('Report Scope', false);
        $response->assertSee('Academic · College of Computer Studies', false);

        /*
         * One compact value list, not the old per-filter label sentence. The
         * report's own "Organizational Classification" table column is
         * unrelated and expected to still appear.
         */
        $response->assertDontSee('Applied Filters', false);
        $response->assertDontSee('All classifications', false);
        $response->assertDontSee('All units', false);
    }

    public function test_formal_document_omits_report_scope_entirely_without_a_meaningful_filter(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies');

        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.print', [
                'type' => 'borrowing',
                'academic_period' => 'month',
            ]));

        $response->assertOk();
        $response->assertDontSee('Report Scope', false);
    }

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

    private function request(string $division, string $unit, ?User $borrower = null): BorrowingRequest
    {
        $createdAt = now()->copy()->subDays(2);

        $borrower ??= User::factory()->create([
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
