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

            $settings = $this->actingAs($user)->get(route('profile.show'))
                ->assertOk()
                ->assertSee('Account Settings')
                ->assertSee('Contact Information')
                ->assertSee('Physical Signatures')
                ->assertSee('handwritten/wet signatures')
                ->assertDontSee('E-Signature')
                ->assertDontSee('Replacing it affects future actions only.');

            if ($classification === AccessClassification::BorrowerOnly) {
                $settings->assertSee('Borrower Number')->assertDontSee('Employee Number');
            } else {
                $settings->assertSee('Employee Number')->assertDontSee('Borrower Number');
            }
        }
    }

    public function test_borrower_account_settings_only_list_allowed_colleges_and_use_default_theme_label(): void
    {
        $borrower = $this->classificationUser(AccessClassification::BorrowerOnly);
        $allowed = [
            'College of Health and Sciences',
            'College of Engineering and Architecture',
            'College of Tourism, Hospitality and Business Management',
            'College of Computer Studies',
            'College of Arts and Sciences',
            'College of Technological Developmental Education',
        ];

        foreach ($allowed as $index => $name) {
            OrganizationalUnit::query()->firstOrCreate(
                ['unit_name' => $name],
                [
                    'unit_code' => 'COLLEGE-'.($index + 1),
                    'unit_type' => 'ACADEMIC_UNIT',
                    'active' => true,
                ],
            );
        }

        OrganizationalUnit::query()->firstOrCreate(
            ['unit_name' => 'Administrative Office'],
            ['unit_code' => 'ADMIN-1', 'unit_type' => 'ADMINISTRATIVE_UNIT', 'active' => true],
        );

        $response = $this->actingAs($borrower)->get(route('profile.show'));

        $response
            ->assertOk()
            ->assertSee('Default')
            ->assertDontSee('System')
            ->assertSee('Account Settings')
            ->assertDontSee('Review your account details, contact preferences, and e-signature.')
            ->assertSee('Borrower Number')
            ->assertDontSee('Administrative Office')
            ->assertSee('College of Health and Sciences')
            ->assertSee('College of Engineering and Architecture')
            ->assertSee('College of Tourism, Hospitality and Business Management')
            ->assertSee('College of Computer Studies')
            ->assertSee('College of Arts and Sciences')
            ->assertSee('College of Technological Developmental Education');

        /*
         * SPMU/ICTU and historical authority-unit labels may legitimately appear elsewhere in the shared
         * application shell (brand, unit name, help text, etc.). The security
         * rule is narrower: those authority units must not be selectable in the
         * Borrower's Office / Department dropdown.
         */
        $html = $response->getContent();

        preg_match(
            '/<select[^>]*name="organizational_unit_id"[^>]*>(.*?)<\/select>/si',
            $html,
            $matches
        );

        $this->assertArrayHasKey(
            1,
            $matches,
            'Borrower Office / Department dropdown was not rendered.'
        );

        $unitOptionsHtml = $matches[1];

        $this->assertStringNotContainsString(
            '>SPMU<',
            $unitOptionsHtml
        );

        $this->assertStringNotContainsString(
            '>GSU<',
            $unitOptionsHtml
        );

        $this->assertStringNotContainsString(
            '>VPAF<',
            $unitOptionsHtml
        );

        $this->assertStringNotContainsString(
            '>ICTU<',
            $unitOptionsHtml
        );

        $this->assertStringNotContainsString(
            'Administrative Office',
            $unitOptionsHtml
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

    public function test_e_signature_upload_route_is_removed_from_the_active_workflow(): void
    {
        $this->assertFalse(Route::has('profile.signature'));

        $user = $this->classificationUser(AccessClassification::SpmuOfficer);

        $this->actingAs($user)
            ->get(route('profile.show'))
            ->assertOk()
            ->assertSee('Physical Signatures')
            ->assertDontSee('E-Signature');
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
