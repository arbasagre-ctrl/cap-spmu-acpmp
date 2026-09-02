<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * Google sign-in proves identity; SPMU-ACPMP still decides access.
 *
 * Socialite is mocked throughout - no test reaches Google.
 */
class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => 'http://localhost/auth/google/callback',
            'services.google.allowed_domains' => '',
        ]);
    }

    /** Pretend Google returned this verified profile. */
    private function fakeGoogleUser(string $email, string $name = 'Google Person'): void
    {
        $socialiteUser = (new SocialiteUser)->map([
            'id' => '1234567890',
            'name' => $name,
            'email' => $email,
            'avatar' => 'https://example.test/avatar.png',
        ]);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    /* ------------------------------------------------------------------ */
    /* Local login is untouched                                            */
    /* ------------------------------------------------------------------ */

    public function test_local_email_and_password_login_still_works(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery-staple']);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-staple',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame('BORROWER', session('active_workspace'));
    }

    public function test_login_page_hides_the_google_button_when_oauth_is_not_configured(): void
    {
        config(['services.google.client_id' => '', 'services.google.client_secret' => '', 'services.google.redirect' => '']);

        $response = $this->get('/login');

        $response->assertOk()
            ->assertSee('CSPC Email Address')
            ->assertDontSee('Sign in with Google');
    }

    public function test_login_page_shows_the_google_button_when_configured(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Sign in with Google')
            ->assertSee('Keep me signed in');
    }

    /* ------------------------------------------------------------------ */
    /* Redirect                                                            */
    /* ------------------------------------------------------------------ */

    public function test_google_redirect_route_sends_the_visitor_to_google(): void
    {
        $this->get(route('auth.google.redirect'))
            ->assertRedirectContains('accounts.google.com');
    }

    public function test_google_redirect_is_refused_when_oauth_is_not_configured(): void
    {
        config(['services.google.client_id' => '', 'services.google.client_secret' => '', 'services.google.redirect' => '']);

        $this->get(route('auth.google.redirect'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /* ------------------------------------------------------------------ */
    /* Successful sign-in reuses the existing local account                */
    /* ------------------------------------------------------------------ */

    public function test_existing_active_borrower_signs_in_through_google(): void
    {
        $user = User::factory()->create([
            'email' => 'borrower.person@cspc.edu.ph',
            'access_classification' => AccessClassification::BorrowerOnly,
        ]);

        $this->fakeGoogleUser('borrower.person@cspc.edu.ph');

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame('BORROWER', session('active_workspace'));
        $this->assertSame(
            AccessClassification::BorrowerOnly,
            $user->fresh()->access_classification
        );
    }

    public function test_email_matching_is_case_insensitive_and_creates_no_second_account(): void
    {
        $user = User::factory()->create(['email' => 'mixed.case@cspc.edu.ph']);

        $this->fakeGoogleUser('Mixed.Case@CSPC.edu.ph');

        $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::query()->count());
        $this->assertSame('mixed.case@cspc.edu.ph', $user->fresh()->email);
    }

    public function test_spmu_action_officer_keeps_the_spmu_workspace(): void
    {
        $user = User::factory()->create([
            'email' => 'officer@cspc.edu.ph',
            'access_classification' => AccessClassification::SpmuOfficer,
        ]);

        $this->fakeGoogleUser('officer@cspc.edu.ph');

        $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame('SPMU', session('active_workspace'));
        $this->assertSame(
            AccessClassification::SpmuOfficer,
            $user->fresh()->access_classification
        );
    }

    public function test_spmu_head_keeps_the_spmu_workspace(): void
    {
        $user = User::factory()->create([
            'email' => 'head@cspc.edu.ph',
            'access_classification' => AccessClassification::SpmuHead,
        ]);

        $this->fakeGoogleUser('head@cspc.edu.ph');

        $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame('SPMU', session('active_workspace'));
        $this->assertSame(
            AccessClassification::SpmuHead,
            $user->fresh()->access_classification
        );
    }

    /* ------------------------------------------------------------------ */
    /* Rejections                                                          */
    /* ------------------------------------------------------------------ */

    public function test_unknown_google_email_is_rejected_and_provisions_nobody(): void
    {
        $this->fakeGoogleUser('student.not.registered@my.cspc.edu.ph');

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
        $this->assertDatabaseMissing('users', ['email' => 'student.not.registered@my.cspc.edu.ph']);
    }

    public function test_inactive_local_account_cannot_sign_in_through_google(): void
    {
        User::factory()->create([
            'email' => 'inactive@cspc.edu.ph',
            'account_status' => AccountStatus::Inactive,
        ]);

        $this->fakeGoogleUser('inactive@cspc.edu.ph');

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_suspended_local_account_cannot_sign_in_through_google(): void
    {
        User::factory()->create([
            'email' => 'suspended@cspc.edu.ph',
            'account_status' => AccountStatus::Suspended,
        ]);

        $this->fakeGoogleUser('suspended@cspc.edu.ph');

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_account_without_a_portal_classification_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'retired@cspc.edu.ph']);

        /* Written raw: the retired value is deliberately not portal-enabled. */
        User::query()->whereKey($user->id)->update([
            'access_classification' => AccessClassification::RetiredInactive->value,
        ]);

        $this->fakeGoogleUser('retired@cspc.edu.ph');

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_google_returning_no_email_is_rejected(): void
    {
        $this->fakeGoogleUser('');

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_cancelled_google_consent_returns_to_login(): void
    {
        $this->get(route('auth.google.callback', ['error' => 'access_denied']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /* ------------------------------------------------------------------ */
    /* Domain restriction is configuration, not hardcoded                  */
    /* ------------------------------------------------------------------ */

    public function test_allowed_domain_is_accepted_when_configured(): void
    {
        config(['services.google.allowed_domains' => 'cspc.edu.ph']);

        $user = User::factory()->create(['email' => 'staff@cspc.edu.ph']);

        $this->fakeGoogleUser('staff@cspc.edu.ph');

        $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_disallowed_domain_is_rejected_even_for_an_existing_user(): void
    {
        config(['services.google.allowed_domains' => 'cspc.edu.ph']);

        User::factory()->create(['email' => 'outsider@gmail.com']);

        $this->fakeGoogleUser('outsider@gmail.com');

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_blank_domain_configuration_imposes_no_domain_requirement(): void
    {
        config(['services.google.allowed_domains' => '']);

        $user = User::factory()->create(['email' => 'partner@example.org']);

        $this->fakeGoogleUser('partner@example.org');

        $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    /* ------------------------------------------------------------------ */
    /* Google never writes to the local record                             */
    /* ------------------------------------------------------------------ */

    public function test_google_profile_never_overwrites_local_account_data(): void
    {
        $unit = \App\Models\OrganizationalUnit::query()->create([
            'unit_code' => 'TEST-UNIT',
            'unit_name' => 'Test Unit',
            'unit_type' => 'OFFICE',
            'active' => true,
        ]);

        $user = User::factory()->create([
            'email' => 'protected@cspc.edu.ph',
            'full_name' => 'Local Recorded Name',
            'employee_no' => 'EMP-00042',
            'designation' => 'Property Custodian',
            'organizational_unit_id' => $unit->id,
            'access_classification' => AccessClassification::SpmuOfficer,
            'password' => 'local-password-value',
        ]);

        $originalPassword = $user->fresh()->password;

        $this->fakeGoogleUser('protected@cspc.edu.ph', 'Google Display Name');

        $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));

        $fresh = $user->fresh();

        $this->assertSame('Local Recorded Name', $fresh->full_name);
        $this->assertSame('EMP-00042', $fresh->employee_no);
        $this->assertSame('Property Custodian', $fresh->designation);
        $this->assertSame($unit->id, $fresh->organizational_unit_id);
        $this->assertSame(AccessClassification::SpmuOfficer, $fresh->access_classification);
        $this->assertSame($user->employment_type, $fresh->employment_type);
        $this->assertSame($originalPassword, $fresh->password);
    }

    public function test_google_sign_in_records_the_login_timestamp(): void
    {
        $user = User::factory()->create([
            'email' => 'stamped@cspc.edu.ph',
            'last_login_at' => null,
        ]);

        $this->fakeGoogleUser('stamped@cspc.edu.ph');

        $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));

        $this->assertNotNull($user->fresh()->last_login_at);
    }
}
