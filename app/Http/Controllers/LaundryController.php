<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\EvidenceSubmission;
use App\Models\GeneratedDocument;
use App\Models\LaundryJob;
use App\Models\User;
use App\Services\AuditService;
use App\Services\CustodyService;
use App\Services\NotificationService;
use App\Services\ProtectedFileService;
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
     * Internal Laundry processing step. The quantity/condition split has already
     * been encoded by the Action Officer from the accomplished Laundry Form at
     * Return Inspection. Only serviceable linen entered the LAUNDRY inventory
     * state, so this action simply marks that known quantity clean/available.
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
                        'laundry' => 'Encode the accomplished Laundry Form in SPMU Return first. Internal laundry completion begins only after the serviceable linen quantity has been recorded from that form.',
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

                $job->update([
                    'status' => 'TURNED_OVER_TO_LAUNDRY',
                    'worker_received_at' => $job->worker_received_at
                        ?: ($job->form_verified_at ?: now()),
                ]);
            }

            if ($job->status !== 'TURNED_OVER_TO_LAUNDRY') {
                throw ValidationException::withMessages([
                    'laundry' => 'Encode the accomplished Laundry Form in SPMU Return first. Internal laundry completion begins only after the serviceable linen quantity has been recorded from that form.',
                ]);
            }

            $transactionId = DB::table('inventory_transactions')->insertGetId([
                'actor_user_id' => $request->user()->id,
                'transaction_type' => 'LAUNDRY_COMPLETION',
                'source_type' => LaundryJob::class,
                'source_id' => $job->id,
                'reason' => 'Internal Laundry washing completed for serviceable linen already classified from the accomplished Laundry Form.',
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
                'worker_name' => $job->worker_name ?: $request->user()->full_name,
                'worker_completed_at' => now(),
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
                "Internal laundry processing for {$job->custody->custody_no} was completed. The serviceable linen already classified from the accomplished Laundry Form was restored to Available inventory.",
                $job,
                ['SYSTEM']
            );
        }, 3);

        return back()->with(
            'status',
            'Internal laundry completion recorded. Clean linen is back in Available inventory.'
        );
    }

    /**
     * Verify and archive the same travelling physical Laundry Form once it
     * carries the Laundry Personnel wet signatures.
     *
     * The borrower returns linen to the Laundry Area first, so the
     * accomplished form normally arrives while the case is still
     * FOR_LAUNDRY. It is the documentary basis for the linen condition
     * encoded in the SPMU Return Inspection, which is blocked until this
     * upload exists. Laundry Operations only reads the archived form; it does
     * not provide a second upload/turnover step.
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
                ->with(['custody.borrower'])
                ->findOrFail($laundryJob->id);

            if (! in_array($job->status, ['FOR_LAUNDRY', 'TURNED_OVER_TO_LAUNDRY', 'LAUNDRY_COMPLETED'], true)) {
                throw ValidationException::withMessages([
                    'evidence' => 'Upload the Laundry Form only after Laundry Personnel has physically received the returned linen, recorded the condition, and signed Received by.',
                ]);
            }

            /*
             * FOR_LAUNDRY starts at physical release, so it also covers the
             * period while the borrower still holds the linen. Laundry
             * Personnel are not system users: at this stage the upload is the
             * system record that the physical receipt happened. The Action
             * Officer therefore attests that the uploaded accomplished form
             * contains the Laundry Personnel RECEIVED BY wet signature. Later
             * stages already have that receipt recorded and are left untouched.
             */
            $attestsPhysicalReceipt = $job->status === 'FOR_LAUNDRY';

            if ($attestsPhysicalReceipt
                && ! $request->boolean('laundry_received_signature_confirmed')) {
                throw ValidationException::withMessages([
                    'laundry_received_signature_confirmed' => 'Confirm that this is the accomplished Laundry Form signed by Laundry Personnel.',
                ]);
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
            ]);

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
                    'physical_condition_source' => 'LAUNDRY_PERSONNEL',
                    'system_verified_by_user_id' => $request->user()->id,
                ]
            );

            $notifications->send(
                'LAUNDRY_FINAL_FORM_ARCHIVED',
                $this->spmuRecipients(),
                "The signed physical Laundry Form for {$job->custody->custody_no} was archived. This archive does not change borrower clearance or the internal washing schedule.",
                $job,
                ['SYSTEM']
            );
        }, 3);

        return back()->with('status', 'Accomplished Laundry Form verified and archived. Linen condition may now be encoded in the Return Inspection.');
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
