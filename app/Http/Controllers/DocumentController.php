<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\BillingStatement;
use App\Models\EvidenceSubmission;
use App\Models\GeneratedDocument;
use App\Models\Incident;
use App\Models\Payment;
use App\Models\RequestSupportingDocument;
use App\Models\SignatureSnapshot;
use App\Models\StoredFile;
use App\Models\UserSignature;
use App\Services\ProtectedFileService;
use App\Services\RequestWorkflowService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function download(
        Request $request,
        GeneratedDocument $document,
        ProtectedFileService $files,
        RequestWorkflowService $workflow
    ): StreamedResponse|BinaryFileResponse {
        $borrowingRequest = $this->authorizeGeneratedDocument($request, $document);
        $user = $request->user();

        if (
            $document->document_type === 'APPROVED_REQUEST_LETTER'
            && $borrowingRequest
            && (int) $borrowingRequest->borrower_user_id === (int) $user->id
        ) {
            $workflow->recordApprovedLetterDownload(
                $borrowingRequest,
                $document,
                $user,
                $request->ip(),
                $request->userAgent()
            );
        }

        $file = $document->file;

        abort_unless($file, 404);

        $bytes = $files->bytes($file);

        return response()->streamDownload(
            fn () => print $bytes,
            $file->original_name,
            [
                'Content-Type' => $file->mime_type,
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    public function view(
        Request $request,
        GeneratedDocument $document,
        ProtectedFileService $files
    ): StreamedResponse {
        $this->authorizeGeneratedDocument($request, $document);

        $file = $document->file;
        abort_unless($file, 404);

        $safeName = str_replace(['"', "\r", "\n"], '', $file->original_name ?: 'document.pdf');

        return response()->stream(
            fn () => print $files->bytes($file),
            200,
            [
                'Content-Type' => $file->mime_type ?: 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$safeName.'"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
            ]
        );
    }

    private function authorizeGeneratedDocument(
        Request $request,
        GeneratedDocument $document
    ): ?\App\Models\BorrowingRequest {
        $document->loadMissing(['file', 'version.request']);

        $borrowingRequest = $document->version?->request;
        $user = $request->user();

        $billingBorrowerId = $document->subject_type === BillingStatement::class
            ? BillingStatement::query()
                ->whereKey($document->subject_id)
                ->value('borrower_user_id')
            : null;

        abort_unless(
            ($borrowingRequest
                && (int) $borrowingRequest->borrower_user_id === (int) $user->id)
            || (int) $billingBorrowerId === (int) $user->id
            || $user->hasRole(UserRole::Spmu)
            || $user->hasRole(UserRole::Ictu),
            403
        );

        abort_if(
            in_array($document->status, ['SUPERSEDED', 'INVALIDATED', 'EXPIRED'], true),
            410,
            'This controlled document is historical and is no longer valid for operational use.'
        );

        return $borrowingRequest;
    }

    /**
     * Secure inline preview for protected uploaded files.
     *
     * Important:
     * RequestSupportingDocument uses `is_current`.
     * It does NOT use GeneratedDocument's `status` field.
     */
    public function protectedFile(
        Request $request,
        StoredFile $file,
        ProtectedFileService $files
    ): StreamedResponse {
        $user = $request->user();

        abort_unless($user, 401);

        $workspace = strtoupper(
            (string) $request->session()->get('active_workspace')
        );

        $isSpmu = $workspace === 'SPMU'
            || $user->hasRole(UserRole::Spmu);

        $isIctu = $workspace === 'ICTU'
            || $user->hasRole(UserRole::Ictu);

        /*
         * A registered signature image and its immutable snapshots are never
         * exposed as standalone files to another user. Official generated
         * documents may contain the authorized signature image, but the
         * protected source/snapshot itself remains signer-only.
         */
        $signatureOwnerId = UserSignature::query()
            ->where('stored_file_id', $file->id)
            ->value('user_id');

        $snapshotSignerId = SignatureSnapshot::query()
            ->where('snapshot_file_id', $file->id)
            ->value('signer_user_id');

        if ($signatureOwnerId !== null || $snapshotSignerId !== null) {
            abort_unless(
                (int) ($signatureOwnerId ?? $snapshotSignerId) === (int) $user->id,
                403
            );

            return response()->stream(
                fn () => print $files->bytes($file),
                200,
                [
                    'Content-Type' => $file->mime_type ?: 'application/octet-stream',
                    'Content-Disposition' => 'inline; filename="signature-preview"',
                    'X-Content-Type-Options' => 'nosniff',
                    'Cache-Control' => 'private, no-store, max-age=0',
                    'Pragma' => 'no-cache',
                ]
            );
        }

        /*
         * Uploaded request supporting document:
         * scanned signed Borrowing Request Letter / PTC.
         *
         * Current workflow identifies the active copy using is_current = true.
         */
        $requestSupportingDocument = RequestSupportingDocument::query()
            ->where('stored_file_id', $file->id)
            ->where('is_current', true)
            ->with('requestVersion.request')
            ->first();

        $requestOwnerId =
            $requestSupportingDocument?->requestVersion?->request?->borrower_user_id;

        $isRequestOwner =
            $requestOwnerId !== null
            && (int) $requestOwnerId === (int) $user->id;

        /*
         * Ordinary operational evidence.
         */
        $belongsToBorrower = EvidenceSubmission::query()
            ->where('stored_file_id', $file->id)
            ->where('borrower_user_id', $user->id)
            ->exists()
            || Incident::query()
                ->where('supporting_evidence_file_id', $file->id)
                ->where('borrower_user_id', $user->id)
                ->exists()
            || Payment::query()
                ->where('evidence_file_id', $file->id)
                ->whereHas(
                    'billingStatement',
                    fn ($query) =>
                        $query->where('borrower_user_id', $user->id)
                )
                ->exists();

        $isTemplateSource = (string) $file->classification === 'DOCUMENT_TEMPLATE_SOURCE';

        $operationalEvidence = in_array(
            (string) $file->classification,
            [
                'PAPER_EVIDENCE',
                'PAYMENT_EVIDENCE',
                'INCIDENT_EVIDENCE',
                'REQUEST_SUPPORTING_DOCUMENT',
            ],
            true
        );

        $uploadedByCurrentUser =
            (int) $file->uploaded_by_user_id === (int) $user->id;

        /*
         * SPMU is allowed to review request supporting documents.
         * ICTU remains available for authorized technical support.
         */
        $allowed =
            $uploadedByCurrentUser
            || $belongsToBorrower
            || $isRequestOwner
            || ($requestSupportingDocument && $isSpmu)
            || ($operationalEvidence && $isSpmu)
            || ($isTemplateSource && ($isSpmu || $isIctu))
            || $isIctu;

        abort_unless($allowed, 403);

        $mimeType =
            $file->mime_type ?: 'application/octet-stream';

        $safeName = str_replace(
            ['"', "\r", "\n"],
            '',
            $file->original_name ?: 'document'
        );

        return response()->stream(
            fn () => print $files->bytes($file),
            200,
            [
                'Content-Type' => $mimeType,
                'Content-Disposition' =>
                    ($isTemplateSource && str_contains(strtolower($mimeType), 'html') ? 'attachment' : 'inline')
                    .'; filename="'.$safeName.'"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
            ]
        );
    }
}
