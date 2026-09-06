<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Enums\AccountStatus;
use App\Enums\EmploymentType;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Services\AuditService;
use App\Services\UserRoleAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserAdministrationController extends Controller
{
    public function index(): View
    {
        $activeClassifications = array_map(
            fn (AccessClassification $classification) => $classification->value,
            AccessClassification::assignableCases(),
        );

        return view('administration.users.index', [
            'users' => User::with(['roles', 'organizationalUnit', 'authorizedOrganizationalUnits'])
                ->whereIn('access_classification', $activeClassifications)
                ->orderBy('full_name')
                ->get(),
        ]);
    }

    public function create(): View
    {
        return $this->form(new User);
    }

    public function edit(User $user): View
    {
        abort_unless(
            $user->access_classification?->isPortalEnabled(),
            404
        );

        return $this->form($user->load(['roles', 'organizationalUnit', 'authorizedOrganizationalUnits']));
    }

    public function store(
        Request $request,
        AuditService $audit,
        UserRoleAssignmentService $roleAssignments,
    ): RedirectResponse {
        $data = $this->validated($request);

        $user = DB::transaction(function () use ($data, $audit, $roleAssignments, $request): User {
            $classification = AccessClassification::from($data['access_classification']);
            $unit = $this->resolveOrganizationalUnit($data, $classification);

            $user = User::query()->create([
                'organizational_unit_id' => $unit->id,
                'employee_no' => $data['employee_no'],
                'full_name' => $data['full_name'],
                'designation' => $data['designation'] ?? null,
                'employment_type' => $data['employment_type'],
                'employment_status' => $data['employment_status'],
                'email' => strtolower($data['email']),
                'mobile_no' => $data['mobile_no'] ?? null,
                'notification_preferences' => [
                    'system' => true,
                    'email' => true,
                    'sms' => true,
                ],
                'account_status' => $data['account_status'],
                'access_classification' => $classification,
                'email_verified_at' => now(),
                'password' => $data['password'],
            ]);

            $this->synchronizeOrganizationalAssignments(
                $user,
                $classification,
                (int) $unit->id,
                $data['additional_organizational_unit_ids'] ?? [],
                $request->user()->id,
            );

            $roleAssignments->synchronize(
                $user,
                $classification,
                $request->user()->id,
            );

            $audit->record('USER_ACCOUNT_CREATED', $user, after: [
                'access_classification' => $classification->value,
                'portal' => $classification->primaryWorkspace()?->value,
                'employee_no' => $user->employee_no,
                'division_code' => $unit->divisionCode(),
                'organizational_unit_id' => $unit->id,
                'organizational_unit' => $unit->unit_name,
                'authorized_organizational_unit_ids' => $user->authorizedOrganizationalUnits()
                    ->pluck('organizational_units.id')
                    ->map(fn ($id) => (int) $id)
                    ->all(),
            ]);

            return $user;
        });

        return redirect()
            ->route('administration.users.index')
            ->with('status', "Account created for {$user->full_name}.");
    }

    public function update(
        Request $request,
        User $user,
        AuditService $audit,
        UserRoleAssignmentService $roleAssignments,
    ): RedirectResponse {
        $data = $this->validated($request, $user);

        DB::transaction(function () use ($data, $user, $audit, $roleAssignments, $request): void {
            $before = $user->load(['roles', 'organizationalUnit', 'authorizedOrganizationalUnits'])->toArray();
            $classification = AccessClassification::from($data['access_classification']);
            $unit = $this->resolveOrganizationalUnit($data, $classification);

            $updates = collect($data)
                ->except([
                    'password',
                    'division_code',
                    'new_organizational_unit_name',
                    'additional_organizational_unit_ids',
                ])
                ->all();

            $updates['organizational_unit_id'] = $unit->id;

            if (filled($data['password'] ?? null)) {
                $updates['password'] = $data['password'];
            }

            $user->update($updates);

            $this->synchronizeOrganizationalAssignments(
                $user,
                $classification,
                (int) $unit->id,
                $data['additional_organizational_unit_ids'] ?? [],
                $request->user()->id,
            );

            $roleAssignments->synchronize(
                $user,
                $classification,
                $request->user()->id,
            );

            $audit->record(
                'USER_ACCOUNT_UPDATED',
                $user,
                before: $before,
                after: $user->fresh(['roles', 'organizationalUnit', 'authorizedOrganizationalUnits'])->toArray(),
            );
        });

        return redirect()
            ->route('administration.users.index')
            ->with('status', 'Account and authorized organizational assignments updated with an audit record.');
    }

    private function form(User $user): View
    {
        $units = OrganizationalUnit::query()
            ->where('active', true)
            ->where('unit_code', '!=', 'LAUNDRY')
            ->whereIn('unit_type', [
                'ADMINISTRATIVE_UNIT',
                'ACADEMIC_UNIT',
                'RESEARCH_UNIT',
            ])
            ->orderBy('unit_name')
            ->get();

        $additionalUnitIds = $user->exists
            ? $user->authorizedOrganizationalUnits()
                ->where('organizational_units.id', '!=', $user->organizational_unit_id)
                ->pluck('organizational_units.id')
                ->map(fn ($id) => (int) $id)
                ->all()
            : [];

        return view('administration.users.form', [
            'user' => $user,
            'units' => $units,
            'additionalUnitIds' => $additionalUnitIds,
            'divisionOptions' => $this->divisionOptions(),
            'selectedDivisionCode' => old(
                'division_code',
                $user->organizationalUnit?->divisionCode() ?? ''
            ),
            'classifications' => AccessClassification::assignableCases(),
            'employmentTypes' => EmploymentType::cases(),
            'accountStatuses' => AccountStatus::cases(),
        ]);
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $allowedClassifications = array_map(
            fn (AccessClassification $classification) => $classification->value,
            AccessClassification::assignableCases(),
        );

        $data = $request->validate([
            'division_code' => [
                'required',
                Rule::in(array_keys($this->divisionOptions())),
            ],
            'organizational_unit_id' => ['nullable'],
            'new_organizational_unit_name' => ['nullable', 'string', 'max:255'],
            'additional_organizational_unit_ids' => ['nullable', 'array'],
            'additional_organizational_unit_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('organizational_units', 'id')
                    ->where(fn ($query) => $query
                        ->where('active', true)
                        ->where('unit_code', '!=', 'LAUNDRY')
                        ->whereIn('unit_type', [
                            'ADMINISTRATIVE_UNIT',
                            'ACADEMIC_UNIT',
                            'RESEARCH_UNIT',
                        ])),
            ],
            'employee_no' => [
                'required',
                'string',
                'max:80',
                Rule::unique('users')->ignore($user?->id),
            ],
            'full_name' => ['required', 'string', 'max:255'],
            'designation' => ['required', 'string', 'max:255'],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
            'employment_status' => [
                'required',
                Rule::in(['FULL_TIME', 'PART_TIME']),
            ],
            'email' => [
                'required',
                'email',
                Rule::unique('users')->ignore($user?->id),
            ],
            'mobile_no' => ['nullable', 'string', 'max:30'],
            'account_status' => ['required', Rule::enum(AccountStatus::class)],
            'access_classification' => [
                'required',
                Rule::in($allowedClassifications),
            ],
            'password' => [
                $user?->exists ? 'nullable' : 'required',
                'confirmed',
                Password::min(12)->letters()->numbers()->symbols(),
            ],
        ]);

        $classification = AccessClassification::from($data['access_classification']);

        if ($classification !== AccessClassification::BorrowerOnly
            && filled($data['new_organizational_unit_name'] ?? null)) {
            throw ValidationException::withMessages([
                'new_organizational_unit_name' => 'New Office / Unit entries may be added only for borrower organizational assignments.',
            ]);
        }

        if (! filled($data['new_organizational_unit_name'] ?? null)
            && ! ctype_digit((string) ($data['organizational_unit_id'] ?? ''))) {
            throw ValidationException::withMessages([
                'organizational_unit_id' => 'Select an Office / Unit or choose Other / Not listed to add one.',
            ]);
        }

        /*
         * Additional requesting-unit assignments are borrower-only.
         * Part-time status alone does not grant another Office / Unit; ICTU
         * must explicitly record every additional unit the borrower may represent.
         */
        if ($classification !== AccessClassification::BorrowerOnly) {
            $data['additional_organizational_unit_ids'] = [];
        }

        $data['additional_organizational_unit_ids'] = collect(
            $data['additional_organizational_unit_ids'] ?? []
        )
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        // “Borrower” is an access classification, not an official job designation.
        if ($classification === AccessClassification::BorrowerOnly
            && strcasecmp(trim((string) ($data['designation'] ?? '')), AccessClassification::BorrowerOnly->label()) === 0) {
            throw ValidationException::withMessages([
                'designation' => 'Enter the employee\'s official designation / position, not Borrower.',
            ]);
        }

        return $data;
    }

    private function resolveOrganizationalUnit(
        array $data,
        AccessClassification $classification,
    ): OrganizationalUnit {
        $divisionCode = strtoupper((string) $data['division_code']);
        $newName = trim((string) ($data['new_organizational_unit_name'] ?? ''));

        if ($newName !== '') {
            $existing = OrganizationalUnit::query()
                ->where('active', true)
                ->whereRaw('LOWER(unit_name) = ?', [mb_strtolower($newName)])
                ->first();

            if ($existing) {
                if ($existing->divisionCode() !== $divisionCode) {
                    throw ValidationException::withMessages([
                        'new_organizational_unit_name' => 'That Office / Unit already exists under a different division.',
                    ]);
                }

                $unit = $existing;
            } else {
                $institutionId = OrganizationalUnit::query()
                    ->where('unit_code', 'CSPC')
                    ->value('id');

                $unit = OrganizationalUnit::query()->create([
                    'parent_unit_id' => $institutionId,
                    'unit_code' => $this->uniqueUnitCode($newName),
                    'unit_name' => $newName,
                    'unit_type' => OrganizationalUnit::unitTypeForDivision($divisionCode),
                    'active' => true,
                ]);
            }
        } else {
            $unit = OrganizationalUnit::query()
                ->where('active', true)
                ->where('unit_code', '!=', 'LAUNDRY')
                ->find((int) $data['organizational_unit_id']);

            if (! $unit) {
                throw ValidationException::withMessages([
                    'organizational_unit_id' => 'Select a valid active Office / Unit.',
                ]);
            }

            if ($unit->divisionCode() !== $divisionCode) {
                throw ValidationException::withMessages([
                    'organizational_unit_id' => 'The selected Office / Unit does not belong to the selected Division.',
                ]);
            }
        }

        $expectedUnit = match ($classification) {
            AccessClassification::SpmuHead,
            AccessClassification::SpmuOfficer => 'SPMU',
            AccessClassification::IctuMaintainer => 'ICTU',
            AccessClassification::BorrowerOnly => null,
            default => null,
        };

        if ($expectedUnit && $unit->unit_code !== $expectedUnit) {
            throw ValidationException::withMessages([
                'access_classification' => "This access classification requires the {$expectedUnit} organizational unit.",
            ]);
        }

        return $unit;
    }

    /**
     * @param list<int|string> $additionalUnitIds
     */
    private function synchronizeOrganizationalAssignments(
        User $user,
        AccessClassification $classification,
        int $primaryUnitId,
        array $additionalUnitIds,
        int $assignedByUserId,
    ): void {
        $desired = collect(
            $classification === AccessClassification::BorrowerOnly
                ? $additionalUnitIds
                : []
        )
            ->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => $id === $primaryUnitId)
            ->push($primaryUnitId)
            ->unique()
            ->values();

        DB::table('user_organizational_units')
            ->where('user_id', $user->id)
            ->whereNotIn('organizational_unit_id', $desired->all())
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);

        foreach ($desired as $unitId) {
            DB::table('user_organizational_units')->updateOrInsert(
                [
                    'user_id' => $user->id,
                    'organizational_unit_id' => $unitId,
                ],
                [
                    'assignment_type' => $unitId === $primaryUnitId ? 'PRIMARY' : 'ADDITIONAL',
                    'assigned_by_user_id' => $assignedByUserId,
                    'assigned_at' => now(),
                    'revoked_at' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    /** @return array<string, string> */
    private function divisionOptions(): array
    {
        return [
            OrganizationalUnit::DIVISION_ADMINISTRATION => 'Administrative',
            OrganizationalUnit::DIVISION_ACADEMIC => 'Academic',
            OrganizationalUnit::DIVISION_RIC => 'Research, Innovation and Collaboration',
        ];
    }

    private function uniqueUnitCode(string $name): string
    {
        $base = Str::upper(Str::slug($name, '_'));
        $base = trim(substr($base !== '' ? $base : 'UNIT', 0, 34), '_');
        $candidate = $base;
        $counter = 2;

        while (OrganizationalUnit::query()->where('unit_code', $candidate)->exists()) {
            $suffix = '_'.$counter++;
            $candidate = substr($base, 0, 40 - strlen($suffix)).$suffix;
        }

        return $candidate;
    }
}
