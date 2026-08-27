<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\OrganizationalUnit;
use App\Models\SystemSetting;
use App\Models\UserSignature;
use App\Services\AuditService;
use App\Services\ProtectedFileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /** @var list<string> */
    private const BORROWER_DEPARTMENT_NAMES = [
        'College of Health and Sciences',
        'College of Engineering and Architecture',
        'College of Tourism, Hospitality and Business Management',
        'College of Computer Studies',
        'College of Arts and Sciences',
        'College of Technological Developmental Education',
    ];

    public function show(Request $request): View
    {
        $user = $request->user()->load('organizationalUnit', 'currentSignature.file');

        return view('profile.show', [
            'user' => $user,
            'signatureMaxUploadMb' => max(1, (int) SystemSetting::value('max_upload_mb', 5)),
            'borrowerUnits' => $user->access_classification === AccessClassification::BorrowerOnly
                ? OrganizationalUnit::query()
                    ->where('active', true)
                    ->whereIn('unit_name', self::BORROWER_DEPARTMENT_NAMES)
                    ->orderBy('unit_name')
                    ->get()
                : collect(),
        ]);
    }

    public function update(Request $request, AuditService $audit): RedirectResponse
    {
        $user = $request->user();
        $isBorrower = $user->access_classification === AccessClassification::BorrowerOnly;

        $rules = [
            'full_name' => ['required', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'mobile_no' => ['nullable', 'string', 'max:30'],
            'system_notifications' => ['nullable', 'boolean'],
            'email_notifications' => ['nullable', 'boolean'],
            'sms_notifications' => ['nullable', 'boolean'],
        ];

        if ($isBorrower) {
            $allowedBorrowerUnitIds = OrganizationalUnit::query()
                ->where('active', true)
                ->whereIn('unit_name', self::BORROWER_DEPARTMENT_NAMES)
                ->pluck('id')
                ->all();

            $rules['employee_no'] = [
                'required',
                'string',
                'max:80',
                Rule::unique('users')->ignore($user->id),
            ];
            $rules['organizational_unit_id'] = ['required', Rule::in($allowedBorrowerUnitIds)];
        }

        $data = $request->validate($rules);

        $updatedFields = ['full_name', 'designation', 'mobile_no', 'notification_preferences'];
        $updates = [
            'full_name' => $data['full_name'],
            'designation' => $data['designation'] ?? null,
            'mobile_no' => $data['mobile_no'] ?? null,
            'notification_preferences' => [
                'system' => $request->boolean('system_notifications'),
                'email' => $request->boolean('email_notifications'),
                'sms' => $request->boolean('sms_notifications'),
            ],
        ];

        if ($isBorrower) {
            $updates['employee_no'] = $data['employee_no'];
            $updates['organizational_unit_id'] = $data['organizational_unit_id'];
            array_push($updatedFields, 'employee_no', 'organizational_unit_id');
        }

        $before = $user->only($updatedFields);
        $user->update($updates);
        $audit->record('PROFILE_UPDATED', $user, before: $before, after: $user->only($updatedFields));

        return back()->with('status', 'Account settings updated.');
    }

    public function signature(
        Request $request,
        ProtectedFileService $files,
        AuditService $audit
    ): RedirectResponse {
        $maxUploadMb = max(1, (int) SystemSetting::value('max_upload_mb', 5));

        $data = $request->validate([
            'signature' => [
                'required',
                'image',
                'mimes:png,jpg,jpeg,webp',
                'max:'.($maxUploadMb * 1024),
            ],
        ], [
            'signature.image' => 'The E-signature must be a valid image file.',
            'signature.mimes' => 'Use a PNG, JPG, JPEG, or WebP signature image.',
            'signature.max' => "The E-signature must not exceed {$maxUploadMb} MB.",
        ]);

        $user = $request->user();
        $isReplacement = $user->currentSignature()->exists();

        DB::transaction(function () use ($data, $user, $files, $audit, $isReplacement): void {
            UserSignature::query()
                ->where('user_id', $user->id)
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->update([
                    'status' => 'REPLACED',
                    'effective_to' => now(),
                ]);

            $file = $files->storeUpload(
                $data['signature'],
                'profile-signatures/'.$user->id,
                'PROFILE_SIGNATURE'
            );

            $signature = UserSignature::query()->create([
                'user_id' => $user->id,
                'stored_file_id' => $file->id,
                'effective_from' => now(),
                'effective_to' => null,
                'status' => 'ACTIVE',
            ]);

            $audit->record(
                $isReplacement
                    ? 'PROFILE_SIGNATURE_REPLACED'
                    : 'PROFILE_SIGNATURE_REGISTERED',
                $signature,
                after: [
                    'user_id' => $user->id,
                    'stored_file_id' => $file->id,
                    'sha256' => $file->sha256,
                    'mime_type' => $file->mime_type,
                    'byte_size' => $file->byte_size,
                    'effective_from' => $signature->effective_from?->toIso8601String(),
                ]
            );
        }, 3);

        return back()->with(
            'status',
            'E-signature saved. Future signing actions will use this version; existing signed records keep their original snapshots.'
        );
    }

}
