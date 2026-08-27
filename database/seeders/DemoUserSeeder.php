<?php

namespace Database\Seeders;

use App\Enums\AccessClassification;
use App\Enums\AccountStatus;
use App\Enums\EmploymentType;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Services\UserRoleAssignmentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoUserSeeder extends Seeder
{
    public const PASSWORD = 'SPMU-Demo-2026!';

    public function run(UserRoleAssignmentService $roleAssignments): void
    {
        if (! config('app.seed_demo_users')) {
            return;
        }
        foreach ($this->accounts() as [$classification, $email, $employeeNo, $name, $unitCode, $employment]) {
            $unit = OrganizationalUnit::query()->where('unit_code', $unitCode)->firstOrFail();
            $user = User::query()->firstOrNew(['email' => $email]);
            $newAccount = ! $user->exists;
            $user->fill([
                'organizational_unit_id' => $unit->id,
                'employee_no' => $employeeNo,
                'full_name' => $name,
                'designation' => $classification->label(),
                'employment_type' => $employment,
                'mobile_no' => '09170000000',
                'notification_preferences' => ['system' => true, 'email' => true, 'sms' => true],
                'account_status' => AccountStatus::Active,
                'access_classification' => $classification,
            ]);
            if ($newAccount) {
                $user->email_verified_at = now();
                $user->password = Hash::make(self::PASSWORD);
            }
            $user->save();
            $roleAssignments->synchronize($user, $classification);
        }
    }

    private function accounts(): array
    {
        return [
            [AccessClassification::BorrowerOnly, 'borrower@spmu.test', 'DEMO-BORROWER', 'Borrower Demo', 'CSPC', EmploymentType::Faculty],
            [AccessClassification::SpmuOfficer, 'spmu@spmu.test', 'DEMO-SPMU', 'SPMU Action Officer Demo', 'SPMU', EmploymentType::Staff],
            [AccessClassification::SpmuHead, 'spmu-head@spmu.test', 'DEMO-SPMU-HEAD', 'SPMU Head Demo', 'SPMU', EmploymentType::Employee],
            [AccessClassification::IctuMaintainer, 'ictu@spmu.test', 'DEMO-ICTU', 'ICTU Maintainer Demo', 'ICTU', EmploymentType::Staff],
        ];
    }
}
