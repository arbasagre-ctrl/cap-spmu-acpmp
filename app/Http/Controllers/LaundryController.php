<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\EvidenceSubmission;
use App\Models\GeneratedDocument;
use App\Models\Incident;
use App\Models\LaundryJob;
use App\Models\LaundryRecord;
use App\Models\OverdueCase;
use App\Models\ReturnTransaction;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\ProtectedFileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
                    'lines.custodyLine.requestItem.inventoryItem.unit',
                ])
                ->where('status', '!=', 'LAUNDRY_COMPLETED')
                ->orderByRaw(
                    "CASE
                        WHEN status = 'FOR_LAUNDRY' THEN 1
                        WHEN status = 'IN_PROCESS' THEN 2
                        WHEN status = 'READY_FOR_SPMU_RETURN' THEN 3
                        WHEN status IN ('AWAITING_FINAL_FORM_UPLOAD', 'FORM_REPLACEMENT_REQUIRED') THEN 4
                        ELSE 5
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
                    'lines.custodyLine.requestItem.inventoryItem.unit',
                ])
                ->where('status', 'LAUNDRY_COMPLETED')
                ->latest('completed_at')
                ->paginate(20),
        ]);
    }

    public function spmuIndex(Request $request): View
    {
        $this->authorizeSpmuActionOfficer($request);

        return view('laundry.spmu-index', [
            'jobs' => LaundryJob::query()
                ->with([
                    'custody.borrower',
                    'custody.request',
                    'latestEvidence.file',
                    'lines.custodyLine.requestItem.inventoryItem.unit',
                ])
                ->whereIn('status', [
                    'READY_FOR_SPMU_RETURN',
                    'AWAITING_FINAL_FORM_UPLOAD',
                    'FORM_REPLACEMENT_REQUIRED',
                ])
                ->latest('updated_at')
                ->paginate(20),
        ]);
    }

    public function spmuShow(Request $request, LaundryJob $laundryJob): View|RedirectResponse
    {
        $this->authorizeSpmuActionOfficer($request);

        if (in_array($laundryJob->status, ['FOR_LAUNDRY', 'IN_PROCESS'], true)) {
            return redirect()->route('laundry.show', $laundryJob);
        }

        $laundryJob->load([
            'custody.borrower',
            'custody.request.currentVersion',
            'document.file',
            'latestEvidence.file',
            'lines.custodyLine.requestItem.inventoryItem.unit',
            'formVerifier',
        ]);

        return view('laundry.spmu-show', [
            'job' => $laundryJob,
        ]);
    }

    public function receive(
        Request $request,
        LaundryJob $laundryJob,
        AuditService $audit,
        NotificationService $notifications
    ): RedirectResponse {
        $this->authorizeSpmuActionOfficer($request);

        $data = $request->validate([
            'borrower_turnover_signature_confirmed' => ['required', 'accepted'],
            'lines' => ['required', 'array'],
            'lines.*.received_quantity' => ['required', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($request, $laundryJob, $audit, $notifications, $data): void {
            $job = LaundryJob::query()
                ->lockForUpdate()
                ->with([
                    'lines.custodyLine.requestItem',
                    'custody.borrower',
                ])
                ->findOrFail($laundryJob->id);

            if ($job->status === 'IN_PROCESS') {
                return;
            }

            if ($job->status !== 'FOR_LAUNDRY') {
                throw ValidationException::withMessages([
                    'laundry' => 'This linen case is not currently awaiting borrower turnover to Laundry.',
                ]);
            }

            $hasQuantityDiscrepancy = false;

            foreach ($job->lines as $line) {
                $values = $data['lines'][$line->id] ?? null;

                if (! is_array($values)) {
                    throw ValidationException::withMessages([
                        'lines' => 'Enter the actual quantity received by Laundry for every linen item.',
                    ]);
                }

                $issued = (float) $line->issued_quantity;
                $received = (float) $values['received_quantity'];

                if ($received > $issued) {
                    throw ValidationException::withMessages([
                        'lines' => 'Laundry received quantity cannot exceed the quantity issued by SPMU.',
                    ]);
                }

                $hasQuantityDiscrepancy = $hasQuantityDiscrepancy || abs($received - $issued) > 0.0005;

                $line->update([
                    'received_quantity' => $received,
                ]);
            }

            $job->update([
                'status' => 'IN_PROCESS',
                'worker_name' => $request->user()->full_name,
                'worker_received_at' => $job->worker_received_at ?: now(),
            ]);

            if (in_array($job->custody->status, ['ACTIVE'], true)) {
                $job->custody->update([
                    'status' => 'RETURN_PROCESSING',
                ]);
            }

            $audit->record(
                'LAUNDRY_USED_LINEN_RECEIVED',
                $job,
                after: [
                    'status' => 'IN_PROCESS',
                    'custody_status' => $job->custody->fresh()->status,
                    'worker_received_at' => now()->toIso8601String(),
                    'received_by_user_id' => $request->user()->id,
                    'quantity_discrepancy' => $hasQuantityDiscrepancy,
                    'borrower_turnover_signature_confirmed' => true,
                ]
            );

            $notifications->send(
                'LAUNDRY_RECEIVED',
                collect([$job->custody->borrower]),
                "Laundry received the used linen and physical Laundry Form for {$job->custody->custody_no}. No further borrower laundry action is required while Laundry and SPMU complete the return process.",
                $job,
                ['SYSTEM', 'EMAIL']
            );

            if ($hasQuantityDiscrepancy) {
                $notifications->send(
                    'LAUNDRY_QUANTITY_DISCREPANCY',
                    $this->spmuRecipients(),
                    "Laundry recorded a quantity discrepancy during borrower turnover for {$job->custody->custody_no}. Review the final SPMU return inspection carefully and account for the complete issued quantity.",
                    $job,
                    ['SYSTEM']
                );
            }
        }, 3);

        return back()->with('status', 'Used linen and signed turnover form received. Laundry processing may begin.');
    }

    public function show(Request $request, LaundryJob $laundryJob): View|RedirectResponse
    {
        $this->authorizeSpmuActionOfficer($request);

        if (in_array($laundryJob->status, [
            'READY_FOR_SPMU_RETURN',
            'AWAITING_FINAL_FORM_UPLOAD',
            'FORM_REPLACEMENT_REQUIRED',
        ], true)) {
            return redirect()->route('laundry.spmu.show', $laundryJob);
        }

        $laundryJob->load([
            'custody.borrower',
            'custody.request.currentVersion',
            'document.file',
            'latestEvidence.file',
            'lines.custodyLine.requestItem.inventoryItem.unit',
        ]);

        return view('laundry.show', [
            'job' => $laundryJob,
        ]);
    }

    public function completeProcessing(
        Request $request,
        LaundryJob $laundryJob,
        AuditService $audit,
        NotificationService $notifications
    ): RedirectResponse {
        $this->authorizeSpmuActionOfficer($request);

        $data = $request->validate([
            'worker_remarks' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array'],
            'lines.*.issue_type' => [
                'required',
                Rule::in(['NONE', 'STAINED', 'TORN', 'DAMAGED', 'OTHER']),
            ],
            'lines.*.affected_quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.completed_quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $laundryJob, $audit, $notifications, $data): void {
            $job = LaundryJob::query()
                ->lockForUpdate()
                ->with([
                    'lines.custodyLine.requestItem',
                    'custody.borrower',
                ])
                ->findOrFail($laundryJob->id);

            if ($job->status === 'READY_FOR_SPMU_RETURN') {
                return;
            }

            if ($job->status !== 'IN_PROCESS') {
                throw ValidationException::withMessages([
                    'laundry' => 'Confirm borrower turnover to Laundry before recording laundry completion.',
                ]);
            }

            foreach ($job->lines as $line) {
                $values = $data['lines'][$line->id] ?? null;

                if (! is_array($values)) {
                    throw ValidationException::withMessages([
                        'lines' => 'Record the laundry completion details for every linen item.',
                    ]);
                }

                $received = (float) ($line->received_quantity ?? 0);
                $affected = (float) $values['affected_quantity'];
                $completed = (float) $values['completed_quantity'];
                $issue = (string) $values['issue_type'];

                if ($affected > $received) {
                    throw ValidationException::withMessages([
                        'lines' => 'Affected quantity cannot exceed the quantity physically received by Laundry.',
                    ]);
                }

                if ($completed > $received) {
                    throw ValidationException::withMessages([
                        'lines' => 'Completed quantity cannot exceed the quantity physically received by Laundry.',
                    ]);
                }

                if ($issue === 'NONE' && $affected > 0) {
                    throw ValidationException::withMessages([
                        'lines' => 'Affected quantity must be zero when Laundry records no condition issue.',
                    ]);
                }

                if ($issue !== 'NONE' && $affected <= 0) {
                    throw ValidationException::withMessages([
                        'lines' => 'Enter the affected quantity when Laundry records a stain, tear, damage, or other issue.',
                    ]);
                }

                if ($issue === 'NONE' && abs($completed - $received) > 0.0005) {
                    throw ValidationException::withMessages([
                        'lines' => 'When no issue is recorded, the completed quantity must equal the quantity received by Laundry.',
                    ]);
                }

                $line->update([
                    'issue_type' => $issue,
                    'affected_quantity' => $affected,
                    'completed_quantity' => $completed,
                    'remarks' => $values['remarks'] ?? null,
                ]);
            }

            $job->update([
                'status' => 'READY_FOR_SPMU_RETURN',
                'worker_name' => $job->worker_name ?: $request->user()->full_name,
                'worker_completed_at' => now(),
                'worker_remarks' => $data['worker_remarks'] ?? null,
                'ready_at' => $job->ready_at ?: now(),
            ]);

            $audit->record(
                'LAUNDRY_PROCESSING_COMPLETED',
                $job,
                after: [
                    'status' => 'READY_FOR_SPMU_RETURN',
                    'worker_completed_at' => now()->toIso8601String(),
                    'spmu_action_officer_user_id' => $request->user()->id,
                ]
            );

            $notifications->send(
                'LAUNDRY_READY_FOR_SPMU_RETURN',
                $this->spmuRecipients(),
                "The SPMU Action Officer recorded laundry processing completion for {$job->custody->custody_no}. The cleaned linen and physical Laundry Form are ready for the existing final SPMU quantity/condition inspection and authorized signature.",
                $job,
                ['SYSTEM']
            );

            $notifications->send(
                'LAUNDRY_PROCESSING_COMPLETE',
                collect([$job->custody->borrower]),
                "Laundry processing for {$job->custody->custody_no} is complete. No borrower pickup is required; SPMU will continue with final physical acceptance.",
                $job,
                ['SYSTEM', 'EMAIL']
            );
        }, 3);

        return back()->with(
            'status',
            'Laundry processing recorded. Bring the cleaned linen and the same physical Laundry Form directly to SPMU for final acceptance.'
        );
    }

    public function upload(
        Request $request,
        LaundryJob $laundryJob,
        ProtectedFileService $files,
        AuditService $audit,
        NotificationService $notifications
    ): RedirectResponse {
        abort_unless(
            $request->user()->access_classification === AccessClassification::SpmuOfficer,
            403,
            'Only the SPMU Action Officer may archive the fully accomplished Laundry Form.'
        );

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
                ->with([
                    'lines.custodyLine',
                    'custody.lines',
                    'custody.borrower',
                ])
                ->findOrFail($laundryJob->id);

            if ($job->status === 'LAUNDRY_COMPLETED') {
                return;
            }

            if (! in_array($job->status, ['AWAITING_FINAL_FORM_UPLOAD', 'FORM_REPLACEMENT_REQUIRED'], true)) {
                throw ValidationException::withMessages([
                    'evidence' => 'SPMU must first physically receive and inspect the cleaned linen and sign the final acceptance portion of the Laundry Form.',
                ]);
            }

            $allLinenAccountedBySpmu = $job->lines->every(function ($line): bool {
                $custodyLine = $line->custodyLine;

                return $custodyLine
                    && (float) $custodyLine->returned_quantity >= (float) $custodyLine->actual_released_quantity;
            });

            if (! $allLinenAccountedBySpmu) {
                throw ValidationException::withMessages([
                    'evidence' => 'The final Laundry Form may be uploaded only after SPMU has fully accounted for the cleaned linen during final return inspection.',
                ]);
            }

            $document = $this->currentLaundryDocument($job);
            $lineIds = $job->lines->pluck('custody_line_id')->all();

            $finalReturn = ReturnTransaction::query()
                ->where('custody_transaction_id', $job->custody_transaction_id)
                ->whereHas('lines', fn ($query) => $query->whereIn('custody_line_id', $lineIds))
                ->latest('received_at')
                ->first();

            if (! $finalReturn) {
                throw ValidationException::withMessages([
                    'evidence' => 'No final SPMU linen acceptance record was found. Complete the physical return inspection before uploading the signed form.',
                ]);
            }

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
                'status' => 'LAUNDRY_COMPLETED',
                'form_verified_by_user_id' => $request->user()->id,
                'form_verified_at' => now(),
                'completed_at' => now(),
            ]);

            $job->custody->lines()
                ->whereHas(
                    'requestItem.inventoryItem',
                    fn ($query) => $query->where('laundry_required', true)
                )
                ->update([
                    'compliance_status' => 'LAUNDRY_COMPLETED',
                ]);

            $this->syncCustodyAfterLaundryCompletion($job);

            $audit->record(
                'LAUNDRY_FINAL_FORM_UPLOADED_AND_SETTLED',
                $job,
                after: [
                    'evidence_submission_id' => $submission->id,
                    'status' => 'LAUNDRY_COMPLETED',
                    'uploaded_by_user_id' => $request->user()->id,
                    'spmu_final_return_id' => $finalReturn->id,
                    'archived_by' => 'SPMU_ACTION_OFFICER',
                ]
            );

            $notifications->send(
                'LAUNDRY_COMPLETED',
                collect([$job->custody->borrower]),
                "The fully accomplished Laundry Form for {$job->custody->custody_no} was archived by the SPMU Action Officer after final acceptance. The Laundry transaction is completed/settled.",
                $job,
                ['SYSTEM', 'EMAIL']
            );

            $notifications->send(
                'LAUNDRY_FINAL_FORM_ARCHIVED',
                $this->spmuRecipients(),
                "The SPMU Action Officer archived the fully signed final Laundry Form for {$job->custody->custody_no}. The Laundry transaction is now completed/settled.",
                $job,
                ['SYSTEM']
            );
        }, 3);

        return redirect()
            ->route('laundry.spmu.show', $laundryJob)
            ->with('status', 'Fully accomplished Laundry Form archived. Laundry transaction completed/settled.');
    }

    private function syncCustodyAfterLaundryCompletion(LaundryJob $job): void
    {
        $custody = $job->custody()->with('lines')->firstOrFail();

        $allReturned = $custody->lines->every(
            fn ($line) => (float) $line->returned_quantity >= (float) $line->actual_released_quantity
        );

        if (! $allReturned) {
            if ($custody->status === 'OBLIGATION_OPEN') {
                $custody->update(['status' => 'RETURN_PROCESSING', 'closed_at' => null]);
            }

            return;
        }

        $hasOpenIncident = Incident::query()
            ->where('custody_transaction_id', $custody->id)
            ->whereNotIn('status', ['RESOLVED', 'CLOSED'])
            ->exists();

        $hasOpenLegacyLaundry = LaundryRecord::query()
            ->whereHas(
                'returnLine.custodyLine',
                fn ($query) => $query->where('custody_transaction_id', $custody->id)
            )
            ->where('status', '!=', 'VERIFIED')
            ->exists();

        $hasOpenOverdue = OverdueCase::query()
            ->where('custody_transaction_id', $custody->id)
            ->where('status', '!=', 'RESOLVED')
            ->exists();

        $hasOpenGatePass = $custody->gatePass()
            ->where('status', '!=', 'VERIFIED')
            ->exists();

        $hasOpenObligation = $hasOpenIncident
            || $hasOpenLegacyLaundry
            || $hasOpenOverdue
            || $hasOpenGatePass;

        $custody->update([
            'status' => $hasOpenObligation ? 'OBLIGATION_OPEN' : 'CLOSED',
            'closed_at' => $hasOpenObligation ? null : now(),
        ]);
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
                'evidence' => 'The current Laundry Form is unavailable. Ask SPMU to regenerate the physical form before uploading.',
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
