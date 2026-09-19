<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ItemPreparationRouteRegistrationTest extends TestCase
{
    #[Test]
    public function step_two_inventory_discrepancy_routes_are_registered(): void
    {
        $report = Route::getRoutes()->getByName('custody.report-preparation-issue');
        $resolve = Route::getRoutes()->getByName('custody.resolve-preparation-issue');

        $this->assertNotNull($report);
        $this->assertSame(
            'custody/{custody}/report-preparation-issue',
            $report->uri()
        );
        $this->assertContains('POST', $report->methods());

        $this->assertNotNull($resolve);
        $this->assertSame(
            'custody/{custody}/preparation-issues/{preparationIssue}/resolve',
            $resolve->uri()
        );
        $this->assertContains('POST', $resolve->methods());
    }
}
