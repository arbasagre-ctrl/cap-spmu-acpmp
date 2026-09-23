<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Enums\AccountStatus;
use App\Enums\EmploymentType;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\UserRoleAssignmentService;
use App\Support\OrganizationalStructure;
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
        $this->authorizeIctu($request);

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
        NotificationService $notifications,
    ): RedirectResponse {
        $this->authorizeIctu($request);

        $data = $this->validated($request, $user);

        /*
         * The form disables these two controls and submits hidden inputs
         * when ICTU is editing their own account, but a disabled control is
         * a client-side convenience only. The server must not trust the
         * submitted value for the acting user's own account_status or
         * access_classification; it always keeps what is already stored,
         * regardless of what a crafted request sends.
         */
        if ($request->user()->id === $user->id) {
            $data['account_status'] = $user->account_status->value;
            $data['access_classification'] = $user->access_classification->value;
        }

        $accountDisabled = false;

        DB::transaction(function () use ($data, $user, $audit, $roleAssignments, $request, &$accountDisabled): void {
            $before = $user->load(['roles', 'organizationalUnit', 'authorizedOrganizationalUnits'])->toArray();
            $beforeAccountStatus = $user->account_status?->value;
            $classification = AccessClassification::from($data['access_classification']);
            $unit = $this->resolveOrganizationalUnit($data, $classification, $user);

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

            $accountDisabled = $beforeAccountStatus === AccountStatus::Active->value
                && in_array($user->account_status?->value, [AccountStatus::Inactive->value, AccountStatus::Suspended->value], true);

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

        if ($accountDisabled) {
            $user->refresh();
            $notifications->send(
                'ACCOUNT_ACCESS_DISABLED',
                collect([$user]),
                'Your account access has been disabled. Contact ICTU for assistance.',
                $user,
                ['SYSTEM', 'EMAIL']
            );
        }

        return redirect()
            ->route('administration.users.index')
            ->with('status', 'Account and authorized organizational assignments updated with an audit record.');
    }

    private function form(User $user): View
    {
        $units = OrganizationalUnit::query()
            ->activeSelectable()
            ->orderBy('unit_name')
            ->get();

        $historicalPrimaryUnit = $user->exists
            && $user->organizationalUnit
            && ! $user->organizationalUnit->isSelectable()
                ? $user->organizationalUnit
                : null;

        $additionalUnits = $user->exists
            ? $user->authorizedOrganizationalUnits()
                ->where('organizational_units.id', '!=', $user->organizational_unit_id)
                ->get()
            : collect();

        $additionalUnitIds = $additionalUnits
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $historicalAdditionalUnits = $additionalUnits
            ->reject(fn (OrganizationalUnit $unit) => $unit->isSelectable())
            ->values();

        return view('administration.users.form', [
            'user' => $user,
            'units' => $units,
            'additionalUnitIds' => $additionalUnitIds,
            'historicalPrimaryUnit' => $historicalPrimaryUnit,
            'historicalAdditionalUnits' => $historicalAdditionalUnits,
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

        $retainsHistoricalPrimary = $this->retainsHistoricalPrimary(
            $user,
            $request->input('organizational_unit_id'),
            $request->input('new_organizational_unit_name'),
            $request->input('access_classification'),
        );
        $historicalAdditionalUnitIds = $this->historicalAdditionalUnitIds($user);

        $data = $request->validate([
            'division_code' => [
                $retainsHistoricalPrimary ? 'nullable' : 'required',
                Rule::in(array_keys($this->divisionOptions())),
            ],
            'organizational_unit_id' => ['nullable'],
            'new_organizational_unit_name' => ['nullable', 'string', 'max:255'],
            'additional_organizational_unit_ids' => ['nullable', 'array'],
            'additional_organizational_unit_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('organizational_units', 'id')
                    ->where(function ($query) use ($historicalAdditionalUnitIds): void {
                        $query->where(function ($activeSelectable): void {
                            $activeSelectable
                                ->where('active', true)
                                ->whereIn('unit_type', OrganizationalUnit::selectableUnitTypes());
                        });

                        if ($historicalAdditionalUnitIds !== []) {
                            $query->orWhereIn('id', $historicalAdditionalUnitIds);
                        }
                    }),
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
                'new_organizational_unit_name' => 'New Office / College / Unit entries may be added only for borrower organizational assignments.',
            ]);
        }

        if (! filled($data['new_organizational_unit_name'] ?? null)
            && ! ctype_digit((string) ($data['organizational_unit_id'] ?? ''))) {
            throw ValidationException::withMessages([
                'organizational_unit_id' => 'Select an Office / College / Unit or choose Other / Not listed to add one.',
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
        ?User $existingUser = null,
    ): OrganizationalUnit {
        if ($this->retainsHistoricalPrimary(
            $existingUser,
            $data['organizational_unit_id'] ?? null,
            $data['new_organizational_unit_name'] ?? null,
            $classification->value,
        )) {
            return $existingUser->organizationalUnit;
        }

        $divisionCode = strtoupper((string) $data['division_code']);
        $newName = trim((string) ($data['new_organizational_unit_name'] ?? ''));

        if ($newName !== '') {
            $existing = OrganizationalUnit::query()
                ->activeSelectable()
                ->whereRaw('LOWER(unit_name) = ?', [mb_strtolower($newName)])
                ->first();

            if ($existing) {
                if ($existing->divisionCode() !== $divisionCode) {
                    throw ValidationException::withMessages([
                        'new_organizational_unit_name' => 'That Office / College / Unit already exists under a different organizational classification.',
                    ]);
                }

                $unit = $existing;
            } else {
                $classificationId = OrganizationalUnit::query()
                    ->where('unit_code', $divisionCode)
                    ->where('unit_type', OrganizationalUnit::TYPE_CLASSIFICATION)
                    ->where('active', true)
                    ->value('id');

                $unit = OrganizationalUnit::query()->create([
                    'parent_unit_id' => $classificationId,
                    'unit_code' => $this->uniqueUnitCode($newName),
                    'unit_name' => $newName,
                    'unit_type' => OrganizationalUnit::unitTypeForDivision($divisionCode),
                    'active' => true,
                ]);
            }
        } else {
            $unit = OrganizationalUnit::query()
                ->activeSelectable()
                ->find((int) $data['organizational_unit_id']);

            if (! $unit) {
                throw ValidationException::withMessages([
                    'organizational_unit_id' => 'Select a valid active Office / College / Unit.',
                ]);
            }

            if ($unit->divisionCode() !== $divisionCode) {
                throw ValidationException::withMessages([
                    'organizational_unit_id' => 'The selected Office / College / Unit does not belong to the selected Organizational Classification.',
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

    /** @return list<int> */
    private function historicalAdditionalUnitIds(?User $user): array
    {
        if (! $user?->exists) {
            return [];
        }

        return $user->authorizedOrganizationalUnits()
            ->where('organizational_units.id', '!=', $user->organizational_unit_id)
            ->get()
            ->reject(fn (OrganizationalUnit $unit) => $unit->isSelectable())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function retainsHistoricalPrimary(
        ?User $user,
        mixed $selectedUnitId,
        mixed $newUnitName,
        mixed $classification,
    ): bool {
        if (! $user?->exists || filled($newUnitName)) {
            return false;
        }

        $user->loadMissing('organizationalUnit');
        $currentUnit = $user->organizationalUnit;

        return $currentUnit !== null
            && ! $currentUnit->isSelectable()
            && ctype_digit((string) $selectedUnitId)
            && (int) $selectedUnitId === (int) $currentUnit->id
            && (string) $classification === $user->access_classification?->value;
    }

    private function authorizeIctu(Request $request): void
    {
        abort_unless(
            $request->user()?->access_classification === AccessClassification::IctuMaintainer,
            403,
            'Only ICTU may administer user accounts.'
        );
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
        return OrganizationalStructure::divisions();
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
