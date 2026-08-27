<?php

namespace App\Services;

use App\Models\SignatureSnapshot;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SignatureService
{
    public function __construct(
        private ProtectedFileService $files,
        private AuditService $audit,
    ) {}

    /**
     * Capture an immutable copy of the authenticated signer's current
     * registered signature for one explicit signing action.
     *
     * @param  array<string, mixed>  $context
     */
    public function snapshot(
        User $user,
        string $purpose,
        ?string $role = null,
        ?Model $document = null,
        array $context = []
    ): SignatureSnapshot {
        abort_unless(
            (int) auth()->id() === (int) $user->id,
            403,
            'You may only sign using your own registered E-signature.'
        );

        $signature = $user->currentSignature()
            ->with('file')
            ->where('effective_from', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>', now());
            })
            ->first();

        if (! $signature?->file) {
            throw ValidationException::withMessages([
                'signature' => 'Register an E-signature in Account Settings before completing this signing action.',
            ]);
        }

        $source = $signature->file;
        $extension = strtolower(pathinfo($source->storage_path, PATHINFO_EXTENSION) ?: 'png');
        $capturedAt = now();
        $purposeCode = strtoupper(trim($purpose));

        $snapshotFile = $this->files->storeBytes(
            $this->files->bytes($source),
            'signature-snapshots',
            'signature-'.$user->id.'-'.Str::slug($purposeCode).'.'.$extension,
            $source->mime_type,
            $extension,
            'SIGNATURE_SNAPSHOT',
            $user->id,
        );

        $snapshot = SignatureSnapshot::query()->create([
            'user_signature_id' => $signature->id,
            'signer_user_id' => $user->id,
            'snapshot_file_id' => $snapshotFile->id,
            'signer_name' => $user->full_name,
            'signer_role' => $role,
            'purpose_code' => $purposeCode,
            'sha256' => $snapshotFile->sha256,
            'captured_at' => $capturedAt,
        ]);

        $this->audit->record(
            'E_SIGNATURE_APPLIED',
            $document ?: $snapshot,
            after: array_merge([
                'signature_snapshot_id' => $snapshot->id,
                'signer_user_id' => $user->id,
                'signer_name' => $user->full_name,
                'signer_role' => $role,
                'purpose_code' => $purposeCode,
                'signed_at' => $capturedAt->toIso8601String(),
                'document_type' => $document ? $document::class : null,
                'document_id' => $document?->getKey(),
                'signature_sha256' => $snapshotFile->sha256,
            ], $context)
        );

        return $snapshot;
    }
}
