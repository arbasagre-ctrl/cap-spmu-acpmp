<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_active_employee_can_sign_in(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery-staple']);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-staple',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_employee_cannot_sign_in(): void
    {
        $user = User::factory()->create([
            'account_status' => AccountStatus::Inactive,
            'password' => 'correct-horse-battery-staple',
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-staple',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_secure_command_creates_an_authorized_system_user(): void
    {
        $this->artisan('spmu:user', [
            'email' => 'ictu.admin@cspc.edu.ph',
            '--name' => 'ICTU Administrator',
            '--employee-no' => 'EMP-0001',
            '--unit' => 'ICTU',
            '--role' => 'ICTU',
            '--password' => 'Test-Only-Secure-2026!',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'ictu.admin@cspc.edu.ph')->firstOrFail();

        $this->assertSame('EMP-0001', $user->employee_no);
        $this->assertTrue($user->hasRole(UserRole::Ictu));
    }

    public function test_user_command_explains_a_duplicate_employee_number(): void
    {
        User::factory()->create([
            'employee_no' => 'EMP-0001',
            'email' => 'existing@cspc.edu.ph',
        ]);

        $this->artisan('spmu:user', [
            'email' => 'another@cspc.edu.ph',
            '--name' => 'Another User',
            '--employee-no' => 'EMP-0001',
            '--unit' => 'ICTU',
            '--role' => 'ICTU',
            '--password' => 'Test-Only-Secure-2026!',
        ])
            ->expectsOutputToContain('Employee number EMP-0001 already belongs to existing@cspc.edu.ph')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'another@cspc.edu.ph']);
    }

    public function test_borrower_cannot_open_administration(): void
    {
        $user = $this->userWithRole(UserRole::Borrower);

        $this->actingAs($user)->get('/administration')->assertForbidden();
    }

    public function test_ictu_user_can_open_administration(): void
    {
        $user = $this->userWithRole(UserRole::Ictu);

        $this->actingAs($user)
            ->get('/administration')
            ->assertOk()
            ->assertSee('System administration');
    }

    private function userWithRole(UserRole $roleCode): User
    {
        $classification = match ($roleCode) {
            UserRole::Borrower => AccessClassification::BorrowerOnly,
            UserRole::Spmu => AccessClassification::SpmuOfficer,
            UserRole::Ictu => AccessClassification::IctuMaintainer,
            UserRole::Gsu, UserRole::Vpaf => throw new \InvalidArgumentException(
                'Retired GSU/VPAF roles cannot be created as active test users.'
            ),
        };
        $user = User::factory()->create(['access_classification' => $classification]);
        $role = Role::query()->where('role_code', $roleCode->value)->firstOrFail();

        $user->roles()->attach($role->id, ['assigned_at' => now()]);

        return $user;
    }
}
