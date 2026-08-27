<?php

namespace App\Console\Commands;

use App\Enums\AccessClassification;
use App\Enums\AccountStatus;
use App\Enums\EmploymentType;
use App\Enums\UserRole;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Services\UserRoleAssignmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class CreateSystemUser extends Command
{
    protected $signature = 'spmu:user
        {email : Institutional email address}
        {--name= : Full name}
        {--employee-no= : Employee / borrower number}
        {--employment-type=STAFF : EMPLOYEE, FACULTY, or STAFF}
        {--unit= : Organizational unit code; inferred for staff roles when omitted}
        {--role=BORROWER : BORROWER, SPMU, or ICTU}
        {--classification= : BORROWER_ONLY, SPMU_HEAD, SPMU_OFFICER, or ICTU_MAINTAINER}
        {--password= : Password; prompted securely when omitted}';

    protected $description = 'Create or update an authorized SPMU-ACPMP account';

    public function handle(UserRoleAssignmentService $roleAssignments): int
    {
        $employmentType = EmploymentType::tryFrom(
            Str::upper((string) $this->option('employment-type'))
        );

        $roleCode = UserRole::tryFrom(
            Str::upper((string) $this->option('role'))
        );

        $activeRoles = [
            UserRole::Borrower,
            UserRole::Spmu,
            UserRole::Ictu,
        ];

        if (
            ! $employmentType
            || ! $roleCode
            || ! in_array($roleCode, $activeRoles, true)
        ) {
            $this->error(
                'Employment type or active role is invalid. '
                .'Allowed roles: BORROWER, SPMU, ICTU.'
            );

            return SymfonyCommand::INVALID;
        }

        $requestedClassification = Str::upper(
            trim((string) $this->option('classification'))
        );

        $classification = $requestedClassification !== ''
            ? AccessClassification::tryFrom($requestedClassification)
            : match ($roleCode) {
                UserRole::Spmu => AccessClassification::SpmuOfficer,
                UserRole::Ictu => AccessClassification::IctuMaintainer,
                default => AccessClassification::BorrowerOnly,
            };

        if (
            ! $classification
            || ! $classification->isPortalEnabled()
            || ! in_array($roleCode, $classification->roles(), true)
        ) {
            $this->error(
                'The requested classification is invalid or does not match '
                .'the selected active role.'
            );

            return SymfonyCommand::INVALID;
        }

        $unitCode = Str::upper(
            trim((string) $this->option('unit'))
        );

        if ($unitCode === '') {
            $unitCode = match ($classification) {
                AccessClassification::SpmuHead,
                AccessClassification::SpmuOfficer => 'SPMU',
                AccessClassification::IctuMaintainer => 'ICTU',
                default => 'CCS',
            };
        }

        $email = Str::lower(
            trim((string) $this->argument('email'))
        );

        $employeeNo = trim(
            (string) (
                $this->option('employee-no')
                ?: 'PENDING-'.Str::upper(Str::random(8))
            )
        );

        $name = trim(
            (string) (
                $this->option('name')
                ?: $email
            )
        );

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Enter a valid institutional email address.');

            return SymfonyCommand::INVALID;
        }

        $unit = OrganizationalUnit::query()
            ->where('unit_code', $unitCode)
            ->where('active', true)
            ->first();

        if (! $unit) {
            $this->error(
                "Organizational unit {$unitCode} is unavailable. "
                .'Run the database seeders or choose an active unit.'
            );

            return SymfonyCommand::FAILURE;
        }

        $employeeNumberOwner = User::query()
            ->where('employee_no', $employeeNo)
            ->first();

        if (
            $employeeNumberOwner
            && $employeeNumberOwner->email !== $email
        ) {
            $this->error(
                "Employee number {$employeeNo} already belongs to "
                ."{$employeeNumberOwner->email}. Use that existing email "
                .'or provide another real employee number.'
            );

            return SymfonyCommand::INVALID;
        }

        $emailOwner = User::query()
            ->where('email', $email)
            ->first();

        if (
            $emailOwner
            && $emailOwner->employee_no !== $employeeNo
        ) {
            $this->error(
                "Email {$email} already belongs to employee number "
                ."{$emailOwner->employee_no}. Reuse that number when updating "
                .'this account.'
            );

            return SymfonyCommand::INVALID;
        }

        $password = (string) (
            $this->option('password')
            ?: $this->secret('Password (minimum 12 characters)')
        );

        if (
            mb_strlen($password) < 12
            || ! preg_match('/[A-Z]/', $password)
            || ! preg_match('/[a-z]/', $password)
            || ! preg_match('/[0-9]/', $password)
            || ! preg_match('/[^A-Za-z0-9]/', $password)
        ) {
            $this->error(
                'The password must contain at least 12 characters, including '
                .'uppercase, lowercase, number, and symbol.'
            );

            return SymfonyCommand::INVALID;
        }

        $user = DB::transaction(
            function () use (
                $email,
                $employeeNo,
                $employmentType,
                $name,
                $password,
                $classification,
                $unit,
                $roleAssignments
            ): User {
                $user = User::query()->updateOrCreate(
                    ['email' => $email],
                    [
                        'organizational_unit_id' => $unit->id,
                        'employee_no' => $employeeNo,
                        'full_name' => $name,
                        'employment_type' => $employmentType,
                        'account_status' => AccountStatus::Active,
                        'access_classification' => $classification,
                        'password' => Hash::make($password),
                        'notification_preferences' => [
                            'system' => true,
                            'email' => true,
                            'sms' => false,
                        ],
                    ],
                );

                $roleAssignments->synchronize(
                    $user,
                    $classification
                );

                return $user;
            }
        );

        $this->info(
            "Account ready for {$user->full_name} as "
            ."{$classification->label()}."
        );

        return SymfonyCommand::SUCCESS;
    }
}
