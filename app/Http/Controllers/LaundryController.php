<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\BorrowerRestriction;
use App\Models\EvidenceSubmission;
use App\Models\GeneratedDocument;
use App\Models\LaundryJob;
use App\Models\OverdueCase;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\ProtectedFileService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LaundryController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeSpmuActionOfficer($request);

        return view('laundry.index', [
            'jobs' => LaundryJob::query()
                ->with([
                    'custody.borrower',
                    'custody.request',
                    'latestEvidence.file',
                    'lines.custodyLine.requestItem.inventoryItem.unit',
                ])
                ->where('status', '!=', 'LAUNDRY_COMPLETED')
                ->orderByRaw(
                    "CASE
                        WHEN status = 'FOR_LAUNDRY' THEN 1
                        WHEN status = 'TURNED_OVER_TO_LAUNDRY' THEN 2
                        ELSE 3
                    END"
                )
                ->latest('updated_at')
                ->paginate(20),
        ]);
    }

    public function completed(Request $request): View
    {
        $this->authorizeSpmuActionOfficer($request);

        return view('laundry.completed', [
            'jobs' => LaundryJob::query()
                ->with([
                    'custody.borrower',
                    'custody.request',
                    'latestEvidence.file',
                    'lines.custodyLine.requestItem.inventoryItem.unit',
                    'lines.custodyLine.returnLines',
                ])
                ->where('status', 'LAUNDRY_COMPLETED')
                ->latest('completed_at')
                ->paginate(20),
        ]);
    }

    /**
     * Legacy final-acceptance URLs are kept as redirects so old bookmarks and
     * notifications do not break after the simplified Laundry workflow.
     */
    public function spmuIndex(Request $request): RedirectResponse
    {
        $this->authorizeSpmuActionOfficer($request);

        return redirect()->route('laundry.index');
    }

    public function spmuShow(Request $request, LaundryJob $laundryJob): RedirectResponse
    {
        $this->authorizeSpmuActionOfficer($request);

        return redirect()->route('laundry.show', $laundryJob);
    }

    public function show(Request $request, LaundryJob $laundryJob): View
    {
        $this->authorizeSpmuActionOfficer($request);

        $laundryJob->load([
            'custody.borrower',
            'custody.request.currentVersion',
            'custody.lines.requestItem.inventoryItem',
            'document.file',
            'latestEvidence.file',
            'lines.custodyLine.returnLines',
            'lines.custodyLine.requestItem.inventoryItem.unit',
        ]);

        return view('laundry.show', [
            'job' => $laundryJob,
        ]);
    }

    /**
     * Verify and archive the same travelling physical Laundry Form once it
     * carries the Laundry Personnel wet signatures.
     *
     * The borrower returns linen to the Laundry Area first. Laundry Personnel
     * record the actual RECEIVED BY date, process/wash the linen, fill DATE
     * COMPLETED, and then deliver the fully accomplished physical form to SPMU.
     * The Action Officer uploads it while the case is still FOR_LAUNDRY.
     *
     * RECEIVED BY — not DATE COMPLETED and not the later SPMU upload time — is
     * the borrower's physical return/compliance date. DATE COMPLETED records the
     * Laundry processing completion date. No Laundry portal login is required.
     */
    public function upload(
        Request $request,
        LaundryJob $laundryJob,
        ProtectedFileService $files,
        AuditService $audit,
        NotificationService $notifications
    ): RedirectResponse {
        $this->authorizeSpmuActionOfficer($request);

        $maxKb = ((int) \App\Models\SystemSetting::value('max_upload_mb', 5)) * 1024;

        $data = $request->validate([
            'evidence' => [
                'required',
                'file',
                'mimes:pdf,png,jpg,jpeg,webp',
                'max:'.$maxKb,
            ],
            'laundry_received_on' => [
                $laundryJob->status === 'FOR_LAUNDRY' ? 'required' : 'nullable',
                'date',
                'before_or_equal:today',
            ],
        ]);

        DB::transaction(function () use (
            $request,
            $laundryJob,
            $files,
            $audit,
            $notifications,
            $data
        ): void {
            $job = LaundryJob::query()
                ->lockForUpdate()
                ->with([
                    'custody.borrower',
                    'custody.lines.requestItem.inventoryItem',
                ])
                ->findOrFail($laundryJob->id);

            if (! in_array($job->status, ['FOR_LAUNDRY', 'TURNED_OVER_TO_LAUNDRY', 'LAUNDRY_COMPLETED'], true)) {
                throw ValidationException::withMessages([
                    'evidence' => 'Upload the Laundry Form after Laundry Personnel have signed Received by, recorded the actual Received By date, and returned the accomplished form to SPMU.',
                ]);
            }

            /*
             * FOR_LAUNDRY starts at physical release and remains the portal
             * state while the physical/offline Laundry process is underway.
             * The Laundry Worker is not a system user. The Action Officer uploads
             * the accomplished form and records the physical RECEIVED BY date.
             */
            $attestsPhysicalReceipt = $job->status === 'FOR_LAUNDRY';

            $physicalReceivedAt = $job->worker_received_at;

            if ($attestsPhysicalReceipt) {
                $physicalReceivedAt = CarbonImmutable::parse(
                    (string) $data['laundry_received_on']
                )->startOfDay();

                if ($job->custody->released_at
                    && $physicalReceivedAt->lt($job->custody->released_at->copy()->startOfDay())) {
                    throw ValidationException::withMessages([
                        'laundry_received_on' => 'Laundry received date cannot be earlier than the physical release date.',
                    ]);
                }
            }

            $document = $this->currentLaundryDocument($job);
            $file = $files->storeUpload(
                $data['evidence'],
                'laundry-evidence',
                'PAPER_EVIDENCE'
            );

            $submission = EvidenceSubmission::query()->create([
                'generated_document_id' => $document->id,
                'stored_file_id' => $file->id,
                'borrower_user_id' => $job->custody->borrower_user_id,
                'uploaded_by_user_id' => $request->user()->id,
                'verified_by_user_id' => $request->user()->id,
                'upload_mode' => 'SPMU_ACTION_OFFICER',
                'submitted_at' => now(),
                'verification_status' => 'VERIFIED',
                'verified_at' => now(),
            ]);

            $job->update([
                'generated_document_id' => $document->id,
                'latest_evidence_submission_id' => $submission->id,
                'form_verified_by_user_id' => $request->user()->id,
                'form_verified_at' => now(),
                'worker_received_at' => $physicalReceivedAt,
            ]);

            /*
             * If the scheduler temporarily marked the custody overdue only
             * because the linen form had not reached SPMU yet, clear that
             * automatic late state when the signed form proves that Laundry
             * physically received the linen on or before the effective due
             * date. A genuinely late physical Laundry receipt is not cleared.
             */
            if ($attestsPhysicalReceipt
                && $physicalReceivedAt
                && $job->custody->due_at
                && ! $physicalReceivedAt->startOfDay()->gt($job->custody->due_at->copy()->startOfDay())) {
                $hasOtherBorrowerOutstanding = $job->custody->lines->contains(
                    function ($line): bool {
                        $outstanding = (float) $line->returned_quantity
                            < (float) $line->actual_released_quantity;

                        if (! $outstanding) {
                            return false;
                        }

                        return ! (bool) $line->requestItem?->inventoryItem?->laundry_required;
                    }
                );

                if (! $hasOtherBorrowerOutstanding) {
                    $overdue = OverdueCase::query()
                        ->where('custody_transaction_id', $job->custody_transaction_id)
                        ->first();

                    $canReverseAutomaticLateState = ! $overdue
                        || ($overdue->status === 'OVERDUE'
                            && ! $overdue->penalties()->where('status', '!=', 'VOID')->exists());

                    if ($canReverseAutomaticLateState) {
                        if ($overdue) {
                            $overdue->update([
                                'status' => 'RESOLVED',
                                'accrued_amount' => 0,
                                'sanction_type' => null,
                            ]);
                        }

                        BorrowerRestriction::query()
                            ->where('borrower_user_id', $job->custody->borrower_user_id)
                            ->whereIn('restriction_type', ['PENDING_RETURN', 'OVERDUE_RETURN'])
                            ->where('status', 'ACTIVE')
                            ->update([
                                'status' => 'LIFTED',
                                'effective_to' => now(),
                                'lifted_by_user_id' => $request->user()->id,
                            ]);

                        if ($job->custody->status === 'OVERDUE') {
                            $job->custody->update([
                                'status' => 'RETURN_PROCESSING',
                                'closed_at' => null,
                            ]);
                        }
                    }
                }
            }

            $audit->record(
                'LAUNDRY_SIGNED_FORM_ARCHIVED',
                $job,
                after: [
                    'evidence_submission_id' => $submission->id,
                    'uploaded_by_user_id' => $request->user()->id,
                    'status' => $job->status,
                    /*
                     * Physical condition source stays Laundry Personnel; the
                     * Action Officer is only the system verifier / encoder.
                     */
                    'laundry_received_wet_signature_confirmed' => $attestsPhysicalReceipt,
                    'physical_laundry_received_on' => $physicalReceivedAt?->toDateString(),
                    'physical_condition_source' => 'LAUNDRY_PERSONNEL',
                    'system_verified_by_user_id' => $request->user()->id,
                ]
            );

            $notifications->send(
                'LAUNDRY_FINAL_FORM_ARCHIVED',
                $this->spmuRecipients(),
                "The fully accomplished Laundry Form for {$job->custody->custody_no} was delivered by Laundry Personnel and archived by SPMU. The Action Officer may now encode the linen return findings.",
                $job,
                ['SYSTEM']
            );
        }, 3);

        return redirect()
            ->to(route('custody.return.show', $laundryJob->custody_transaction_id).'#return-primary')
            ->with(
                'status',
                'Completed Laundry Form received and recorded. Encode the linen quantities and any reported issue. If no issue was reported, record the full received quantity as Fine / Good.'
            );
    }

    private function authorizeSpmuActionOfficer(Request $request): void
    {
        abort_unless(
            $request->user()->access_classification === AccessClassification::SpmuOfficer,
            403,
            'Laundry operations are restricted to the SPMU Action Officer.'
        );
    }

    private function currentLaundryDocument(LaundryJob $job): GeneratedDocument
    {
        $document = GeneratedDocument::query()
            ->where('subject_type', \App\Models\CustodyTransaction::class)
            ->where('subject_id', $job->custody_transaction_id)
            ->where('document_type', 'LAUNDRY_FORM')
            ->where('status', 'FINAL')
            ->latest('id')
            ->first();

        if (! $document) {
            throw ValidationException::withMessages([
                'evidence' => 'The current Laundry Form is unavailable. Regenerate the approved physical form before archiving evidence.',
            ]);
        }

        return $document;
    }

    private function spmuRecipients()
    {
        return User::query()
            ->whereIn(
                'access_classification',
                [
                    AccessClassification::SpmuHead->value,
                    AccessClassification::SpmuOfficer->value,
                ]
            )
            ->where('account_status', 'ACTIVE')
            ->get();
    }
}
