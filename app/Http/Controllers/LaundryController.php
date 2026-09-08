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
use App\Services\CustodyService;
use App\Services\NotificationService;
use App\Services\ProtectedFileService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
     * Availability-finalization step.
     *
     * Laundry Personnel are physical/offline actors and do not use the portal.
     * By the time this action is available, the offline Laundry Worker has
     * received/checked the linen, completed the physical Laundry Form, and
     * delivered that form to SPMU. The Action Officer has already uploaded the
     * form and encoded the return findings. This action
     * does not represent washing; it only restores the already-confirmed
     * serviceable quantity to Available inventory.
     */
    public function completeProcessing(
        Request $request,
        LaundryJob $laundryJob,
        AuditService $audit,
        NotificationService $notifications
    ): RedirectResponse {
        $this->authorizeSpmuActionOfficer($request);

        $data = $request->validate([
            'worker_remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $laundryJob, $audit, $notifications, $data): void {
            $job = LaundryJob::query()
                ->lockForUpdate()
                ->with([
                    'lines.custodyLine.requestItem.inventoryItem',
                    'lines.custodyLine.returnLines',
                    'custody.borrower',
                ])
                ->findOrFail($laundryJob->id);

            if ($job->status === 'LAUNDRY_COMPLETED') {
                return;
            }

            /*
             * Backward compatibility for records created under the old UI:
             * some fully returned linen jobs can still be FOR_LAUNDRY even
             * though the accomplished form was verified and SPMU already
             * encoded the return. Derive the already-known serviceable
             * quantity from Return Inspection instead of asking for the
             * removed duplicate turnover/quantity step.
             */
            if ($job->status === 'FOR_LAUNDRY') {
                $allLinenReturned = $job->lines->isNotEmpty()
                    && $job->lines->every(function ($line): bool {
                        $custodyLine = $line->custodyLine;

                        return $custodyLine
                            && (float) $custodyLine->returned_quantity >= (float) $custodyLine->actual_released_quantity;
                    });

                if (! $job->hasVerifiedAccomplishedForm() || ! $allLinenReturned) {
                    throw ValidationException::withMessages([
                        'laundry' => 'Encode the accomplished Laundry Form in SPMU Return first. Availability finalization begins only after the serviceable linen quantity has been recorded from the completed form.',
                    ]);
                }

                foreach ($job->lines as $line) {
                    $received = (int) round((float) $line->custodyLine->returnLines
                        ->where('disposition_state', 'LAUNDRY')
                        ->sum('quantity_received'));

                    $line->update(['received_quantity' => $received]);
                    $line->custodyLine->update([
                        'compliance_status' => $received > 0 ? 'INTERNAL_LAUNDRY' : 'LAUNDRY_COMPLETED',
                    ]);
                }

                /*
                 * Only the queue status moves here. worker_received_at is the
                 * borrower's physical return date and is set from the Laundry
                 * Personnel record or from the Action Officer's attestation of
                 * the accomplished form - never from a document timestamp.
                 */
                $job->update(['status' => 'TURNED_OVER_TO_LAUNDRY']);
            }

            if ($job->status !== 'TURNED_OVER_TO_LAUNDRY') {
                throw ValidationException::withMessages([
                    'laundry' => 'Encode the accomplished Laundry Form in SPMU Return first. Availability finalization begins only after the serviceable linen quantity has been recorded from the completed form.',
                ]);
            }

            $transactionId = DB::table('inventory_transactions')->insertGetId([
                'actor_user_id' => $request->user()->id,
                'transaction_type' => 'LAUNDRY_COMPLETION',
                'source_type' => LaundryJob::class,
                'source_id' => $job->id,
                'reason' => 'Serviceable linen confirmed from the completed Laundry Form and SPMU return encoding; restored to Available inventory.',
                'correlation_id' => (string) Str::uuid(),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($job->lines as $line) {
                $received = (int) round((float) ($line->received_quantity ?? 0));
                $itemId = $line->custodyLine->requestItem->inventory_item_id;

                if ($received > 0) {
                    DB::table('inventory_transaction_lines')->insert([
                        'inventory_transaction_id' => $transactionId,
                        'inventory_item_id' => $itemId,
                        'from_state' => 'LAUNDRY',
                        'to_state' => 'AVAILABLE',
                        'quantity' => $received,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $line->update([
                    'completed_quantity' => $received,
                ]);

                $line->custodyLine->update([
                    'item_status' => 'RETURNED',
                    'compliance_status' => 'LAUNDRY_COMPLETED',
                ]);
            }

            $job->update([
                'status' => 'LAUNDRY_COMPLETED',
                'worker_remarks' => $data['worker_remarks'] ?? $job->worker_remarks,
                'ready_at' => now(),
                'completed_at' => now(),
            ]);

            app(CustodyService::class)->reconcileTransactionStatus($job->custody);

            $audit->record(
                'LAUNDRY_INTERNAL_COMPLETION_RECORDED',
                $job,
                after: [
                    'status' => 'LAUNDRY_COMPLETED',
                    'recorded_by_user_id' => $request->user()->id,
                    'completed_at' => now()->toIso8601String(),
                ]
            );

            $notifications->send(
                'LAUNDRY_PROCESSING_COMPLETED',
                $this->spmuRecipients(),
                "Linen availability for {$job->custody->custody_no} was finalized. The serviceable quantity was restored to Available inventory.",
                $job,
                ['SYSTEM']
            );
        }, 3);

        return redirect()
            ->to(route('custody.return.show', $laundryJob->custody_transaction_id).'#return-summary')
            ->with(
                'status',
                'Linen availability finalized. Serviceable linen is Available. The transaction has returned to Return tracking.'
            );
    }

    /**
     * Verify and archive the same travelling physical Laundry Form once it
     * carries the Laundry Personnel wet signatures.
     *
     * The borrower returns linen to the Laundry Area first. The Laundry Worker
     * checks the actual quantity/condition at handover, records any finding,
     * wet-signs Received by and the Date row, and keeps the accomplished form.
     * The Laundry Worker later delivers that physical form directly to SPMU.
     * The Action Officer uploads it while the case is still FOR_LAUNDRY. The
     * physical Laundry receipt
     * date — not the later SPMU upload/encoding time — is the borrower's return
     * compliance date. No Laundry portal login or second turnover action exists.
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
                    'evidence' => 'Upload the Laundry Form only after Laundry Personnel have checked the returned linen, recorded the quantity/condition, signed Received by, and written the Date.',
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
                "The completed physical Laundry Form for {$job->custody->custody_no} was delivered by the Laundry Worker and archived by SPMU. The Action Officer may now encode the final linen findings.",
                $job,
                ['SYSTEM']
            );
        }, 3);

        return redirect()
            ->to(route('custody.return.show', $laundryJob->custody_transaction_id).'#return-primary')
            ->with(
                'status',
                'Completed Laundry Form received from the Laundry Worker and recorded. Encode the final linen findings exactly as written on the form.'
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
