<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use App\Models\OrganizationalUnit;
use App\Models\RequestVersion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportBorrowerCascadeTest extends TestCase
{
    use RefreshDatabase;

    private User $head;
    private OrganizationalUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 9, 11, 10));

        $this->unit = OrganizationalUnit::query()->create([
            'unit_code' => 'RBC',
            'unit_name' => 'Reports Borrower Cascade Unit',
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

    public function test_initial_builder_keeps_all_borrowers_closed_and_search_only_inside_picker(): void
    {
        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index', ['report' => 'borrowing']));

        $response->assertOk();
        $response->assertSee('data-borrower-picker', false);
        $response->assertSee('data-borrower-trigger', false);
        $response->assertSee('data-borrower-current', false);
        $response->assertSee('All borrowers', false);
        $response->assertSee('Search borrower by name or email...', false);
        $response->assertSee('data-borrower-menu hidden', false);
    }

    public function test_borrower_picker_builds_a_fresh_scope_url_instead_of_appending_to_form_action(): void
    {
        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->get(route('reports.index', [
                'report' => 'borrowing',
                'academic_period' => 'month',
                'division' => 'ACADEMIC',
                'generated' => 1,
            ]));

        $response->assertOk();
        $response->assertSee("previewForm.getAttribute('action') || window.location.pathname", false);
        $response->assertSee('endpoint.search = params.toString();', false);
        $response->assertDontSee('`${previewForm.action || window.location.pathname}?${params.toString()}`', false);
    }

    public function test_borrower_options_are_scoped_by_division_and_office(): void
    {
        $ccs = $this->borrower('CCS Borrower', 'ccs.borrower@cspc.edu.ph');
        $cas = $this->borrower('CAS Borrower', 'cas.borrower@cspc.edu.ph');
        $admin = $this->borrower('Admin Borrower', 'admin.borrower@cspc.edu.ph');

        $this->request($ccs, 'ACADEMIC', 'College of Computer Studies');
        $this->request($cas, 'ACADEMIC', 'College of Arts and Sciences');
        $this->request($admin, 'ADMINISTRATION', 'Budget Office');

        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->getJson(route('reports.index', [
                'borrower_options' => 1,
                'report' => 'borrowing',
                'academic_period' => 'month',
                'division' => 'ACADEMIC',
                'unit' => 'College of Computer Studies',
            ]));

        $response->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('options.0.value', (string) $ccs->id)
            ->assertJsonPath('options.0.name', 'CCS Borrower');

        $ids = collect($response->json('options'))->pluck('value');

        $this->assertFalse($ids->contains((string) $cas->id));
        $this->assertFalse($ids->contains((string) $admin->id));
    }

    public function test_all_borrowers_at_division_level_means_all_matching_division_borrowers(): void
    {
        $ccs = $this->borrower('CCS Borrower', 'ccs.borrower@cspc.edu.ph');
        $cas = $this->borrower('CAS Borrower', 'cas.borrower@cspc.edu.ph');
        $admin = $this->borrower('Admin Borrower', 'admin.borrower@cspc.edu.ph');

        $this->request($ccs, 'ACADEMIC', 'College of Computer Studies');
        $this->request($cas, 'ACADEMIC', 'College of Arts and Sciences');
        $this->request($admin, 'ADMINISTRATION', 'Budget Office');

        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->getJson(route('reports.index', [
                'borrower_options' => 1,
                'report' => 'borrowing',
                'academic_period' => 'month',
                'division' => 'ACADEMIC',
            ]));

        $response->assertOk()->assertJsonPath('count', 2);

        $ids = collect($response->json('options'))->pluck('value');

        $this->assertTrue($ids->contains((string) $ccs->id));
        $this->assertTrue($ids->contains((string) $cas->id));
        $this->assertFalse($ids->contains((string) $admin->id));
    }

    public function test_borrower_options_support_scoped_name_or_email_search_and_are_capped(): void
    {
        $target = $this->borrower('Arianne Search Target', 'arianne.target@cspc.edu.ph');
        $other = $this->borrower('Different Borrower', 'different.borrower@cspc.edu.ph');

        $this->request($target, 'ACADEMIC', 'College of Computer Studies');
        $this->request($other, 'ACADEMIC', 'College of Computer Studies');

        $response = $this->actingAs($this->head)
            ->withSession(['active_workspace' => 'SPMU'])
            ->getJson(route('reports.index', [
                'borrower_options' => 1,
                'report' => 'borrowing',
                'academic_period' => 'month',
                'division' => 'ACADEMIC',
                'unit' => 'College of Computer Studies',
                'q' => 'arianne.target',
            ]));

        $response->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('shown', 1)
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('options.0.value', (string) $target->id)
            ->assertJsonPath('options.0.name', 'Arianne Search Target')
            ->assertJsonPath('options.0.email', 'arianne.target@cspc.edu.ph');

        $ids = collect($response->json('options'))->pluck('value');
        $this->assertFalse($ids->contains((string) $other->id));
    }

    private function borrower(string $name, string $email): User
    {
        return User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
            'full_name' => $name,
            'email' => $email,
        ]);
    }

    private function request(User $borrower, string $division, string $office): BorrowingRequest
    {
        $createdAt = now()->copy()->subDays(2);

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
            'purpose_event' => 'Borrower cascade fixture',
            'location' => 'Campus',
            'division_code' => $division,
            'office_unit' => $office,
            'schedule_date' => $createdAt->copy()->addDay()->toDateString(),
            'return_date' => $createdAt->copy()->addDays(3)->toDateString(),
            'needed_from' => $createdAt->copy()->addDay()->startOfDay(),
            'return_due_at' => $createdAt->copy()->addDays(3)->endOfDay(),
        ]);

        return $request;
    }
}
