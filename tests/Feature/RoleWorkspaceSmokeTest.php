<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleWorkspaceSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_borrower_workspace_pages_render(): void
    {
        $this->assertPagesRender(UserRole::Borrower, [
            '/dashboard',
            '/profile',
            '/notifications',
            '/inventory',
            '/calendar',
            '/requests',
            '/requests/create',
            '/custody',
            '/accountability',
        ]);
    }

    public function test_spmu_workspace_pages_render(): void
    {
        $this->assertPagesRender(UserRole::Spmu, [
            '/dashboard',
            '/profile',
            '/notifications',
            '/inventory',
            '/inventory/create',
            '/calendar',
            '/requests',
            '/approvals',
            '/custody',
            '/accountability',
            '/reports',
            '/reports/audit',
            '/reports/notifications',
            '/administration',
            '/administration/settings',
        ]);
    }

    public function test_ictu_workspace_pages_render(): void
    {
        $this->assertPagesRender(UserRole::Ictu, [
            '/dashboard',
            '/profile',
            '/notifications',
            '/reports/audit',
            '/reports/notifications',
            '/administration',
            '/administration/settings',
            '/administration/users',
            '/administration/users/create',
            '/administration/delegations',
        ]);
    }

    public function test_each_active_workspace_has_a_focused_role_specific_menu(): void
    {
        $expectations = [
            UserRole::Borrower->value => [
                'see' => ['Available Items', 'My Requests', 'My Borrowings', 'My Obligations'],
                'hide' => ['For Approval', 'User Accounts', 'Laundry Operations'],
            ],
            UserRole::Spmu->value => [
                'see' => ['For Approval', 'Inventory Overview', 'Release & Return Oversight', 'Analytics', 'Reports', 'Operational Configuration'],
                'hide' => ['User Accounts', 'Audit Trail', 'Delivery Records', 'Laundry Operations'],
            ],
            UserRole::Ictu->value => [
                'see' => ['User Accounts', 'System Settings', 'Audit Trail', 'Delivery Records'],
                'hide' => ['Borrowing Calendar', 'For Approval', 'Laundry Operations'],
            ],
        ];

        foreach ($expectations as $roleCode => $labels) {
            $role = UserRole::from($roleCode);
            $user = $this->workspaceUser($role);

            $response = $this->withSession(['active_workspace' => $role->value])
                ->actingAs($user)
                ->get('/dashboard')
                ->assertOk();

            foreach ($labels['see'] as $label) {
                $response->assertSee($label);
            }

            foreach ($labels['hide'] as $label) {
                $response->assertDontSee($label);
            }
        }

        $actionOfficer = User::query()
            ->where('access_classification', AccessClassification::SpmuOfficer->value)
            ->firstOrFail();

        $this->withSession(['active_workspace' => UserRole::Spmu->value])
            ->actingAs($actionOfficer)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Laundry Operations')
            ->assertDontSee('User Accounts');
    }

    /** @param array<int, string> $paths */
    private function assertPagesRender(UserRole $role, array $paths): void
    {
        $user = $this->workspaceUser($role);

        foreach ($paths as $path) {
            $this->withSession(['active_workspace' => $role->value])
                ->actingAs($user)
                ->get($path)
                ->assertOk();
        }
    }

    private function workspaceUser(UserRole $role): User
    {
        return User::query()
            ->whereHas(
                'roles',
                fn ($query) => $query
                    ->where('role_code', $role->value)
                    ->whereNull('user_roles.revoked_at'),
            )
            ->when(
                $role === UserRole::Spmu,
                fn ($query) => $query->where(
                    'access_classification',
                    AccessClassification::SpmuHead->value,
                ),
            )
            ->firstOrFail();
    }
}
