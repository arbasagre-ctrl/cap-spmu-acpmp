<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfilePictureController extends Controller
{
    /**
     * Display the authenticated user's profile picture.
     */
    public function show(Request $request): StreamedResponse
    {
        $user = $request->user();

        $path = $user?->profile_picture_path;

        abort_unless(
            $path && Storage::disk('local')->exists($path),
            404
        );

        return Storage::disk('local')->response(
            $path,
            null,
            [
                'Cache-Control' => 'private, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    /**
     * Upload or replace the authenticated user's profile picture.
     */
    public function update(
        Request $request,
        AuditService $audit
    ): RedirectResponse {
        $data = $request->validate([
            'profile_picture' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],
        ]);

        $user = $request->user();

        $previousPath = $user->profile_picture_path;

        $newPath = $data['profile_picture']->store(
            'profile-pictures/'.$user->id,
            'local'
        );

        $user->forceFill([
            'profile_picture_path' => $newPath,
        ])->save();

        if (
            $previousPath
            && $previousPath !== $newPath
            && Storage::disk('local')->exists($previousPath)
        ) {
            Storage::disk('local')->delete($previousPath);
        }

        $audit->record(
            'PROFILE_PICTURE_UPDATED',
            $user,
            before: [
                'profile_picture_path' => $previousPath,
            ],
            after: [
                'profile_picture_path' => $newPath,
            ],
        );

        return back()->with(
            'status',
            'Profile picture updated successfully.'
        );
    }

    /**
     * Remove the authenticated user's profile picture.
     */
    public function destroy(
        Request $request,
        AuditService $audit
    ): RedirectResponse {
        $user = $request->user();

        $previousPath = $user->profile_picture_path;

        if (! $previousPath) {
            return back()->with(
                'status',
                'No profile picture to remove.'
            );
        }

        $user->forceFill([
            'profile_picture_path' => null,
        ])->save();

        if (Storage::disk('local')->exists($previousPath)) {
            Storage::disk('local')->delete($previousPath);
        }

        $audit->record(
            'PROFILE_PICTURE_REMOVED',
            $user,
            before: [
                'profile_picture_path' => $previousPath,
            ],
            after: [
                'profile_picture_path' => null,
            ],
        );

        return back()->with(
            'status',
            'Profile picture removed successfully.'
        );
    }
}