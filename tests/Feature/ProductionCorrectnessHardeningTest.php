<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Http\Controllers\UserAdministrationController;
use App\Models\BillingLine;
use App\Models\BillingStatement;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\UserRoleAssignmentService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class ProductionCorrectnessHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_historical_assignment_is_visible_and_survives_an_unrelated_ictu_update(): void
    {
        $ictu = $this->classificationUser(AccessClassification::IctuMaintainer);
        $borrower = $this->classificationUser(AccessClassification::BorrowerOnly);
        $historicalUnit = $this->historicalAcademicUnit();

        $borrower->forceFill([
            'organizational_unit_id' => $historicalUnit->id,
            'designation' => 'Faculty Member',
            'employment_status' => 'FULL_TIME',
        ])->save();

        DB::table('user_organizational_units')
            ->where('user_id', $borrower->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        DB::table('user_organizational_units')->insert([
            'user_id' => $borrower->id,
            'organizational_unit_id' => $historicalUnit->id,
            'assignment_type' => 'PRIMARY',
            'assigned_by_user_id' => $ictu->id,
            'assigned_at' => now(),
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($ictu)
            ->get(route('administration.users.edit', $borrower))
            ->assertOk()
            ->assertSee($historicalUnit->unit_name)
            ->assertSee('Historical / inactive assignment');

        $this->actingAs($ictu)
            ->put(
                route('administration.users.update', $borrower),
                $this->administrationPayload($borrower, [
                    'division_code' => OrganizationalUnit::DIVISION_ACADEMIC,
                    'organizational_unit_id' => $historicalUnit->id,
                    'mobile_no' => '09171234567',
                ]),
            )
            ->assertRedirect(route('administration.users.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'id' => $borrower->id,
            'organizational_unit_id' => $historicalUnit->id,
            'mobile_no' => '09171234567',
        ]);
        $this->assertDatabaseHas('user_organizational_units', [
            'user_id' => $borrower->id,
            'organizational_unit_id' => $historicalUnit->id,
            'assignment_type' => 'PRIMARY',
            'revoked_at' => null,
        ]);

        $this->actingAs($ictu)
            ->get(route('administration.users.create'))
            ->assertOk()
            ->assertDontSee($historicalUnit->unit_name);

        $this->actingAs($borrower)
            ->get(route('requests.create'))
            ->assertOk()
            ->assertSee('Your organizational assignment is historical or inactive. Contact ICTU to assign an active Office / College / Unit before filing a new request.');
    }

    public function test_ictu_cannot_change_their_own_account_status_or_access_classification_via_a_crafted_request(): void
    {
        $ictu = $this->classificationUser(AccessClassification::IctuMaintainer);

        $this->assertSame('ACTIVE', $ictu->account_status->value);
        $this->assertSame('ICTU_MAINTAINER', $ictu->access_classification->value);

        $this->actingAs($ictu)
            ->put(
                route('administration.users.update', $ictu),
                $this->administrationPayload($ictu, [
                    'division_code' => OrganizationalUnit::DIVISION_ADMINISTRATION,
                    'organizational_unit_id' => $ictu->organizational_unit_id,
                    /* The crafted, disallowed part of the request. */
                    'account_status' => 'SUSPENDED',
                    'access_classification' => AccessClassification::SpmuHead->value,
                    /* An unrelated field, submitted in the same request, which must still save. */
                    'mobile_no' => '09170000001',
                ]),
            )
            ->assertRedirect(route('administration.users.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'id' => $ictu->id,
            'account_status' => 'ACTIVE',
            'access_classification' => 'ICTU_MAINTAINER',
            'mobile_no' => '09170000001',
        ]);
    }

    public function test_user_administration_update_requires_ictu_even_when_called_without_route_middleware(): void
    {
        $spmuOfficer = $this->classificationUser(AccessClassification::SpmuOfficer);
        $borrower = $this->classificationUser(AccessClassification::BorrowerOnly);
        $request = Request::create('/administration/users/'.$borrower->id, 'PUT');
        $request->setUserResolver(fn (): User => $spmuOfficer);

        try {
            app(UserAdministrationController::class)->update(
                $request,
                $borrower,
                app(AuditService::class),
                app(UserRoleAssignmentService::class),
                app(NotificationService::class),
            );

            $this->fail('A non-ICTU user must not reach the User Administration update action.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_billing_line_source_key_prevents_duplicate_sources_but_keeps_historical_null_rows_and_distinct_cases(): void
    {
        $borrower = $this->classificationUser(AccessClassification::BorrowerOnly);
        $head = $this->classificationUser(AccessClassification::SpmuHead);
        $firstBilling = $this->billing($borrower, $head, 'BILL-HARDENING-001');
        $secondBilling = $this->billing($borrower, $head, 'BILL-HARDENING-002');

        BillingLine::query()->create([
            'billing_statement_id' => $firstBilling->id,
            'source_key' => 'INCIDENT:1001',
            'line_type' => 'PROPERTY_ACCOUNTABILITY_CHARGE',
            'description' => 'First incident charge',
            'amount' => 100,
        ]);

        try {
            BillingLine::query()->create([
                'billing_statement_id' => $secondBilling->id,
                'source_key' => 'INCIDENT:1001',
                'line_type' => 'PROPERTY_ACCOUNTABILITY_CHARGE',
                'description' => 'Duplicate incident charge',
                'amount' => 100,
            ]);

            $this->fail('The database must reject two billing lines for one source key.');
        } catch (QueryException) {
            // The named database uniqueness guarantee is the race-safe guard.
        }

        BillingLine::query()->create([
            'billing_statement_id' => $firstBilling->id,
            'source_key' => null,
            'line_type' => 'HISTORICAL_IMPORT',
            'description' => 'Historical row one',
            'amount' => 1,
        ]);
        BillingLine::query()->create([
            'billing_statement_id' => $secondBilling->id,
            'source_key' => null,
            'line_type' => 'HISTORICAL_IMPORT',
            'description' => 'Historical row two',
            'amount' => 1,
        ]);
        BillingLine::query()->create([
            'billing_statement_id' => $secondBilling->id,
            'source_key' => 'INCIDENT:1002',
            'line_type' => 'PROPERTY_ACCOUNTABILITY_CHARGE',
            'description' => 'Different incident charge',
            'amount' => 100,
        ]);

        $this->assertDatabaseCount('billing_lines', 4);
    }

    private function classificationUser(AccessClassification $classification): User
    {
        return User::query()
            ->where('access_classification', $classification->value)
            ->firstOrFail();
    }

    private function historicalAcademicUnit(): OrganizationalUnit
    {
        return OrganizationalUnit::query()->create([
            'parent_unit_id' => OrganizationalUnit::query()
                ->where('unit_code', OrganizationalUnit::DIVISION_ACADEMIC)
                ->value('id'),
            'unit_code' => 'HISTORICAL_ACADEMIC_UNIT',
            'unit_name' => 'Historical Academic Unit',
            'unit_type' => 'ACADEMIC_UNIT',
            'active' => false,
        ]);
    }

    /** @return array<string, mixed> */
    private function administrationPayload(User $user, array $overrides = []): array
    {
        return array_replace([
            'division_code' => OrganizationalUnit::DIVISION_ACADEMIC,
            'organizational_unit_id' => $user->organizational_unit_id,
            'employee_no' => $user->employee_no,
            'full_name' => $user->full_name,
            'designation' => $user->designation ?: 'Faculty Member',
            'employment_type' => $user->employment_type->value,
            'employment_status' => $user->employment_status ?: 'FULL_TIME',
            'email' => $user->email,
            'mobile_no' => $user->mobile_no,
            'account_status' => $user->account_status->value,
            'access_classification' => $user->access_classification->value,
        ], $overrides);
    }

    private function billing(User $borrower, User $head, string $billingNo): BillingStatement
    {
        return BillingStatement::query()->create([
            'billing_no' => $billingNo,
            'borrower_user_id' => $borrower->id,
            'responsible_spmu_user_id' => $head->id,
            'issued_at' => now(),
            'total_amount' => 100,
            'status' => 'ISSUED',
        ]);
    }
}
