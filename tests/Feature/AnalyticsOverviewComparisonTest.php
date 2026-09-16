<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Compatibility filename for the former previous-period Overview test.
 *
 * Overview now acts as the executive summary of the four detailed analytics
 * workspaces. The detailed Top Released Items / Top Borrowing Units rankings
 * stay in Demand & Utilization. The standalone previous-period comparison and
 * Reporting Period Snapshot are no longer rendered; the snapshot's important
 * return figures are folded into the Borrowing & Return summary card instead.
 */
class AnalyticsOverviewComparisonTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_overview_uses_cross_tab_analytics_summary_without_duplicate_rankings(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 12, 9));

        $user = User::factory()->create([
            'access_classification' => AccessClassification::SpmuHead,
        ]);

        $response = $this->actingAs($user)->get(route('analytics.index', [
            'section' => 'overview',
            'academic_period' => 'month',
        ]));

        $response->assertOk();

        $response->assertSee('<h2>Analytics Summary</h2>', false);
        $response->assertSee('Demand &amp; Utilization', false);
        $response->assertSee('Inventory Health', false);
        $response->assertSee('Borrowing &amp; Return Performance', false);
        $response->assertSee('Forecast &amp; Planning', false);

        /* Each block is a short tab summary, not a second detailed dashboard. */
        $response->assertSee('Requested quantity', false);
        $response->assertSee('Released quantity', false);
        $response->assertSee('Available units', false);
        $response->assertSee('Approved for release', false);
        $response->assertSee('Completed returns', false);
        $response->assertSee('Returned on time / Late', false);
        $response->assertSee('Open accountability:', false);
        $response->assertSee('On-time return rate:', false);
        $response->assertSee('Forecast readiness', false);
        $response->assertSee('Scheduled next period', false);

        /* Detailed rankings remain owned by Demand & Utilization. */
        $response->assertDontSee('<h2>Top Released Items</h2>', false);
        $response->assertDontSee('<h2>Top Borrowing Units</h2>', false);

        /* The rejected replacement and old extra summary are both gone. */
        $response->assertDontSee('Previous Period Comparison', false);
        $response->assertDontSee('Reporting Period Snapshot', false);
    }
}
