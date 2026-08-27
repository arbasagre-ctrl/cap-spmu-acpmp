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
            'users' => User::with(['roles', 'organizationalUnit'])
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

        return $this->form($user->load('roles'));
    }

    public function store(
        Request $request,
        AuditService $audit,
        UserRoleAssignmentService $roleAssignments,
    ): RedirectResponse {
        $data = $this->validated($request);

        $user = DB::transaction(function () use ($data, $audit, $roleAssignments, $request): User {
            $classification = AccessClassification::from($data['access_classification']);

            $user = User::query()->create([
                'organizational_unit_id' => $data['organizational_unit_id'],
                'employee_no' => $data['employee_no'],
                'full_name' => $data['full_name'],
                'designation' => $data['designation'] ?? null,
                'employment_type' => $data['employment_type'],
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

            $roleAssignments->synchronize(
                $user,
                $classification,
                $request->user()->id,
            );

            $audit->record('USER_ACCOUNT_CREATED', $user, after: [
                'access_classification' => $classification->value,
                'portal' => $classification->primaryWorkspace()?->value,
                'employee_no' => $user->employee_no,
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
            $before = $user->load('roles')->toArray();
            $classification = AccessClassification::from($data['access_classification']);

            $updates = collect($data)->except(['password'])->all();

            if (filled($data['password'] ?? null)) {
                $updates['password'] = $data['password'];
            }

            $user->update($updates);

            $roleAssignments->synchronize(
                $user,
                $classification,
                $request->user()->id,
            );

            $audit->record(
                'USER_ACCOUNT_UPDATED',
                $user,
                before: $before,
                after: $user->fresh('roles')->toArray(),
            );
        });

        return redirect()
            ->route('administration.users.index')
            ->with('status', 'Account and portal classification updated with an audit record.');
    }

    private function form(User $user): View
    {
        return view('administration.users.form', [
            'user' => $user,
            'units' => OrganizationalUnit::where('active', true)
                ->where('unit_code', '!=', 'LAUNDRY')
                ->orderBy('unit_name')
                ->get(),
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
            'organizational_unit_id' => [
                'required',
                Rule::exists('organizational_units', 'id')
                    ->where(fn ($query) => $query
                        ->where('active', true)
                        ->where('unit_code', '!=', 'LAUNDRY')),
            ],
            'employee_no' => [
                'required',
                'string',
                'max:80',
                Rule::unique('users')->ignore($user?->id),
            ],
            'full_name' => ['required', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
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
        $unit = OrganizationalUnit::findOrFail($data['organizational_unit_id']);

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

        return $data;
    }
}
