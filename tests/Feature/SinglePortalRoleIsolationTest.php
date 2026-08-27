<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Models\OrganizationalUnit;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SinglePortalRoleIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_every_active_classification_has_exactly_one_portal_and_only_borrower_may_borrow(): void
    {
        $expectations = [
            AccessClassification::BorrowerOnly->value => ['BORROWER', true],
            AccessClassification::SpmuHead->value => ['SPMU', false],
            AccessClassification::SpmuOfficer->value => ['SPMU', false],
            AccessClassification::IctuMaintainer->value => ['ICTU', false],
        ];

        foreach ($expectations as $classification => [$portal, $mayBorrow]) {
            $user = User::query()
                ->where('access_classification', $classification)
                ->firstOrFail();

            $this->assertSame($portal, $user->primaryWorkspace());
            $this->assertSame([$portal], $user->allowedWorkspaces());
            $this->assertSame($mayBorrow, $user->mayBorrow());

            $activeRoles = $user->roles()
                ->wherePivotNull('revoked_at')
                ->get()
                ->map(fn (Role $role) => (string) $role->role_code)
                ->all();

            $this->assertSame([$portal], $activeRoles);
        }
    }

    public function test_portal_middleware_rejects_cross_portal_routes_and_restores_stale_context(): void
    {
        $borrower = $this->classificationUser(AccessClassification::BorrowerOnly);
        $spmuOfficer = $this->classificationUser(AccessClassification::SpmuOfficer);
        $ictu = $this->classificationUser(AccessClassification::IctuMaintainer);

        $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($spmuOfficer)
            ->get(route('requests.create'))
            ->assertForbidden();

        $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($ictu)
            ->get(route('requests.create'))
            ->assertForbidden();

        $this->actingAs($borrower)
            ->get(route('inventory.create'))
            ->assertForbidden();

        $this->actingAs($spmuOfficer)
            ->get(route('administration.users.index'))
            ->assertForbidden();

        $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($spmuOfficer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSessionHas('active_workspace', 'SPMU');
    }

    public function test_login_automatically_establishes_the_single_portal_without_a_chooser(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $expectations = [
            'borrower@spmu.test' => 'BORROWER',
            'spmu-head@spmu.test' => 'SPMU',
            'spmu@spmu.test' => 'SPMU',
            'ictu@spmu.test' => 'ICTU',
        ];

        foreach ($expectations as $email => $portal) {
            $this->post(route('login.store'), [
                'email' => $email,
                'password' => DemoUserSeeder::PASSWORD,
            ])
                ->assertRedirect(route('dashboard'))
                ->assertSessionHas('active_workspace', $portal);

            $this->post(route('logout'))
                ->assertRedirect(route('home'));
        }

        $this->assertFalse(Route::has('workspace.choose'));
        $this->assertFalse(Route::has('workspace.select'));
        $this->get('/workspace')->assertNotFound();
    }

    public function test_login_fails_safely_when_portal_classification_is_invalid(): void
    {
        $user = $this->classificationUser(AccessClassification::BorrowerOnly);

        DB::table('users')
            ->where('id', $user->id)
            ->update(['access_classification' => 'INVALID_CLASSIFICATION']);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => DemoUserSeeder::PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_administration_classification_change_revokes_obsolete_role_and_assigns_required_role(): void
    {
        $ictu = $this->classificationUser(AccessClassification::IctuMaintainer);
        $user = $this->classificationUser(AccessClassification::BorrowerOnly);
        $spmuUnit = OrganizationalUnit::query()
            ->where('unit_code', 'SPMU')
            ->firstOrFail();
        $borrowerRole = Role::query()
            ->where('role_code', 'BORROWER')
            ->firstOrFail();
        $spmuRole = Role::query()
            ->where('role_code', 'SPMU')
            ->firstOrFail();

        $this->actingAs($ictu)
            ->put(route('administration.users.update', $user), [
                'organizational_unit_id' => $spmuUnit->id,
                'employee_no' => $user->employee_no,
                'full_name' => $user->full_name,
                'designation' => $user->designation,
                'employment_type' => $user->employment_type->value,
                'email' => $user->email,
                'mobile_no' => $user->mobile_no,
                'account_status' => $user->account_status->value,
                'access_classification' => AccessClassification::SpmuOfficer->value,
            ])
            ->assertRedirect(route('administration.users.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('user_roles', [
            'user_id' => $user->id,
            'role_id' => $borrowerRole->id,
        ]);

        $this->assertFalse(
            DB::table('user_roles')
                ->where('user_id', $user->id)
                ->where('role_id', $borrowerRole->id)
                ->whereNull('revoked_at')
                ->exists(),
        );

        $this->assertTrue(
            DB::table('user_roles')
                ->where('user_id', $user->id)
                ->where('role_id', $spmuRole->id)
                ->whereNull('revoked_at')
                ->exists(),
        );

        $this->assertSame(['SPMU'], $user->fresh()->allowedWorkspaces());
        $this->assertFalse($user->fresh()->mayBorrow());
    }

    public function test_demo_seeder_is_idempotent_for_passwords_and_active_roles(): void
    {
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);
        $passwordHash = $officer->password;

        $this->seed(DemoUserSeeder::class);

        $officer->refresh();
        $this->assertSame($passwordHash, $officer->password);

        $activeRoles = $officer->roles()
            ->wherePivotNull('revoked_at')
            ->get()
            ->map(fn (Role $role) => (string) $role->role_code)
            ->all();

        $this->assertSame(['SPMU'], $activeRoles);
        $this->assertSame(
            1,
            DB::table('user_roles')
                ->where('user_id', $officer->id)
                ->whereNull('revoked_at')
                ->count(),
        );
    }


    public function test_user_administration_hides_retired_non_portal_accounts(): void
    {
        $ictu = $this->classificationUser(AccessClassification::IctuMaintainer);
        $institution = OrganizationalUnit::query()
            ->where('unit_code', 'CSPC')
            ->firstOrFail();

        User::factory()->create([
            'organizational_unit_id' => $institution->id,
            'employee_no' => 'LEGACY-GSU-'.uniqid(),
            'email' => 'legacy-gsu-'.uniqid().'@example.test',
            'full_name' => 'Legacy GSU Account',
            'access_classification' => AccessClassification::GsuHead,
            'account_status' => 'INACTIVE',
        ]);

        User::factory()->create([
            'organizational_unit_id' => $institution->id,
            'employee_no' => 'LEGACY-VPAF-'.uniqid(),
            'email' => 'legacy-vpaf-'.uniqid().'@example.test',
            'full_name' => 'Legacy VPAF Account',
            'access_classification' => AccessClassification::VpafHead,
            'account_status' => 'INACTIVE',
        ]);

        User::factory()->create([
            'organizational_unit_id' => $institution->id,
            'employee_no' => 'RETIRED-LAUNDRY-'.uniqid(),
            'email' => 'retired-laundry-'.uniqid().'@example.test',
            'full_name' => 'Retired Laundry Account',
            'access_classification' => AccessClassification::RetiredInactive,
            'account_status' => 'INACTIVE',
        ]);

        $this->actingAs($ictu)
            ->get(route('administration.users.index'))
            ->assertOk()
            ->assertDontSee('Retired Laundry Account')
            ->assertDontSee('Legacy GSU Account')
            ->assertDontSee('Legacy VPAF Account');
    }

    public function test_gsu_and_vpaf_are_not_assignable_or_active_system_portals(): void
    {
        $assignable = array_map(
            fn (AccessClassification $classification) => $classification->value,
            AccessClassification::assignableCases(),
        );

        $this->assertNotContains('GSU_HEAD', $assignable);
        $this->assertNotContains('VPAF_HEAD', $assignable);

        $this->assertFalse(AccessClassification::GsuHead->isPortalEnabled());
        $this->assertFalse(AccessClassification::VpafHead->isPortalEnabled());

        $this->assertSame([], AccessClassification::GsuHead->workspaces());
        $this->assertSame([], AccessClassification::VpafHead->workspaces());
    }

    private function classificationUser(AccessClassification $classification): User
    {
        return User::query()
            ->where('access_classification', $classification->value)
            ->firstOrFail();
    }
}
