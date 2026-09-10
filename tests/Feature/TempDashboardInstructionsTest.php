<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TempDashboardInstructionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function dashboardHtml(AccessClassification $classification, string $workspace): string
    {
        $user = User::query()
            ->where('access_classification', $classification->value)
            ->firstOrFail();

        return $this->withSession(['active_workspace' => $workspace])
            ->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->getContent();
    }

    public function test_head_dashboard_drops_the_responsibility_panel(): void
    {
        $html = $this->dashboardHtml(AccessClassification::SpmuHead, 'SPMU');

        foreach ([
            'Approval authority',
            'Head responsibility',
            'Review the submitted request and signed documents',
            'Check requested quantities, dates, and current availability',
            'Approve &amp; reserve, return for revision, or reject',
            'Monitor custody, issues, and inventory oversight',
            'After approval, pickup scheduling',
        ] as $instruction) {
            $this->assertStringNotContainsString($instruction, $html);
        }

        // Operational content is untouched.
        $this->assertStringContainsString('Requests needing your approval decision', $html);
        $this->assertStringContainsString('For Approval', $html);
        $this->assertStringContainsString('dashboard-kpi-card', $html);
        $this->assertStringContainsString('Open Approval Queue', $html);
    }

    public function test_action_officer_dashboard_drops_the_workflow_panel(): void
    {
        $html = $this->dashboardHtml(AccessClassification::SpmuOfficer, 'SPMU');

        foreach ([
            'Action Officer workflow',
            'Verification through release',
            'Verify the submitted request and required documents',
            'Route VERIFIED requests to the SPMU Head for decision',
            'Prepare and physically release the approved items',
            'Keep Laundry and final reconciliation in their separate existing paths',
        ] as $instruction) {
            $this->assertStringNotContainsString($instruction, $html);
        }

        $this->assertStringContainsString('Requests requiring verification', $html);
        $this->assertStringContainsString('Open Verification Queue', $html);
        $this->assertStringContainsString('dashboard-kpi-card', $html);
    }

    public function test_ictu_dashboard_drops_the_scope_panel(): void
    {
        $html = $this->dashboardHtml(AccessClassification::IctuMaintainer, 'ICTU');

        foreach ([
            'Technical scope',
            'ICTU responsibility',
            'Manage active user accounts',
            'Maintain system settings',
            'Monitor email/SMS delivery records',
            'ICTU does not approve borrowing',
        ] as $instruction) {
            $this->assertStringNotContainsString($instruction, $html);
        }

        $this->assertStringContainsString('Recent account activity', $html);
        $this->assertStringContainsString('Manage Accounts', $html);
        $this->assertStringContainsString('dashboard-kpi-card', $html);
    }

    public function test_no_empty_grid_column_remains(): void
    {
        foreach ([
            [AccessClassification::SpmuHead, 'SPMU'],
            [AccessClassification::SpmuOfficer, 'SPMU'],
            [AccessClassification::IctuMaintainer, 'ICTU'],
        ] as [$classification, $workspace]) {
            $html = $this->dashboardHtml($classification, $workspace);

            // The queue is the only panel and the grid is a single column.
            $this->assertStringContainsString('dashboard-single-panel', $html);
            $this->assertStringContainsString('.dashboard-balanced-grid.dashboard-single-panel { grid-template-columns: minmax(0, 1fr); }', $html);
            $this->assertSame(1, substr_count($html, 'class="card queue-card dashboard-panel-equal'));
            $this->assertStringNotContainsString('workflow-mini-list', $html);
        }
    }

    public function test_borrower_dashboard_is_unaffected(): void
    {
        $html = $this->dashboardHtml(AccessClassification::BorrowerOnly, 'BORROWER');

        $this->assertStringContainsString('Your next actions', $html);
        $this->assertStringContainsString('Active requests', $html);
        $this->assertStringContainsString('is-borrower-dashboard', $html);
        $this->assertStringNotContainsString('workflow-mini-list', $html);
    }
}
