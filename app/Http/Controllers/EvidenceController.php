<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\CustodyTransaction;
use App\Models\EvidenceSubmission;
use App\Models\GeneratedDocument;
use App\Models\LaundryJob;
use App\Models\LaundryRecord;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\ProtectedFileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EvidenceController extends Controller
{
    public function store(Request $request, GeneratedDocument $document, ProtectedFileService $files, AuditService $audit, NotificationService $notifications): RedirectResponse
    {
        abort_unless(in_array($document->document_type, ['GATE_PASS', 'LAUNDRY_FORM'], true), 404);
        $maxKb = ((int) SystemSetting::value('max_upload_mb', 5)) * 1024;
        $data = $request->validate([
            'evidence' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:'.$maxKb],
            'fallback_reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $submission = DB::transaction(function () use ($request, $document, $files, $audit, $notifications, $data): EvidenceSubmission {
            $document = GeneratedDocument::query()->lockForUpdate()->findOrFail($document->id);
            $custody = $this->applicableCustody($document);

            if (
                $document->document_type === 'LAUNDRY_FORM'
                && LaundryJob::query()
                    ->where('custody_transaction_id', $custody->id)
                    ->exists()
            ) {
                abort(
                    403,
                    'The active Laundry Form is archived by the SPMU Action Officer through Laundry Operations after final physical acceptance.'
                );
            }

            $borrower = $custody->borrower;
            abort_unless($request->user()->id === $borrower->id || $request->user()->hasRole(UserRole::Spmu), 403);
            $fallback = $request->user()->id !== $borrower->id;
            if ($fallback && blank($data['fallback_reason'] ?? null)) {
                throw ValidationException::withMessages(['fallback_reason' => 'An attributable reason is required when SPMU uploads evidence for the borrower.']);
            }

            $file = $files->storeUpload($data['evidence'], 'paper-evidence', 'PAPER_EVIDENCE');
            $submission = EvidenceSubmission::query()->create([
                'generated_document_id' => $document->id,
                'stored_file_id' => $file->id,
                'borrower_user_id' => $borrower->id,
                'uploaded_by_user_id' => $request->user()->id,
                'upload_mode' => $fallback ? 'SPMU_FALLBACK' : 'BORROWER_PRIMARY',
                'fallback_reason' => $data['fallback_reason'] ?? null,
                'borrower_notified_at' => $fallback ? now() : null,
                'submitted_at' => now(),
                'verification_status' => 'PENDING_VERIFICATION',
            ]);
            $audit->record('PAPER_EVIDENCE_UPLOADED', $submission, reason: $data['fallback_reason'] ?? null, after: ['mode' => $submission->upload_mode, 'sha256' => $file->sha256]);
            if ($fallback) {
                $notifications->send('SPMU_FALLBACK_UPLOAD', collect([$borrower]), "SPMU uploaded {$document->document_type} evidence on your behalf. It remains pending separate verification.", $document, ['SYSTEM', 'EMAIL']);
            }

            return $submission;
        }, 3);

        return back()->with('status', 'Evidence uploaded and marked Pending Verification. Upload alone does not close the transaction.');
    }

    public function verify(Request $request, EvidenceSubmission $evidence, AuditService $audit, NotificationService $notifications): RedirectResponse
    {
        abort_unless($request->user()->hasRole(UserRole::Spmu), 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['VERIFIED', 'REJECTED'])], 'reason' => ['nullable', 'string', 'max:1000']]);
        if ($data['decision'] === 'REJECTED' && blank($data['reason'] ?? null)) {
            throw ValidationException::withMessages(['reason' => 'A rejection reason is required.']);
        }
        $decided = DB::transaction(function () use ($request, $evidence, $audit, $notifications, $data): bool {
            $evidence = EvidenceSubmission::query()->lockForUpdate()->findOrFail($evidence->id);
            if ($evidence->verification_status !== 'PENDING_VERIFICATION') {
                return false;
            }
            if ($evidence->uploaded_by_user_id === $request->user()->id && $evidence->upload_mode === 'SPMU_FALLBACK') {
                throw ValidationException::withMessages(['decision' => 'The SPMU fallback uploader cannot verify the same evidence. A separate SPMU verifier is required.']);
            }
            $evidence->loadMissing('document');
            $custody = $this->applicableCustody($evidence->document);

            if (
                $evidence->document->document_type === 'LAUNDRY_FORM'
                && LaundryJob::query()
                    ->where('custody_transaction_id', $custody->id)
                    ->exists()
            ) {
                abort(
                    403,
                    'Use the SPMU Laundry Form verification panel so the signed form is encoded and verified in one accountable step.'
                );
            }

            $evidence->update([
                'verified_by_user_id' => $request->user()->id,
                'verification_status' => $data['decision'],
                'verified_at' => now(),
                'rejection_reason' => $data['decision'] === 'REJECTED' ? $data['reason'] : null,
            ]);
            if ($data['decision'] === 'VERIFIED' && $evidence->document->document_type === 'LAUNDRY_FORM') {
                LaundryRecord::query()
                    ->whereHas('returnLine.custodyLine', fn ($query) => $query->where('custody_transaction_id', $custody->id))
                    ->where('status', '!=', 'VERIFIED')
                    ->update(['status' => 'EVIDENCE_VERIFIED_PENDING_PHYSICAL_CHECK']);
            }
            $audit->record('PAPER_EVIDENCE_'.$data['decision'], $evidence, reason: $data['reason'] ?? null);
            $notifications->send('EVIDENCE_'.$data['decision'], collect([$custody->borrower]), "{$evidence->document->document_type} evidence was {$data['decision']}. ".($data['reason'] ?? ''), $evidence->document, ['SYSTEM', 'EMAIL']);

            return true;
        }, 3);

        return back()->with('status', $decided
            ? 'Evidence decision recorded separately from physical verification.'
            : 'This evidence already has a final decision. No duplicate verification was recorded.');
    }

    private function applicableCustody(GeneratedDocument $document): CustodyTransaction
    {
        if ($document->status !== 'FINAL' || $document->subject_type !== CustodyTransaction::class) {
            throw ValidationException::withMessages(['evidence' => 'Evidence can only be attached to the current applicable controlled form.']);
        }

        $custody = CustodyTransaction::query()->with(['borrower', 'gatePass'])->find($document->subject_id);
        if (! $custody || (int) $document->request_version_id !== (int) $custody->request_version_id) {
            throw ValidationException::withMessages(['evidence' => 'The selected controlled form does not belong to this custody request.']);
        }
        $currentDocumentId = GeneratedDocument::query()
            ->where('subject_type', CustodyTransaction::class)
            ->where('subject_id', $custody->id)
            ->where('document_type', $document->document_type)
            ->where('status', 'FINAL')
            ->latest('id')
            ->value('id');
        if ((int) $currentDocumentId !== (int) $document->id) {
            throw ValidationException::withMessages(['evidence' => 'A newer controlled form exists. Upload evidence against the current form.']);
        }

        if ($document->document_type === 'GATE_PASS') {
            if ((int) $custody->gatePass?->pass_document_id !== (int) $document->id || ! $custody->released_at || $custody->gatePass?->status === 'VERIFIED') {
                throw ValidationException::withMessages(['evidence' => 'This Gate Pass is not currently awaiting post-release evidence.']);
            }
        } elseif ($document->document_type === 'LAUNDRY_FORM') {
            $activeLaundryJob = LaundryJob::query()
                ->where('custody_transaction_id', $custody->id)
                ->where('status', '!=', 'LAUNDRY_COMPLETED')
                ->exists();

            $historicalLaundryRecord = LaundryRecord::query()
                ->whereHas(
                    'returnLine.custodyLine',
                    fn ($query) =>
                        $query->where(
                            'custody_transaction_id',
                            $custody->id
                        )
                )
                ->where('status', '!=', 'VERIFIED')
                ->exists();

            if (! $activeLaundryJob && ! $historicalLaundryRecord) {
                throw ValidationException::withMessages([
                    'evidence' =>
                        'This Laundry Form is not currently awaiting laundry-service evidence.',
                ]);
            }
        }

        return $custody;
    }
}
