<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\AccountStatus;
use App\Models\OrganizationalUnit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AccountSettingsRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_shared_account_menu_and_settings_render_for_every_normal_classification(): void
    {
        foreach (AccessClassification::assignableCases() as $classification) {
            $user = $this->classificationUser($classification);

            $dashboard = $this->actingAs($user)->get(route('dashboard'));

            $dashboard->assertOk();

            $dashboard
                ->assertSee('data-account-menu', false)
                ->assertSee('data-account-menu-toggle', false)
                ->assertSee($user->full_name)
                ->assertSee($classification->label())
                ->assertSee('Account Settings')
                ->assertSee('Log out')
                ->assertDontSee('View profile')
                ->assertDontSee('Sign out');

            foreach (['Borrower Portal', 'SPMU Operations', 'GSU Approval', 'VPAF Approval', 'Current Workspace', 'You are using'] as $obsoleteLabel) {
                $dashboard->assertDontSee($obsoleteLabel);
            }

            /*
             * Account Settings shows one unified "Borrower / Employee Number"
             * field for every classification (no separate labels), and the
             * E-signature registration section is a real, active feature
             * used to authorize SPMU verify/approve/decide actions - it was
             * never removed, so it must be visible here.
             */
            $this->actingAs($user)->get(route('profile.show'))
                ->assertOk()
                ->assertSee('Account Settings')
                ->assertSee('Account Information')
                ->assertSee('Borrower / Employee Number')
                ->assertSee('E-signature');
        }
    }

    public function test_borrower_account_settings_only_list_allowed_colleges_and_use_default_theme_label(): void
    {
        $borrower = $this->classificationUser(AccessClassification::BorrowerOnly);

        $response = $this->actingAs($borrower)->get(route('profile.show'));

        $response
            ->assertOk()
            ->assertSee('Default')
            ->assertDontSee('System')
            ->assertSee('Account Settings')
            ->assertSee('Borrower / Employee Number')
            ->assertSee($borrower->organizationalUnit->unit_name);

        /*
         * Office / College / Unit assignment is administered exclusively by
         * ICTU through User Administration (UserAdministrationController);
         * Account Settings only displays it read-only. There is no
         * self-service <select> a borrower could use to reassign themselves
         * into an SPMU/GSU/VPAF/ICTU authority unit.
         */
        $this->assertStringNotContainsString(
            'name="organizational_unit_id"',
            $response->getContent()
        );
    }

    public function test_account_settings_updates_contact_preferences_without_changing_borrower_identity_or_authority_fields(): void
    {
        $borrower = $this->classificationUser(AccessClassification::BorrowerOnly);
        $authorityUnit = OrganizationalUnit::query()->where('unit_code', 'SPMU')->firstOrFail();
        $beforeIdentity = $borrower->only([
            'employee_no',
            'organizational_unit_id',
            'access_classification',
            'account_status',
            'full_name',
            'designation',
            'email',
            'employment_type',
        ]);

        $this->actingAs($borrower)->put(route('profile.update'), [
            'mobile_no' => '09171234567',
            'system_notifications' => '0',
            'email_notifications' => '1',
            'sms_notifications' => '1',
            'employee_no' => 'BORROWER-2026-001',
            'organizational_unit_id' => $authorityUnit->id,
            'access_classification' => AccessClassification::IctuMaintainer->value,
            'account_status' => AccountStatus::Inactive->value,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $borrower->refresh();
        $this->assertSame($beforeIdentity, $borrower->only(array_keys($beforeIdentity)));
        $this->assertSame('09171234567', $borrower->mobile_no);
        $this->assertSame([
            'system' => false,
            'email' => true,
            'sms' => true,
        ], $borrower->notification_preferences);
    }

    public function test_staff_cannot_self_modify_authority_sensitive_account_fields(): void
    {
        foreach (array_filter(AccessClassification::assignableCases(), fn ($classification) => $classification !== AccessClassification::BorrowerOnly) as $classification) {
            $user = $this->classificationUser($classification);
            $before = $user->only(['employee_no', 'organizational_unit_id', 'access_classification', 'account_status', 'email', 'employment_type']);
            $differentUnit = OrganizationalUnit::query()->whereKeyNot($user->organizational_unit_id)->firstOrFail();

            $this->actingAs($user)->put(route('profile.update'), $this->profilePayload($user) + [
                'employee_no' => 'SELF-CHANGED-'.$user->id,
                'organizational_unit_id' => $differentUnit->id,
                'access_classification' => AccessClassification::BorrowerOnly->value,
                'account_status' => AccountStatus::Inactive->value,
                'email' => 'changed-'.$user->id.'@example.test',
                'employment_type' => 'FACULTY',
            ])->assertRedirect()->assertSessionHasNoErrors();

            $this->assertSame($before, $user->fresh()->only(array_keys($before)));
        }
    }

    public function test_e_signature_upload_route_powers_the_spmu_decision_workflow(): void
    {
        /*
         * E-signature registration was never removed: SpmuDocumentVerificationTest
         * confirms the verify/approve/decide actions require a current
         * E-signature ($hasCurrentESignature), linking here via "Register
         * your E-signature in Account Settings".
         */
        $this->assertTrue(Route::has('profile.signature'));

        $user = $this->classificationUser(AccessClassification::SpmuOfficer);

        $this->actingAs($user)
            ->get(route('profile.show'))
            ->assertOk()
            ->assertSee('E-signature')
            ->assertSee('Register E-signature');
    }

    public function test_logout_route_remains_post_only(): void
    {
        $this->assertSame(['POST'], Route::getRoutes()->getByName('logout')->methods());
    }

    /** @return array<string, mixed> */
    private function profilePayload(User $user): array
    {
        return [
            'mobile_no' => $user->mobile_no,
            'system_notifications' => '1',
            'email_notifications' => '1',
            'sms_notifications' => '0',
        ];
    }

    private function classificationUser(AccessClassification $classification): User
    {
        return User::query()->where('access_classification', $classification->value)->firstOrFail();
    }
}
