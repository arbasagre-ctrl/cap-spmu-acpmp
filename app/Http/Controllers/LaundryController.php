<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\EvidenceSubmission;
use App\Models\GeneratedDocument;
use App\Models\Incident;
use App\Models\LaundryJob;
use App\Models\LaundryRecord;
use App\Models\OverdueCase;
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

    /**
     * Confirm the physical handover from the borrower to the Laundry Area. Laundry Personnel are not system users. The Action Officer
     * only records that Laundry Personnel physically received the linen and
     * wet-signed the "Received by" cell on the same printed Laundry Form.
     *
     * This is the point at which the Laundry requirement stops blocking the
     * borrower's transaction. Actual washing may happen later as an internal
     * Laundry Area processing step.
     */
    public function receive(
        Request $request,
        LaundryJob $laundryJob,
        AuditService $audit,
        NotificationService $notifications
    ): RedirectResponse {
        $this->authorizeSpmuActionOfficer($request);

        $data = $request->validate([
            'laundry_received_signature_confirmed' => ['required', 'accepted'],
            'worker_remarks' => ['nullable', 'string', 'max:2000'],
        ], [
            'laundry_received_signature_confirmed.accepted' => 'Confirm that Laundry Personnel physically received the linen and signed the Received by portion of the same printed Laundry Form.',
        ]);

        DB::transaction(function () use ($request, $laundryJob, $audit, $notifications, $data): void {
            $job = LaundryJob::query()
                ->lockForUpdate()
                ->with([
                    'lines.custodyLine.returnLines',
                    'lines.custodyLine.requestItem.inventoryItem',
                    'custody.lines',
                    'custody.borrower',
                ])
                ->findOrFail($laundryJob->id);

            if (in_array($job->status, ['TURNED_OVER_TO_LAUNDRY', 'LAUNDRY_COMPLETED'], true)) {
                return;
            }

            if ($job->status !== 'FOR_LAUNDRY') {
                throw ValidationException::withMessages([
                    'laundry' => 'This linen case is not currently awaiting physical turnover to Laundry.',
                ]);
            }

            $allLinenAccountedBySpmu = $job->lines->isNotEmpty()
                && $job->lines->every(function ($line): bool {
                    $custodyLine = $line->custodyLine;

                    return $custodyLine
                        && (float) $custodyLine->returned_quantity >= (float) $custodyLine->actual_released_quantity;
                });

            if (! $allLinenAccountedBySpmu) {
                throw ValidationException::withMessages([
                    'laundry' => 'Record the SPMU Return Inspection for all linen first. Laundry turnover is confirmed only after the borrower has physically returned and SPMU has accounted for the issued linen.',
                ]);
            }

            $totalForInternalLaundry = 0;

            foreach ($job->lines as $line) {
                /*
                 * Only quantities whose return disposition is LAUNDRY enter
                 * the internal wash queue. Missing/lost/destroyed/damaged
                 * quantities are already handled by the return/accountability
                 * workflow and are not falsely counted as Laundry receipts.
                 */
                $received = (float) $line->custodyLine->returnLines
                    ->where('disposition_state', 'LAUNDRY')
                    ->sum('quantity_received');

                $line->update([
                    'received_quantity' => (int) round($received),
                ]);

                $totalForInternalLaundry += $received;
            }

            $nextStatus = $totalForInternalLaundry > 0
                ? 'TURNED_OVER_TO_LAUNDRY'
                : 'LAUNDRY_COMPLETED';

            $job->update([
                'status' => $nextStatus,
                'worker_name' => $request->user()->full_name,
                'worker_received_at' => $job->worker_received_at ?: now(),
                'worker_remarks' => $data['worker_remarks'] ?? $job->worker_remarks,
                'completed_at' => $nextStatus === 'LAUNDRY_COMPLETED' ? now() : null,
            ]);

            $job->custody->lines()
                ->whereHas(
                    'requestItem.inventoryItem',
                    fn ($query) => $query->where('laundry_required', true)
                )
                ->update([
                    'compliance_status' => $nextStatus === 'LAUNDRY_COMPLETED'
                        ? 'LAUNDRY_COMPLETED'
                        : 'INTERNAL_LAUNDRY',
                ]);

            $this->syncCustodyAfterLaundryTurnover($job);

            $audit->record(
                'LAUNDRY_PHYSICAL_TURNOVER_CONFIRMED',
                $job,
                after: [
                    'status' => $nextStatus,
                    'recorded_by_user_id' => $request->user()->id,
                    'laundry_received_at' => now()->toIso8601String(),
                    'laundry_received_wet_signature_confirmed' => true,
                    'internal_laundry_quantity' => (int) round($totalForInternalLaundry),
                    'custody_status' => $job->custody->fresh()->status,
                ]
            );

            $notifications->send(
                'LAUNDRY_USED_LINEN_RECEIVED',
                collect([$job->custody->borrower]),
                "Laundry Personnel physically received the returned linen for {$job->custody->custody_no}. Your linen-return obligation is complete. Washing now continues internally in the Laundry Area until clean/serviceable linen is Available.",
                $job,
                ['SYSTEM', 'EMAIL']
            );

            $notifications->send(
                'LAUNDRY_INTERNAL_QUEUE',
                $this->spmuRecipients(),
                $nextStatus === 'TURNED_OVER_TO_LAUNDRY'
                    ? "Returned linen for {$job->custody->custody_no} was turned over to Laundry and is now in the internal laundry queue."
                    : "The linen return for {$job->custody->custody_no} was reconciled with no quantity remaining for internal laundry.",
                $job,
                ['SYSTEM']
            );
        }, 3);

        return back()->with(
            'status',
            'Laundry turnover confirmed. The borrower transaction no longer waits for the washing cycle.'
        );
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
     * Internal Laundry processing step. It can happen hours or days after the
     * borrower was already cleared. The Action Officer simply records the
     * quantity that came back clean versus the quantity that needs maintenance.
     * No borrower incident is created from this internal post-turnover step.
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
            'lines' => ['required', 'array'],
            'lines.*.cleaned_quantity' => ['required', 'integer', 'min:0'],
            'lines.*.damaged_quantity' => ['required', 'integer', 'min:0'],
            'lines.*.remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $laundryJob, $audit, $notifications, $data): void {
            $job = LaundryJob::query()
                ->lockForUpdate()
                ->with([
                    'lines.custodyLine.requestItem.inventoryItem',
                    'custody.borrower',
                ])
                ->findOrFail($laundryJob->id);

            if ($job->status === 'LAUNDRY_COMPLETED') {
                return;
            }

            if ($job->status !== 'TURNED_OVER_TO_LAUNDRY') {
                throw ValidationException::withMessages([
                    'laundry' => 'Confirm the physical Laundry turnover first. Internal laundry completion does not begin until Laundry Personnel has received the returned linen.',
                ]);
            }

            $normalized = [];

            foreach ($job->lines as $line) {
                $values = $data['lines'][$line->id] ?? null;

                if (! is_array($values)) {
                    throw ValidationException::withMessages([
                        'lines' => 'Record the cleaned and maintenance quantities for every linen item.',
                    ]);
                }

                $received = (int) round((float) ($line->received_quantity ?? 0));
                $cleaned = (int) $values['cleaned_quantity'];
                $damaged = (int) $values['damaged_quantity'];

                if (($cleaned + $damaged) !== $received) {
                    $description = $line->custodyLine?->requestItem?->description_snapshot ?: 'Linen item';

                    throw ValidationException::withMessages([
                        'lines' => $description.': cleaned plus maintenance quantity must equal the internal Laundry quantity of '.$received.'.',
                    ]);
                }

                $normalized[$line->id] = [
                    'cleaned' => $cleaned,
                    'damaged' => $damaged,
                    'remarks' => $values['remarks'] ?? null,
                ];
            }

            $transactionId = DB::table('inventory_transactions')->insertGetId([
                'actor_user_id' => $request->user()->id,
                'transaction_type' => 'LAUNDRY_COMPLETION',
                'source_type' => LaundryJob::class,
                'source_id' => $job->id,
                'reason' => 'Internal Laundry processing completed after borrower turnover was already settled.',
                'correlation_id' => (string) Str::uuid(),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($job->lines as $line) {
                $values = $normalized[$line->id];
                $itemId = $line->custodyLine->requestItem->inventory_item_id;

                foreach ([
                    ['AVAILABLE', $values['cleaned']],
                    ['DAMAGED_MAINTENANCE', $values['damaged']],
                ] as [$state, $quantity]) {
                    if ($quantity <= 0) {
                        continue;
                    }

                    DB::table('inventory_transaction_lines')->insert([
                        'inventory_transaction_id' => $transactionId,
                        'inventory_item_id' => $itemId,
                        'from_state' => 'LAUNDRY',
                        'to_state' => $state,
                        'quantity' => $quantity,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $line->update([
                    'issue_type' => $values['damaged'] > 0 ? 'DAMAGED' : 'NONE',
                    'affected_quantity' => $values['damaged'],
                    'completed_quantity' => $values['cleaned'],
                    'remarks' => $values['remarks'],
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
                "Internal laundry processing for {$job->custody->custody_no} was completed. Clean linen was restored to Available and any maintenance quantity was routed to maintenance.",
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
     * Archive the same travelling physical Laundry Form after it has the
     * Laundry Personnel wet signatures. Archiving is useful evidence, but it
     * is no longer a prerequisite for borrower clearance or for the internal
     * washing schedule.
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

            if (! in_array($job->status, ['TURNED_OVER_TO_LAUNDRY', 'LAUNDRY_COMPLETED'], true)) {
                throw ValidationException::withMessages([
                    'evidence' => 'Archive the signed Laundry Form only after Laundry Personnel has physically received the returned linen and signed Received by.',
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

        return back()->with('status', 'Signed Laundry Form archived successfully.');
    }

    private function syncCustodyAfterLaundryTurnover(LaundryJob $job): void
    {
        app(CustodyService::class)->reconcileTransactionStatus($job->custody);
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
