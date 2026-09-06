<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Models\UserSignature;
use App\Services\AuditService;
use App\Services\ProtectedFileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user()->load([
            'organizationalUnit',
            'authorizedOrganizationalUnits',
            'currentSignature.file',
        ]);

        return view('profile.show', [
            'user' => $user,
            'signatureMaxUploadMb' => max(1, (int) SystemSetting::value('max_upload_mb', 5)),
        ]);
    }

    public function update(Request $request, AuditService $audit): RedirectResponse
    {
        /*
         * Official identity and organizational data are centrally managed by
         * ICTU. Account Settings may change communication preferences only.
         */
        $data = $request->validate([
            'mobile_no' => ['nullable', 'string', 'max:30'],
            'system_notifications' => ['nullable', 'boolean'],
            'email_notifications' => ['nullable', 'boolean'],
            'sms_notifications' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $before = $user->only(['mobile_no', 'notification_preferences']);

        $user->update([
            'mobile_no' => $data['mobile_no'] ?? null,
            'notification_preferences' => [
                'system' => $request->boolean('system_notifications'),
                'email' => $request->boolean('email_notifications'),
                'sms' => $request->boolean('sms_notifications'),
            ],
        ]);

        $audit->record(
            'PROFILE_UPDATED',
            $user,
            before: $before,
            after: $user->only(['mobile_no', 'notification_preferences'])
        );

        return back()->with('status', 'Contact and notification settings updated.');
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
