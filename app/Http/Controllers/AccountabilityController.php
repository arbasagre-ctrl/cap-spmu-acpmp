<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Enums\UserRole;
use App\Models\BillingStatement;
use App\Models\BorrowerViolation;
use App\Models\BorrowerRestriction;
use App\Models\CustodyTransaction;
use App\Models\EvidenceSubmission;
use App\Models\GeneratedDocument;
use App\Models\Incident;
use App\Models\LaundryJob;
use App\Models\LaundryRecord;
use App\Models\OverdueCase;
use App\Models\Payment;
use App\Models\Penalty;
use App\Models\Sanction;
use App\Models\User;
use App\Services\AuditService;
use App\Services\BorrowerObligationService;
use App\Services\LateReturnService;
use App\Services\CustodyService;
use App\Services\InventoryService;
use App\Services\DocumentService;
use App\Services\NotificationService;
use App\Services\ProtectedFileService;
use App\Services\PolicyService;
use App\Services\SignatureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccountabilityController extends Controller
{
    public function index(Request $request, PolicyService $policy): View
    {
        $incidentQuery = Incident::with(['borrower', 'evidenceFile', 'custody.request', 'custody.lines.requestItem', 'lines', 'documents'])->latest('reported_at');
        $billingQuery = BillingStatement::with(['borrower', 'lines.penalty', 'payments.verifiedBy', 'documents'])->latest('issued_at');
        $restrictionQuery = BorrowerRestriction::with(['borrower', 'custody.request', 'sanction.documents', 'imposedBy'])->latest('effective_from');
        $overdueQuery = OverdueCase::with([
            'borrower',
            'custody.lines',
            'custody.request',
            'custody.returns',
            'custody.laundryJob',
            'penalties',
            'confirmedBy',
            'documents',
        ])->latest('overdue_started_at');
        $violationQuery = BorrowerViolation::with(['borrower', 'custody.request', 'academicPeriod', 'sanction'])
            ->latest('detected_at');
        $sanctionQuery = Sanction::with(['borrower', 'academicPeriod', 'violation', 'confirmedBy', 'documents'])
            ->latest('confirmed_at');

        if (strtoupper((string) $request->session()->get('active_workspace')) === 'BORROWER') {
            $incidentQuery->where('borrower_user_id', $request->user()->id);
            $billingQuery->where('borrower_user_id', $request->user()->id);
            $restrictionQuery->where('borrower_user_id', $request->user()->id);
            $overdueQuery->where('borrower_user_id', $request->user()->id);
            $violationQuery->where('borrower_user_id', $request->user()->id);
            $sanctionQuery->where('borrower_user_id', $request->user()->id);
        }

        $incidents = $incidentQuery->get();
        $billings = $billingQuery->get();
        $restrictions = $restrictionQuery->get();
        $overdueCases = $overdueQuery->get();
        $violations = $violationQuery->get();
        $sanctions = $sanctionQuery->get();

        $incidentOffensePreviews = [];
        $violationOffensePreviews = [];

        if ($request->user()?->access_classification === AccessClassification::SpmuHead) {
            foreach ($incidents as $incident) {
                $incidentOffensePreviews[$incident->id] = $policy->incidentOffensePreview($incident);
            }

            foreach ($violations->where('status', 'PENDING_REVIEW') as $violation) {
                $violationOffensePreviews[$violation->id] = $policy->violationOffensePreview($violation);
            }
        }

        return view('accountability.index', [
            'incidents' => $incidents,
            'billings' => $billings,
            'restrictions' => $restrictions,
            'overdueCases' => $overdueCases,
            'violations' => $violations,
            'sanctions' => $sanctions,
            'incidentOffensePreviews' => $incidentOffensePreviews,
            'violationOffensePreviews' => $violationOffensePreviews,
            'resolvedHistory' => $this->resolvedHistory($billings, $overdueCases, $incidents),
            // Consumed by the Active Accountability Cases table: the
            // Head/Admin dashboard's "Active Restrictions" card links here
            // with ?view=restrictions so it lands pre-filtered to exactly
            // the restriction-carrying rows it counted, not the unfiltered
            // table.
            'restrictionsFocusRequested' => $request->query('view') === 'restrictions',
        ]);
    }

    /**
     * Accountability cases that have reached a final outcome.
     *
     * Read-only history assembled from the records that already exist: the
     * billing and its verified payment, the penalty that links the billing back
     * to its overdue case, and the frozen late-return assessment on that case.
     * Nothing is recalculated and nothing is duplicated into new storage.
     *
     * Outcomes are kept distinct. A settled billing is Paid; a waived or voided
     * one is not, and is never relabelled as such.
     *
     * Moved to BorrowerObligationService so the borrower dashboard's
     * resolved_count and this workspace's Resolved History always agree -
     * both now read the one implementation there.
     *
     * @param  \Illuminate\Support\Collection<int, BillingStatement>  $billings
     * @param  \Illuminate\Support\Collection<int, OverdueCase>  $overdueCases
     * @param  \Illuminate\Support\Collection<int, Incident>  $incidents
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function resolvedHistory(Collection $billings, Collection $overdueCases, Collection $incidents): Collection
    {
        return app(BorrowerObligationService::class)->resolvedHistory($billings, $overdueCases, $incidents);
    }

    /**
     * Financial late-return assessment. This is separate from sanctions.
     *
     * The assessment itself is entirely system-derived from the recorded
     * physical return, so it is finalized straight to the SPMU Head by
     * LateReturnService::assess() when the return is recorded - there is no
     * separate Action Officer confirmation action.
     *
     * There is also no "return for correction" action: no route anywhere
     * edits an authoritative physical return record after it is recorded
     * (the Return Inspection form has nothing left to submit once a custody
     * is fully returned), so such an action could never lead anywhere. The
     * Head's only meaningful decision on a finalized assessment is below.
     */
    public function billOverdue(
        Request $request,
        OverdueCase $overdue,
        DocumentService $documents,
        AuditService $audit,
        NotificationService $notifications,
        SignatureService $signatures
    ): RedirectResponse {
        $this->authorizeSpmu($request);

        /* Approving the assessment is the SPMU Head's decision. */
        abort_unless(
            $request->user()?->access_classification === AccessClassification::SpmuHead,
            403
        );

        $data = $request->validate([
            'basis' => ['required', 'string', 'max:2000'],
            'due_at' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $overdue->loadMissing('custody.lines');

        if ($overdue->status === LateReturnService::STATUS_OVERDUE) {
            return back()->withErrors([
                'overdue' => 'The item is still overdue. Record the physical return first so the final late-return fee can be determined.',
            ]);
        }

        /*
         * The assessment is entirely system-derived from the recorded
         * physical return, so the Head may decide from either pre-final
         * stage: a freshly finalized assessment, or one the Head previously
         * returned for correction. There is no separate Action Officer
         * confirmation gate.
         */
        if (! in_array($overdue->status, [
            LateReturnService::STATUS_FOR_AO_CONFIRMATION,
            LateReturnService::STATUS_FOR_HEAD_APPROVAL,
        ], true)) {
            return back()->withErrors([
                'overdue' => 'This late-return case is not ready for a new Billing Statement.',
            ]);
        }

        if ($overdue->actual_return_date === null) {
            return back()->withErrors([
                'overdue' => 'A recorded physical return date is required before the Late Return Fee Form can be generated.',
            ]);
        }

        if (! $overdue->custody->lines->every(
            fn ($line) => (float) $line->returned_quantity >= (float) $line->actual_released_quantity
        )) {
            return back()->withErrors([
                'overdue' => 'Record the complete physical return first so the final late-return fee can be determined.',
            ]);
        }

        if ((float) $overdue->accrued_amount <= 0 || $overdue->rate_snapshot === null) {
            return back()->withErrors([
                'overdue' => 'Configure the approved late-return fee policy before issuing a Billing Statement.',
            ]);
        }

        if ($overdue->penalties()->where('status', '!=', 'VOID')->exists()) {
            return back()->withErrors([
                'overdue' => 'This overdue case already has an assessed financial charge.',
            ]);
        }

        try {
            $billing = DB::transaction(function () use ($overdue, $request, $data, $documents, $audit, $notifications, $signatures): BillingStatement {
            $penalty = Penalty::query()->create([
                'borrower_user_id' => $overdue->borrower_user_id,
                'custody_transaction_id' => $overdue->custody_transaction_id,
                'overdue_case_id' => $overdue->id,
                'assessed_by_user_id' => $request->user()->id,
                'penalty_type' => 'LATE_RETURN_FEE',
                'offense_level' => null,
                'basis' => $data['basis'],
                'rate_snapshot' => $overdue->rate_snapshot,
                'amount' => $overdue->accrued_amount,
                'status' => 'ASSESSED',
                'assessed_at' => now(),
            ]);

            $billing = BillingStatement::query()->create([
                'billing_no' => 'BILL-LATE-'.now()->format('YmdHis').'-'.$overdue->id,
                'borrower_user_id' => $overdue->borrower_user_id,
                'responsible_spmu_user_id' => $request->user()->id,
                'issued_at' => now(),
                'due_at' => $data['due_at'] ?? null,
                'total_amount' => $penalty->amount,
                'status' => 'ISSUED',
                'remarks' => 'Late-return fee for '.$overdue->custody->custody_no.'. Administrative sanction, if any, is handled separately.',
            ]);

            $billing->lines()->create([
                'penalty_id' => $penalty->id,
                'source_key' => 'OVERDUE_CASE:'.$overdue->id,
                'line_type' => 'LATE_RETURN_FEE',
                'description' => 'Date-based late-return fee',
                'basis' => $data['basis'],
                'amount' => $penalty->amount,
            ]);

            BorrowerRestriction::query()
                ->forCustody($overdue->custody)
                ->where('restriction_type', 'OVERDUE_RETURN')
                ->where('status', 'ACTIVE')
                ->update([
                    'penalty_id' => $penalty->id,
                    'billing_statement_id' => $billing->id,
                    'reason' => 'Outstanding late-return billing '.$billing->billing_no.'.',
                ]);

            $overdue->update(['status' => LateReturnService::STATUS_AWAITING_PAYMENT]);

            $billingSignature = $signatures->snapshot(
                $request->user(),
                'LATE_RETURN_BILLING_STATEMENT',
                'SPMU Head',
                $billing,
                [
                    'custody_no' => $overdue->custody?->custody_no,
                    'late_days' => (int) $overdue->late_days,
                    'amount' => (float) $penalty->amount,
                ]
            );

            $billingDocument = $documents->billingStatement($billing, $billingSignature);
            $lateReturnNotice = $documents->lateReturnNotice(
                $overdue,
                $request->user(),
                'Billing Required',
                $data['basis'],
                $billingSignature
            );

            $audit->record(
                'LATE_RETURN_FEE_BILLED',
                $billing,
                reason: $data['basis'],
                after: [
                    'amount' => $penalty->amount,
                    'rate' => $penalty->rate_snapshot,
                    'sanction_created' => false,
                    'head_signature_snapshot_id' => $billingSignature->id,
                    'generated_document_id' => $billingDocument->id,
                    'late_return_notice_document_id' => $lateReturnNotice->id,
                ]
            );

            $billing->loadMissing('borrower');
            if ($billing->borrower) {
                $notifications->send(
                    'LATE_RETURN_NOTICE_ISSUED',
                    collect([$billing->borrower]),
                    "SPMU completed the late-return assessment for {$overdue->custody?->custody_no}. {$this->lateReturnBorrowerContext($overdue)} The formal Late Return Notice is available under My Obligations.",
                    $overdue,
                    ['SYSTEM']
                );

                $notifications->send(
                    'LATE_RETURN_BILLING_STATEMENT_ISSUED',
                    collect([$billing->borrower]),
                    "Billing Statement {$billing->billing_no} has been issued for the late return under {$overdue->custody?->custody_no}. {$this->lateReturnBorrowerContext($overdue)} Preview it in My Obligations, pay through the CSPC Cashier, then present the official receipt to the SPMU Action Officer for recording and confirmation.",
                    $billing,
                    ['SYSTEM', 'EMAIL']
                );
            }

            return $billing;
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->isDuplicateBillingAttempt($exception)) {
                throw $exception;
            }

            return back()->withErrors([
                'overdue' => 'This overdue case already has a Billing Statement.',
            ]);
        }

        return back()->with('status', "Late Return Notice and Billing Statement {$billing->billing_no} were issued by the SPMU Head/Admin. The borrower was notified to pay through the CSPC Cashier and present the official receipt to the SPMU Action Officer afterward.");
    }

    public function billIncident(
        Request $request,
        Incident $incident,
        DocumentService $documents,
        AuditService $audit,
        NotificationService $notifications
    ): RedirectResponse {
        $this->authorizeSpmu($request);
        abort_unless(
            $request->user()?->access_classification === AccessClassification::SpmuHead,
            403,
            'Billing Statement issuance is an SPMU Head/Admin responsibility.'
        );

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'basis' => ['required', 'string', 'max:2000'],
            'due_at' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        if ($incident->status !== 'FOR_BILLING') {
            return back()->withErrors([
                'incident' => 'The SPMU Head must first record Billing / Payment Required as the formal accountability decision before a property Billing Statement can be generated.',
            ]);
        }

        if (DB::table('billing_lines')->where('incident_id', $incident->id)->exists()) {
            return back()->withErrors([
                'incident' => 'This accountability case already has a Billing Statement.',
            ]);
        }

        try {
            $billing = DB::transaction(function () use ($incident, $request, $data, $documents, $audit): BillingStatement {
            $billing = BillingStatement::query()->create([
                'billing_no' => 'BILL-'.now()->format('YmdHis').'-'.$incident->id,
                'borrower_user_id' => $incident->borrower_user_id,
                'responsible_spmu_user_id' => $request->user()->id,
                'issued_at' => now(),
                'due_at' => $data['due_at'] ?? null,
                'total_amount' => $data['amount'],
                'status' => 'ISSUED',
                'remarks' => 'Configurable property/accountability charge linked to '.$incident->incident_no,
            ]);

            $billing->lines()->create([
                'incident_id' => $incident->id,
                'source_key' => 'INCIDENT:'.$incident->id,
                'line_type' => 'PROPERTY_ACCOUNTABILITY_CHARGE',
                'description' => $incident->incident_type.' accountability charge',
                'basis' => $data['basis'],
                'amount' => $data['amount'],
            ]);

            $incident->update([
                'appraisal_amount' => $data['amount'],
                'status' => 'BILLING_PENDING',
            ]);

            BorrowerRestriction::query()->updateOrCreate(
                [
                    'borrower_user_id' => $incident->borrower_user_id,
                    'custody_transaction_id' => $incident->custody_transaction_id,
                    'incident_id' => $incident->id,
                    'status' => 'ACTIVE',
                ],
                [
                    'restriction_type' => 'UNRESOLVED_PROPERTY_OBLIGATION',
                    'reason' => 'Open billing statement '.$billing->billing_no,
                    'effective_from' => now(),
                    'imposed_by_user_id' => $request->user()->id,
                    'billing_statement_id' => $billing->id,
                ]
            );

            $billingDocument = $documents->billingStatement($billing);
            $audit->record(
                'ACCOUNTABILITY_BILLING_STATEMENT_ISSUED',
                $billing,
                reason: $data['basis'],
                after: [
                    'amount' => $data['amount'],
                    'source' => $incident->incident_no,
                    'generated_document_id' => $billingDocument->id,
                ]
            );

            return $billing;
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->isDuplicateBillingAttempt($exception)) {
                throw $exception;
            }

            return back()->withErrors([
                'incident' => 'This accountability case already has a Billing Statement.',
            ]);
        }

        $incident->loadMissing('borrower');
        if ($incident->borrower) {
            $notifications->send(
                'ACCOUNTABILITY_BILLING_STATEMENT_ISSUED',
                collect([$incident->borrower]),
                "Billing Statement {$billing->billing_no} has been issued for accountability case {$incident->incident_no}. {$this->incidentBorrowerContext($incident)} Review/download it in My Obligations, pay through the CSPC Cashier, then present the official receipt to the SPMU Action Officer for recording and confirmation.",
                $billing,
                ['SYSTEM', 'EMAIL']
            );
        }

        return back()->with('status', "Billing Statement {$billing->billing_no} issued by the SPMU Head/Admin. The borrower was notified and can present it to the CSPC Cashier.");
    }

    /**
     * The Action Officer receives the official CSPC Cashier receipt, checks it
     * before saving, uploads the scan, and confirms the payment in one step.
     * There is no second SPMU verification stage for newly recorded payments.
     */
    public function recordPayment(
        Request $request,
        BillingStatement $billing,
        ProtectedFileService $files,
        AuditService $audit,
        NotificationService $notifications
    ): RedirectResponse {
        $this->authorizeSpmu($request);
        abort_unless(
            $request->user()?->access_classification === AccessClassification::SpmuOfficer,
            403,
            'Only the SPMU Action Officer may record and confirm Cashier payments.'
        );
        abort_if(in_array($billing->status, ['SETTLED', 'WAIVED', 'VOID'], true), 403);

        $data = $request->validate([
            'evidence' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:5120'],
            'official_receipt_no' => ['required', 'string', 'max:255'],
            'receipt_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'official_receipt_no' => 'Cashier Receipt No.',
            'receipt_date' => 'Receipt Date',
            'amount' => 'Amount Paid',
            'evidence' => 'Scanned Paid Receipt',
        ]);

        $file = $files->storeUpload(
            $data['evidence'],
            'payment-evidence',
            'CSPC_CASHIER_PAID_RECEIPT'
        );

        [$payment, $settled, $remainingBalance] = DB::transaction(function () use (
            $billing,
            $request,
            $data,
            $file,
            $audit
        ): array {
            $billing = BillingStatement::query()->lockForUpdate()->findOrFail($billing->id);

            if (in_array($billing->status, ['SETTLED', 'WAIVED', 'VOID'], true)) {
                abort(403);
            }

            $confirmedBefore = (float) $billing->payments()
                ->where('status', 'VERIFIED')
                ->sum('amount');
            $remainingBefore = max(0.0, (float) $billing->total_amount - $confirmedBefore);
            $amount = (float) $data['amount'];

            if ($amount > $remainingBefore + 0.0001) {
                throw ValidationException::withMessages([
                    'amount' => 'Amount Paid cannot be more than the remaining balance of PHP '
                        .number_format($remainingBefore, 2).'.',
                ]);
            }

            $payment = Payment::query()->create([
                'billing_statement_id' => $billing->id,
                'evidence_file_id' => $file->id,
                'recorded_by_user_id' => $request->user()->id,
                'verified_by_user_id' => $request->user()->id,
                'official_receipt_no' => $data['official_receipt_no'],
                'receipt_date' => $data['receipt_date'],
                'amount' => $data['amount'],
                'status' => 'VERIFIED',
                'submitted_at' => now(),
                'verified_at' => now(),
                'verification_remarks' => $data['remarks'] ?? null,
                'rejection_reason' => null,
            ]);

            $settled = $this->settleBillingIfFullyPaid($billing, $request->user()->id, $audit);
            $confirmedAfter = (float) $billing->payments()
                ->where('status', 'VERIFIED')
                ->sum('amount');
            $remainingBalance = max(0.0, (float) $billing->total_amount - $confirmedAfter);

            if (! $settled) {
                // Keep the Billing Statement open when only part of the balance was paid.
                $billing->update(['status' => 'ISSUED']);
            }

            $audit->record(
                'CASHIER_PAYMENT_CONFIRMED',
                $payment,
                reason: $data['remarks'] ?? null,
                after: [
                    'billing_status' => $billing->fresh()->status,
                    'receipt_no' => $payment->official_receipt_no,
                    'receipt_date' => $payment->receipt_date,
                    'amount' => $payment->amount,
                    'remaining_balance' => $remainingBalance,
                    'confirmed_by_user_id' => $request->user()->id,
                ]
            );

            return [$payment, $settled, $remainingBalance];
        }, 3);

        $billing->refresh()->loadMissing('borrower');

        if ($billing->borrower) {
            $billingContext = $this->billingBorrowerContext($billing);
            $message = $settled
                ? "Payment for {$billing->billing_no} was recorded and confirmed by the SPMU Action Officer. {$billingContext} The billing is now settled."
                : "Payment for {$billing->billing_no} was recorded and confirmed by the SPMU Action Officer. {$billingContext} Remaining balance: PHP "
                    .number_format($remainingBalance, 2).'.';

            $notifications->send(
                'PAYMENT_VERIFIED',
                collect([$billing->borrower]),
                $message,
                $billing
            );
        }

        if ($settled) {
            $this->notifyResolvedPropertyBilling($billing, $notifications, 'Cashier payment settled');
        }

        return back()->with(
            'status',
            $settled
                ? 'Cashier payment confirmed. The billing is now settled.'
                : 'Cashier payment confirmed. Remaining balance: PHP '.number_format($remainingBalance, 2).'.'
        );
    }

    /**
     * Kept only for older records that were created under the former two-step
     * receipt workflow. New payments are confirmed directly by recordPayment().
     */
    public function verifyPayment(
        Request $request,
        Payment $payment,
        AuditService $audit,
        NotificationService $notifications
    ): RedirectResponse {
        $this->authorizeSpmu($request);
        abort_unless(
            $request->user()?->access_classification === AccessClassification::SpmuOfficer,
            403
        );

        $data = $request->validate([
            'decision' => ['required', 'in:VERIFIED,REJECTED'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $payment->loadMissing('billingStatement');

        DB::transaction(function () use ($payment, $request, $audit, $notifications, $data): void {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $billing = BillingStatement::query()->lockForUpdate()->findOrFail($payment->billing_statement_id);

            if ($payment->status !== 'PENDING_VERIFICATION') {
                return;
            }

            if ($data['decision'] === 'REJECTED') {
                $payment->update([
                    'verified_by_user_id' => $request->user()->id,
                    'status' => 'REJECTED',
                    'verified_at' => now(),
                    'rejection_reason' => $data['remarks'] ?? 'Legacy payment record returned for correction.',
                    'verification_remarks' => $data['remarks'],
                ]);

                $billing->update(['status' => 'ISSUED']);
                $audit->record('PAYMENT_RECEIPT_REJECTED', $payment, reason: $data['remarks']);

                return;
            }

            $payment->update([
                'verified_by_user_id' => $request->user()->id,
                'status' => 'VERIFIED',
                'verified_at' => now(),
                'verification_remarks' => $data['remarks'],
                'rejection_reason' => null,
            ]);

            $legacySettled = $this->settleBillingIfFullyPaid($billing, $request->user()->id, $audit);

            $audit->record(
                'CASHIER_PAYMENT_VERIFIED',
                $payment,
                reason: $data['remarks'],
                after: [
                    'billing_status' => $billing->fresh()->status,
                    'receipt_no' => $payment->official_receipt_no,
                    'amount' => $payment->amount,
                    'legacy_payment_record' => true,
                ]
            );

            $billing->loadMissing('borrower');
            if ($billing->borrower) {
                $notifications->send(
                    'PAYMENT_VERIFIED',
                    collect([$billing->borrower]),
                    "CSPC Cashier receipt {$payment->official_receipt_no} for {$billing->billing_no} was confirmed by the SPMU Action Officer. {$this->billingBorrowerContext($billing)}",
                    $billing
                );
            }

            if ($legacySettled) {
                $this->notifyResolvedPropertyBilling($billing->fresh(), $notifications, 'Legacy Cashier payment settled');
            }
        }, 3);

        return back()->with('status', $data['decision'] === 'VERIFIED'
            ? 'Legacy Cashier payment record confirmed.'
            : 'Legacy payment record returned for correction.');
    }

    public function waive(
        Request $request,
        BillingStatement $billing,
        AuditService $audit,
        NotificationService $notifications
    ): RedirectResponse {
        abort_unless(
            $request->user()->access_classification === AccessClassification::SpmuHead,
            403,
            'Only the SPMU Head/Admin may authorize a billing waiver.'
        );

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        if (in_array($billing->status, ['SETTLED', 'WAIVED', 'VOID'], true)) {
            return back()->withErrors([
                'billing' => 'This Billing Statement already has a final status.',
            ]);
        }

        DB::transaction(function () use ($billing, $request, $data, $audit): void {
            $billing->update([
                'status' => 'WAIVED',
                'remarks' => trim(($billing->remarks ? $billing->remarks."\n" : '').'Authorized waiver: '.$data['reason']),
            ]);

            $incidentIds = $billing->lines()->whereNotNull('incident_id')->pluck('incident_id');
            $penaltyIds = $billing->lines()->whereNotNull('penalty_id')->pluck('penalty_id');

            $propertyIncidents = Incident::query()
                ->whereKey($incidentIds)
                ->whereNotIn('status', ['RESOLVED', 'CLOSED', 'VOID_CORRECTION'])
                ->get();

            foreach ($propertyIncidents as $propertyIncident) {
                $previousStatus = $propertyIncident->status;
                $propertyIncident->update(['status' => 'RESOLVED']);

                $audit->record(
                    'PROPERTY_ACCOUNTABILITY_CASE_RESOLVED',
                    $propertyIncident,
                    reason: 'Billing Statement waived by the SPMU Head/Admin. '.$data['reason'],
                    before: ['status' => $previousStatus],
                    after: [
                        'status' => 'RESOLVED',
                        'resolution_outcome' => 'BILLING_WAIVED',
                        'billing_statement_id' => $billing->id,
                        'resolved_by_user_id' => $request->user()->id,
                    ]
                );
            }

            BorrowerRestriction::query()
                ->where('billing_statement_id', $billing->id)
                ->where('status', 'ACTIVE')
                ->update([
                    'status' => 'LIFTED',
                    'effective_to' => now(),
                    'lifted_by_user_id' => $request->user()->id,
                ]);

            Penalty::query()->whereKey($penaltyIds)->update(['status' => 'WAIVED']);
            OverdueCase::query()
                ->whereHas('penalties', fn ($query) => $query->whereIn('penalties.id', $penaltyIds))
                ->update(['status' => 'RESOLVED']);

            $custodyIds = Incident::query()->whereKey($incidentIds)->pluck('custody_transaction_id')
                ->merge(Penalty::query()->whereKey($penaltyIds)->pluck('custody_transaction_id'))
                ->unique();

            foreach ($custodyIds as $custodyId) {
                $this->attemptCloseCustody((int) $custodyId);
            }

            $audit->record('BILLING_STATEMENT_WAIVED', $billing, reason: $data['reason']);
        }, 3);

        $billing->refresh();
        $this->notifyResolvedPropertyBilling($billing, $notifications, 'Authorized billing waiver');

        return back()->with('status', 'Authorized waiver recorded. Related property accountability and financial restrictions were re-evaluated; any separate administrative sanction remains unchanged.');
    }

    /**
     * Accountability case action endpoint. Head/Admin records the formal
     * decision; when that decision requires repair/replacement/compliance, the
     * Action Officer later uses the same endpoint only to verify completion.
     * Financial cases continue through billing settlement or an authorized
     * billing waiver instead of a manual compliance closeout.
     */
    public function resolveIncident(
        Request $request,
        Incident $incident,
        AuditService $audit,
        NotificationService $notifications,
        PolicyService $policy,
        DocumentService $documents,
        SignatureService $signatures,
        InventoryService $inventoryService
    ): RedirectResponse {
        $requestedOutcome = strtoupper(trim((string) $request->input('resolution_outcome')));

        /*
         * Property compliance is an operational verification step. The SPMU
         * Head/Admin already made the decision that repair/replacement or
         * another compliance action is required; the Action Officer is the
         * staff member who physically checks the completed requirement.
         */
        if ($requestedOutcome === 'COMPLIANCE_COMPLETED') {
            return $this->completeIncidentCompliance(
                $request,
                $incident,
                $audit,
                $notifications,
                $inventoryService
            );
        }

        abort_unless(
            $request->user()->access_classification === AccessClassification::SpmuHead,
            403,
            'Only the SPMU Head/Admin may record the accountability decision.'
        );

        $offensePreview = $policy->incidentOffensePreview($incident);
        $requiresOffenseDecision = $offensePreview['is_eligible']
            && ! $offensePreview['existing_sanction'];

        $data = $request->validate([
            'resolution_outcome' => ['required', 'in:NO_BORROWER_CHARGE,COMPLIANCE_REQUIRED,BILLING_REQUIRED,ADMINISTRATIVELY_CLEARED'],
            'compliance_action' => ['nullable', 'in:REPAIR,REPLACEMENT,RECOVERY'],
            'resolution_remarks' => ['required', 'string', 'max:2000'],
            'count_as_offense' => [$requiresOffenseDecision ? 'required' : 'nullable', 'boolean'],
            'requires_rslddp' => ['required_if:resolution_outcome,COMPLIANCE_REQUIRED', 'boolean'],
        ], [
            'count_as_offense.required' => 'Choose whether this eligible incident should count as an administrative offense.',
            'requires_rslddp.required_if' => 'Choose whether this confirmed property accountability requires an RSLDDP.',
        ]);

        $complianceAction = null;
        if ($data['resolution_outcome'] === 'COMPLIANCE_REQUIRED') {
            $complianceAction = strtoupper(trim((string) ($data['compliance_action'] ?? '')));
            $allowedComplianceActions = $this->allowedComplianceActions($incident);

            if ($complianceAction === '') {
                throw ValidationException::withMessages([
                    'compliance_action' => 'Select the required property compliance action.',
                ]);
            }

            if (! in_array($complianceAction, $allowedComplianceActions, true)) {
                throw ValidationException::withMessages([
                    'compliance_action' => 'The selected compliance action does not match the recorded property finding.',
                ]);
            }
        }

        $countAsOffense = $request->boolean('count_as_offense');
        /*
         * Billing (monetary settlement) has no other approved path anymore -
         * RSLDDP is always required. Compliance (repair/replacement) is a
         * per-case Head/Admin decision, since not every compliance case
         * needs RSLDDP paperwork.
         */
        $requiresRslddp = $data['resolution_outcome'] === 'BILLING_REQUIRED'
            ? true
            : (bool) ($data['requires_rslddp'] ?? false);
        $recordedSanction = null;
        $headSignature = null;
        $issuedSanctionDocument = null;
        $issuedRsldppDocument = null;

        if (DB::table('billing_lines')->where('incident_id', $incident->id)->exists()) {
            return back()->withErrors([
                'incident' => 'This property case already has a Billing Statement. Continue through billing settlement or an authorized billing waiver instead of recording another Head decision.',
            ]);
        }

        $outcomeLabel = match ($data['resolution_outcome']) {
            'NO_BORROWER_CHARGE' => 'No borrower liability / no charge',
            'COMPLIANCE_REQUIRED' => 'Compliance required — '.$this->complianceActionLabel($complianceAction),
            'BILLING_REQUIRED' => 'Billing / payment required',
            'ADMINISTRATIVELY_CLEARED' => 'Administratively cleared',
        };

        $isInterimDecision = in_array($data['resolution_outcome'], ['COMPLIANCE_REQUIRED', 'BILLING_REQUIRED'], true);

        DB::transaction(function () use ($incident, $request, $data, $complianceAction, $outcomeLabel, $isInterimDecision, $requiresRslddp, $audit, $notifications, $policy, $documents, $signatures, $countAsOffense, &$recordedSanction, &$headSignature, &$issuedSanctionDocument, &$issuedRsldppDocument): void {
            $incident = Incident::query()->lockForUpdate()->findOrFail($incident->id);

            if (in_array($incident->status, ['RESOLVED', 'CLOSED', 'VOID_CORRECTION'], true)) {
                return;
            }

            $previousStatus = $incident->status;
            $existingRemarks = trim((string) $incident->remarks);

            $headSignature = $signatures->snapshot(
                $request->user(),
                'ACCOUNTABILITY_HEAD_DECISION',
                'SPMU Head',
                $incident,
                [
                    'incident_no' => $incident->incident_no,
                    'resolution_outcome' => $data['resolution_outcome'],
                    'compliance_action' => $complianceAction,
                ]
            );

            $incident->update([
                'head_decision_signature_snapshot_id' => $headSignature->id,
                'head_decided_by_user_id' => $request->user()->id,
                'head_decided_at' => now(),
            ]);

            $decisionNote = 'SPMU Head decision: '.$outcomeLabel.'. '.$data['resolution_remarks'];

            if ($countAsOffense) {
                $recordedSanction = $policy->confirmIncidentOffense(
                    $incident,
                    $request->user(),
                    $data['resolution_remarks']
                );
            }

            if ($recordedSanction) {
                $recordedSanction->update(['signature_snapshot_id' => $headSignature->id]);

                /*
                 * Only a formal borrowing suspension gets a printed notice
                 * going forward (as "Suspension Notice"). A Written
                 * Reprimand still confirms/records the sanction itself -
                 * only its paper notice is skipped; no institutional
                 * requirement for one was found in this codebase.
                 *
                 * sanction_code is read directly off the Sanction row (it is
                 * snapshotted there at confirmation time, per
                 * PolicyService::confirmIncidentOffense()) rather than via
                 * its optional sanction_rule_id, which can be null for a
                 * manually-chosen "OTHER" sanction.
                 */
                if (strtoupper((string) $recordedSanction->sanction_code) === 'BORROWING_SUSPENSION') {
                    $issuedSanctionDocument = $documents->administrativeSanctionNotice(
                        $recordedSanction->fresh(),
                        $headSignature
                    );
                }
            }

            if ($isInterimDecision) {
                $nextStatus = match (true) {
                    $data['resolution_outcome'] === 'BILLING_REQUIRED' => 'RSLDDP_AWAITING_UPLOAD',
                    $requiresRslddp => 'COMPLIANCE_RSLDDP_PENDING',
                    default => 'COMPLIANCE_REQUIRED',
                };

                $incident->update([
                    'status' => $nextStatus,
                    'requires_rslddp' => $requiresRslddp,
                    'compliance_action' => $data['resolution_outcome'] === 'COMPLIANCE_REQUIRED' ? $complianceAction : null,
                    'remarks' => trim($existingRemarks.($existingRemarks !== '' ? "\n" : '').$decisionNote),
                ]);

                if ($requiresRslddp) {
                    $issuedRsldppDocument = $documents->rslddp($incident->fresh());
                }

                $audit->record(
                    'PROPERTY_ACCOUNTABILITY_HEAD_DECISION_RECORDED',
                    $incident,
                    reason: $data['resolution_remarks'],
                    before: ['status' => $previousStatus],
                    after: [
                        'status' => $nextStatus,
                        'resolution_outcome' => $data['resolution_outcome'],
                        'requires_rslddp' => $requiresRslddp,
                        'compliance_action' => $data['resolution_outcome'] === 'COMPLIANCE_REQUIRED' ? $complianceAction : null,
                        'administrative_offense_confirmed' => (bool) $recordedSanction,
                        'sanction_id' => $recordedSanction?->id,
                        'head_signature_snapshot_id' => $headSignature?->id,
                        'rslddp_document_id' => $issuedRsldppDocument?->id,
                        'sanction_notice_document_id' => $issuedSanctionDocument?->id,
                    ]
                );

                $incident->loadMissing('borrower');
                if ($incident->borrower) {
                    $incidentContext = $this->incidentBorrowerContext($incident);
                    $borrowerMessage = $nextStatus === 'RSLDDP_AWAITING_UPLOAD'
                        ? "Property accountability case {$incident->incident_no} was reviewed by the SPMU Head/Admin and requires formal settlement. {$incidentContext} An RSLDDP is being prepared for external signing/notarization. Once accomplished and forwarded, the official Billing Statement issued by the Accounting Office will appear in My Obligations for payment through the CSPC Cashier. Your linked borrowing restriction remains active until settlement is fully verified."
                        : "Property accountability case {$incident->incident_no} requires {$this->complianceActionLabel($complianceAction)}. {$incidentContext} Complete the required action, then present the property to the SPMU Action Officer for physical verification. The linked borrowing restriction remains active until verification is completed.";

                    $notifications->send(
                        'PROPERTY_ACCOUNTABILITY_HEAD_DECISION_RECORDED',
                        collect([$incident->borrower]),
                        $borrowerMessage,
                        $incident,
                        ['SYSTEM', 'EMAIL']
                    );
                }

                return;
            }

            $resolutionNote = 'SPMU Head resolution: '.$outcomeLabel.'. '.$data['resolution_remarks'];

            $incident->update([
                'status' => 'RESOLVED',
                'compliance_action' => null,
                'remarks' => trim($existingRemarks.($existingRemarks !== '' ? "\n" : '').$resolutionNote),
            ]);

            BorrowerRestriction::query()
                ->where('incident_id', $incident->id)
                ->where('status', 'ACTIVE')
                ->update([
                    'status' => 'LIFTED',
                    'effective_to' => now(),
                    'lifted_by_user_id' => $request->user()->id,
                ]);

            $this->attemptCloseCustody((int) $incident->custody_transaction_id);

            $audit->record(
                'PROPERTY_ACCOUNTABILITY_CASE_RESOLVED',
                $incident,
                reason: $data['resolution_remarks'],
                before: ['status' => $previousStatus],
                after: [
                    'status' => 'RESOLVED',
                    'resolution_outcome' => $data['resolution_outcome'],
                    'administrative_offense_confirmed' => (bool) $recordedSanction,
                    'sanction_id' => $recordedSanction?->id,
                    'head_signature_snapshot_id' => $headSignature?->id,
                    'sanction_notice_document_id' => $issuedSanctionDocument?->id,
                ]
            );

            $incident->loadMissing('borrower');
            if ($incident->borrower) {
                $notifications->send(
                    'PROPERTY_ACCOUNTABILITY_CASE_RESOLVED',
                    collect([$incident->borrower]),
                    "Property accountability case {$incident->incident_no} has been resolved by the SPMU Head/Admin. {$this->incidentBorrowerContext($incident)} The restriction linked to this property case has been lifted. Any separate administrative sanction or other active obligation/restriction still applies according to its own status.",
                    $incident,
                    ['SYSTEM', 'EMAIL']
                );
            }
        }, 3);

        if ($recordedSanction) {
            $this->notifyAdministrativeSanction($notifications, $recordedSanction);
        }

        $sanctionSuffix = $recordedSanction
            ? ' Administrative offense recorded: '.$recordedSanction->offense_no.' offense — '.$recordedSanction->sanction_label.'.'
            : '';

        if ($isInterimDecision) {
            return back()->with('status', ($data['resolution_outcome'] === 'BILLING_REQUIRED'
                ? 'Head decision recorded. RSLDDP was generated and the case is now for RSLDDP upload/settlement processing by the SPMU Head/Admin.'
                : ($requiresRslddp
                    ? 'Head decision recorded. RSLDDP was generated for this compliance case; upload the accomplished RSLDDP to resume Action Officer compliance verification.'
                    : 'Head decision recorded. Required property compliance remains open and the linked restriction stays active until the SPMU Action Officer verifies completion.')).$sanctionSuffix);
        }

        return back()->with('status', 'Property accountability case resolved. Its linked property restriction was lifted. Any separate sanction remains governed by the configured sanction rule.'.$sanctionSuffix);
    }

    /**
     * The SPMU Action Officer physically verifies repair/replacement or another
     * Head-required property compliance. The officer does not re-decide the
     * administrative offense or sanction; those remain Head-level records.
     */
    private function completeIncidentCompliance(
        Request $request,
        Incident $incident,
        AuditService $audit,
        NotificationService $notifications,
        InventoryService $inventoryService
    ): RedirectResponse {
        abort_unless(
            $request->user()?->access_classification === AccessClassification::SpmuOfficer,
            403,
            'Only the SPMU Action Officer may verify completed property compliance.'
        );

        $data = $request->validate([
            'resolution_outcome' => ['required', 'in:COMPLIANCE_COMPLETED'],
        ]);

        $inventoryAdjustments = [];

        DB::transaction(function () use ($incident, $request, $data, $audit, $inventoryService, &$inventoryAdjustments): void {
            $incident = Incident::query()->lockForUpdate()->findOrFail($incident->id);

            if ($incident->status !== 'COMPLIANCE_REQUIRED') {
                throw ValidationException::withMessages([
                    'incident' => 'This property case is not awaiting Action Officer compliance verification.',
                ]);
            }

            $previousStatus = $incident->status;
            $existingRemarks = trim((string) $incident->remarks);
            $complianceAction = $this->resolvedComplianceAction($incident);

            if (! $complianceAction) {
                throw ValidationException::withMessages([
                    'incident' => 'This compliance case does not have a valid physical compliance action.',
                ]);
            }

            $inventoryAdjustments = $inventoryService->recordIncidentCompliance(
                $incident,
                $request->user(),
                $complianceAction
            );

            $verificationNote = 'SPMU Action Officer verified '.$this->complianceActionLabel($complianceAction).'.';

            $incident->update([
                'status' => 'RESOLVED',
                'remarks' => trim($existingRemarks.($existingRemarks !== '' ? "\n" : '').$verificationNote),
            ]);

            BorrowerRestriction::query()
                ->where('incident_id', $incident->id)
                ->where('status', 'ACTIVE')
                ->update([
                    'status' => 'LIFTED',
                    'effective_to' => now(),
                    'lifted_by_user_id' => $request->user()->id,
                ]);

            $audit->record(
                'PROPERTY_ACCOUNTABILITY_COMPLIANCE_VERIFIED',
                $incident,
                reason: 'Physical compliance verified by the SPMU Action Officer.',
                before: ['status' => $previousStatus],
                after: [
                    'status' => 'RESOLVED',
                    'resolution_outcome' => 'COMPLIANCE_COMPLETED',
                    'compliance_action' => $complianceAction,
                    'inventory_adjustments' => $inventoryAdjustments,
                    'verified_by_user_id' => $request->user()->id,
                    'verification_role' => 'SPMU_ACTION_OFFICER',
                ]
            );

            $this->attemptCloseCustody((int) $incident->custody_transaction_id);
        }, 3);

        $incident->refresh()->loadMissing('borrower');
        if ($incident->borrower) {
            $notifications->send(
                'PROPERTY_ACCOUNTABILITY_CASE_RESOLVED',
                collect([$incident->borrower]),
                "The SPMU Action Officer verified the required {$this->complianceActionLabel($this->resolvedComplianceAction($incident))} for property accountability case {$incident->incident_no}. {$this->incidentBorrowerContext($incident)} The property case is now resolved and its linked property restriction has been lifted. Any separate administrative sanction or other active obligation/restriction remains subject to its own status.",
                $incident,
                ['SYSTEM', 'EMAIL']
            );
        }

        return back()->with(
            'status',
            'Property compliance verified. Inventory and the linked property restriction were updated automatically; any separate sanction remains unchanged.'
        );
    }


    /** @return list<string> */
    private function allowedComplianceActions(Incident $incident): array
    {
        $states = $incident->lines()
            ->pluck('disposition_state')
            ->map(fn ($state) => strtoupper((string) $state))
            ->filter()
            ->unique()
            ->values();

        if ($states->isEmpty()) {
            return match (strtoupper((string) $incident->incident_type)) {
                'DAMAGED', 'DAMAGE' => ['REPAIR', 'REPLACEMENT'],
                'MISSING', 'LOST', 'LOSS', 'STOLEN' => ['RECOVERY', 'REPLACEMENT'],
                'DESTROYED' => ['REPLACEMENT'],
                default => [],
            };
        }

        $supportedStates = [
            'REPAIR' => ['DAMAGED_MAINTENANCE'],
            'REPLACEMENT' => ['DAMAGED_MAINTENANCE', 'LOST', 'STOLEN', 'DESTROYED', 'REPLACEMENT_REQUIRED'],
            'RECOVERY' => ['LOST', 'STOLEN'],
        ];

        return collect($supportedStates)
            ->filter(fn (array $allowedStates) => $states->every(
                fn (string $state) => in_array($state, $allowedStates, true)
            ))
            ->keys()
            ->values()
            ->all();
    }

    private function resolvedComplianceAction(Incident $incident): ?string
    {
        $recorded = strtoupper(trim((string) $incident->compliance_action));
        if (in_array($recorded, ['REPAIR', 'REPLACEMENT', 'RECOVERY'], true)) {
            return $recorded;
        }

        return match (strtoupper((string) $incident->incident_type)) {
            'DAMAGED', 'DAMAGE' => 'REPAIR',
            'MISSING', 'LOST', 'LOSS', 'STOLEN', 'DESTROYED' => 'REPLACEMENT',
            default => null,
        };
    }

    private function complianceActionLabel(?string $action): string
    {
        return match (strtoupper((string) $action)) {
            'REPAIR' => 'repair and return to service',
            'REPLACEMENT' => 'one-for-one replacement',
            'RECOVERY' => 'item recovery / return',
            default => 'property compliance',
        };
    }

    /**
     * SPMU Head/Admin records the accomplished/notarized RSLDDP scan after
     * external signing. The system never performs notarization itself -
     * this only records evidence that it happened. Re-callable at any point
     * before the case is resolved, so a mistaken upload can simply be
     * replaced; the first successful call also advances the case out of its
     * RSLDDP-pending stage, later calls only replace the file.
     */
    public function uploadAccomplishedRslddp(
        Request $request,
        Incident $incident,
        ProtectedFileService $files,
        AuditService $audit
    ): RedirectResponse {
        $this->authorizeSpmu($request);
        abort_unless(
            $request->user()?->access_classification === AccessClassification::SpmuHead,
            403,
            'Only the SPMU Head/Admin may record the accomplished RSLDDP.'
        );

        abort_unless(
            in_array($incident->status, [
                'COMPLIANCE_RSLDDP_PENDING',
                'RSLDDP_AWAITING_UPLOAD',
                'RSLDDP_FOR_ACCOUNTING_PROCESSING',
                'RSLDDP_PAYMENT_REQUIRED',
                'RSLDDP_FOR_RESOLUTION',
            ], true),
            422,
            'This property case is not awaiting an accomplished RSLDDP.'
        );

        $data = $request->validate([
            'evidence' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:5120'],
        ], [], ['evidence' => 'Accomplished RSLDDP scan']);

        $document = $incident->documents()
            ->where('document_type', 'RSLDDP')
            ->where('status', 'FINAL')
            ->latest('id')
            ->first();

        abort_unless($document, 422, 'No RSLDDP document has been generated for this case yet.');

        $file = $files->storeUpload($data['evidence'], 'accountability-rslddp', 'INCIDENT_EVIDENCE');

        DB::transaction(function () use ($incident, $request, $document, $file, $audit): void {
            $incident = Incident::query()->lockForUpdate()->findOrFail($incident->id);
            $isFirstUpload = $incident->rslddp_evidence_submission_id === null;

            $submission = EvidenceSubmission::query()->create([
                'generated_document_id' => $document->id,
                'stored_file_id' => $file->id,
                'borrower_user_id' => $incident->borrower_user_id,
                'uploaded_by_user_id' => $request->user()->id,
                'verified_by_user_id' => $request->user()->id,
                'upload_mode' => 'SPMU_INTAKE',
                'submitted_at' => now(),
                'verification_status' => 'VERIFIED',
                'verified_at' => now(),
            ]);

            $nextStatus = match (true) {
                ! $isFirstUpload => $incident->status,
                $incident->status === 'COMPLIANCE_RSLDDP_PENDING' => 'COMPLIANCE_REQUIRED',
                $incident->status === 'RSLDDP_AWAITING_UPLOAD' => 'RSLDDP_FOR_ACCOUNTING_PROCESSING',
                default => $incident->status,
            };

            $incident->update([
                'rslddp_evidence_submission_id' => $submission->id,
                'status' => $nextStatus,
            ]);

            $audit->record(
                'PROPERTY_ACCOUNTABILITY_RSLDDP_RECEIVED',
                $incident,
                reason: $isFirstUpload ? 'Accomplished RSLDDP recorded.' : 'Accomplished RSLDDP replaced with a corrected scan.',
                after: [
                    'status' => $nextStatus,
                    'evidence_submission_id' => $submission->id,
                ]
            );
        }, 3);

        return back()->with('status', 'Accomplished RSLDDP recorded.');
    }

    /**
     * SPMU Head/Admin records the official Billing Statement the Accounting
     * Office issued after processing the accomplished RSLDDP. SPMU-ACPMP
     * never generates this document itself - only the received PDF and its
     * amount/reference are recorded. Replacing a not-yet-paid billing
     * supersedes it in place, preserving full audit history; once a Payment
     * exists against it, replacement is permanently blocked here -
     * correction then requires Accounting/Cashier reconciliation outside
     * the system.
     */
    public function recordOfficialBillingStatement(
        Request $request,
        Incident $incident,
        ProtectedFileService $files,
        AuditService $audit,
        NotificationService $notifications
    ): RedirectResponse {
        $this->authorizeSpmu($request);
        abort_unless(
            $request->user()?->access_classification === AccessClassification::SpmuHead,
            403,
            'Only the SPMU Head/Admin may record the official Accounting-issued Billing Statement.'
        );

        /*
         * RSLDDP_FOR_RESOLUTION is included so a correction *attempt* after
         * full settlement reaches the friendly, specific
         * "reconciliation required" message below instead of this generic
         * guard's message - the outcome (blocked, nothing changes) is the
         * same either way, but the borrower/Head sees why.
         */
        abort_unless(
            in_array($incident->status, ['RSLDDP_FOR_ACCOUNTING_PROCESSING', 'RSLDDP_PAYMENT_REQUIRED', 'RSLDDP_FOR_RESOLUTION'], true),
            422,
            'This property case is not awaiting an official Billing Statement.'
        );

        $data = $request->validate([
            'evidence' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:5120'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'billing_reference' => ['required', 'string', 'max:255'],
            'due_at' => ['nullable', 'date', 'after_or_equal:today'],
        ], [], [
            'evidence' => 'Official Billing Statement',
            'billing_reference' => 'Accounting Billing/SOA Reference No.',
        ]);

        $currentBilling = BillingStatement::query()
            ->whereHas('lines', fn ($query) => $query->where('incident_id', $incident->id))
            ->where('source', 'ACCOUNTING_OFFICE')
            ->where('status', '!=', 'VOID')
            ->latest('id')
            ->first();

        /*
         * RSLDDP_FOR_RESOLUTION is only ever reached after a full settled
         * payment (settleBillingIfFullyPaid()), so this case is always
         * blocked here regardless of the currentBilling lookup above -
         * defense in depth against ever silently creating a second billing
         * once settlement is already complete.
         */
        if ($incident->status === 'RSLDDP_FOR_RESOLUTION' || ($currentBilling && $currentBilling->payments()->exists())) {
            return back()->withErrors([
                'incident' => 'This Official Billing Statement already has a recorded payment. Correction requires Accounting/Cashier reconciliation outside the system before it can be replaced.',
            ]);
        }

        $file = $files->storeUpload($data['evidence'], 'accountability-official-billing', 'INCIDENT_EVIDENCE');

        $billing = DB::transaction(function () use ($incident, $request, $data, $file, $currentBilling, $audit): BillingStatement {
            $incident = Incident::query()->lockForUpdate()->findOrFail($incident->id);

            if ($currentBilling) {
                $currentBilling->update([
                    'status' => 'VOID',
                    'remarks' => trim(($currentBilling->remarks ? $currentBilling->remarks."\n" : '').'Superseded by correction.'),
                ]);

                GeneratedDocument::query()
                    ->where('subject_type', BillingStatement::class)
                    ->where('subject_id', $currentBilling->id)
                    ->where('status', 'FINAL')
                    ->update([
                        'status' => 'SUPERSEDED',
                        'invalidated_at' => now(),
                        'invalidation_reason' => 'Replaced by a corrected Official Property Billing Statement.',
                    ]);
            }

            $billing = BillingStatement::query()->create([
                'billing_no' => 'ACCTG-'.now()->format('YmdHis').'-'.$incident->id.'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
                'borrower_user_id' => $incident->borrower_user_id,
                'responsible_spmu_user_id' => $request->user()->id,
                'issued_at' => now(),
                'due_at' => $data['due_at'] ?? null,
                'total_amount' => $data['amount'],
                'status' => 'ISSUED',
                'source' => 'ACCOUNTING_OFFICE',
                'remarks' => 'Official Billing Statement issued by the Accounting Office (ref. '.$data['billing_reference'].') for '.$incident->incident_no.'.',
            ]);

            $billing->lines()->create([
                'incident_id' => $incident->id,
                /*
                 * Globally unique per billing row (not per incident), so a
                 * later correction can create a second line for the same
                 * incident without colliding with the voided one's key.
                 */
                'source_key' => 'INCIDENT:'.$incident->id.':'.$billing->id,
                'line_type' => 'PROPERTY_ACCOUNTABILITY_CHARGE',
                'description' => str($incident->incident_type)->replace('_', ' ')->title().' accountability charge (Accounting Office billing '.$data['billing_reference'].')',
                'basis' => 'Official Billing Statement issued by the Accounting Office.',
                'amount' => $data['amount'],
            ]);

            GeneratedDocument::query()->create([
                'template_id' => null,
                'stored_file_id' => $file->id,
                'request_version_id' => $incident->custody?->request?->currentVersion?->id,
                'subject_type' => BillingStatement::class,
                'subject_id' => $billing->id,
                'document_no' => 'BILLING-'.now()->format('YmdHis').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
                'document_type' => 'BILLING_STATEMENT',
                'version_no' => 1,
                'sha256' => $file->sha256,
                'status' => 'FINAL',
                'generated_at' => now(),
            ]);

            $incident->update(['status' => 'RSLDDP_PAYMENT_REQUIRED']);

            $audit->record(
                $currentBilling ? 'PROPERTY_ACCOUNTABILITY_OFFICIAL_BILLING_CORRECTED' : 'PROPERTY_ACCOUNTABILITY_OFFICIAL_BILLING_RECORDED',
                $billing,
                reason: 'Accounting reference '.$data['billing_reference'],
                after: [
                    'amount' => $data['amount'],
                    'incident_no' => $incident->incident_no,
                    'superseded_billing_id' => $currentBilling?->id,
                ]
            );

            return $billing;
        }, 3);

        $incident->refresh()->loadMissing('borrower');
        if ($incident->borrower) {
            $notifications->send(
                'PROPERTY_ACCOUNTABILITY_PAYMENT_REQUIRED',
                collect([$incident->borrower]),
                "The official Billing Statement for property accountability case {$incident->incident_no} has been recorded. {$this->incidentBorrowerContext($incident)} Review it in My Obligations, pay through the CSPC Cashier, then present the official receipt to the SPMU Action Officer for recording.",
                $billing,
                ['SYSTEM', 'EMAIL']
            );
        }

        return back()->with('status', $currentBilling
            ? 'Official Billing Statement corrected. The previous record was superseded and preserved for audit history.'
            : 'Official Billing Statement recorded. The borrower was notified to pay through the CSPC Cashier.');
    }

    /**
     * SPMU Head/Admin's final verification and resolution once the
     * accomplished RSLDDP is on record and the official Billing Statement
     * is fully settled through a confirmed Cashier payment. No reject path
     * here - any correction happens earlier, before this final step.
     */
    public function resolveRslddpSettlement(
        Request $request,
        Incident $incident,
        AuditService $audit,
        NotificationService $notifications
    ): RedirectResponse {
        $this->authorizeSpmu($request);
        abort_unless(
            $request->user()?->access_classification === AccessClassification::SpmuHead,
            403,
            'Only the SPMU Head/Admin may verify and resolve the RSLDDP settlement.'
        );

        $data = $request->validate([
            'resolution_remarks' => ['required', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($incident, $request, $data, $audit): void {
            $incident = Incident::query()->lockForUpdate()->findOrFail($incident->id);

            if ($incident->status !== 'RSLDDP_FOR_RESOLUTION') {
                throw ValidationException::withMessages([
                    'incident' => 'This property case is not awaiting RSLDDP settlement resolution.',
                ]);
            }

            $previousStatus = $incident->status;
            $existingRemarks = trim((string) $incident->remarks);

            if ($incident->rslddp_evidence_submission_id) {
                EvidenceSubmission::query()
                    ->whereKey($incident->rslddp_evidence_submission_id)
                    ->update([
                        'verification_status' => 'VERIFIED',
                        'verified_by_user_id' => $request->user()->id,
                        'verified_at' => now(),
                    ]);
            }

            $incident->update([
                'status' => 'RESOLVED',
                'remarks' => trim($existingRemarks.($existingRemarks !== '' ? "\n" : '').'SPMU Head/Admin RSLDDP settlement resolution: '.$data['resolution_remarks']),
            ]);

            BorrowerRestriction::query()
                ->where('incident_id', $incident->id)
                ->where('status', 'ACTIVE')
                ->update([
                    'status' => 'LIFTED',
                    'effective_to' => now(),
                    'lifted_by_user_id' => $request->user()->id,
                ]);

            $this->attemptCloseCustody((int) $incident->custody_transaction_id);

            $audit->record(
                'PROPERTY_ACCOUNTABILITY_CASE_RESOLVED',
                $incident,
                reason: $data['resolution_remarks'],
                before: ['status' => $previousStatus],
                after: [
                    'status' => 'RESOLVED',
                    'resolution_outcome' => 'RSLDDP_SETTLEMENT_VERIFIED',
                    'resolved_by_user_id' => $request->user()->id,
                ]
            );
        }, 3);

        $incident->refresh()->loadMissing('borrower');
        if ($incident->borrower) {
            $notifications->send(
                'PROPERTY_ACCOUNTABILITY_CASE_RESOLVED',
                collect([$incident->borrower]),
                "Property accountability case {$incident->incident_no} has been resolved by the SPMU Head/Admin after RSLDDP settlement verification. {$this->incidentBorrowerContext($incident)} The restriction linked to this property case has been lifted. Any separate administrative sanction or other active obligation/restriction still applies according to its own status.",
                $incident,
                ['SYSTEM', 'EMAIL']
            );
        }

        return back()->with('status', 'RSLDDP settlement verified. The property accountability case was resolved and its linked restriction was lifted.');
    }

    /**
     * Lazy find-or-generate: a restriction is printable from the moment
     * it's active, not only after a Head decision, so this keeps
     * BorrowerObligationService::buildRows() a pure read path with no side
     * effects. Property-accountability-caused restrictions only - a
     * late-return or suspension-caused restriction has its own notice.
     */
    public function restrictionNotice(
        Request $request,
        BorrowerRestriction $restriction,
        DocumentService $documents
    ): RedirectResponse {
        $user = $request->user();
        abort_unless(
            (int) $restriction->borrower_user_id === (int) $user?->id
                || $user?->hasRole(UserRole::Spmu)
                || $user?->hasRole(UserRole::Ictu),
            403
        );

        abort_unless(
            $restriction->incident_id !== null,
            404,
            'A Restriction Notice is available only for property-accountability-caused restrictions.'
        );

        $document = GeneratedDocument::query()
            ->where('subject_type', BorrowerRestriction::class)
            ->where('subject_id', $restriction->id)
            ->where('document_type', 'RESTRICTION_NOTICE')
            ->where('status', 'FINAL')
            ->latest('id')
            ->first();

        $document ??= $documents->restrictionNotice($restriction);

        return redirect()->route('documents.preview', $document);
    }

    public function reviewViolation(
        Request $request,
        BorrowerViolation $violation,
        PolicyService $policy,
        DocumentService $documents,
        SignatureService $signatures,
        NotificationService $notifications
    ): RedirectResponse {
        abort_unless(
            $request->user()->access_classification === AccessClassification::SpmuHead,
            403,
            'Only the SPMU Head may review violations and record sanctions.'
        );

        $data = $request->validate([
            'decision' => ['required', 'in:CONFIRMED,DISMISSED'],
            'sanction_code' => ['nullable', 'in:NOTICE,WRITTEN_REPRIMAND,BORROWING_SUSPENSION,OTHER'],
            'custom_sanction_label' => ['nullable', 'string', 'max:255'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $sanction = $policy->reviewViolation(
            $violation,
            $request->user(),
            $data['decision'],
            $data['remarks'] ?? null,
            $data['sanction_code'] ?? null,
            $data['custom_sanction_label'] ?? null,
            $data['effective_to'] ?? null
        );

        if ($data['decision'] === 'DISMISSED') {
            return back()->with('status', 'Violation dismissed. No sanction was recorded.');
        }

        if ($sanction) {
            /*
             * Only a formal borrowing suspension gets a printed notice
             * (as "Suspension Notice"). A Written Reprimand (or NOTICE/
             * OTHER) still confirms/records the sanction itself here -
             * only its paper notice is skipped, matching the identical
             * gate in resolveIncident() above.
             */
            $noticeIssued = strtoupper((string) $sanction->sanction_code) === 'BORROWING_SUSPENSION';

            if ($noticeIssued) {
                $documents->administrativeSanctionNotice($sanction);
            }

            $this->notifyAdministrativeSanction($notifications, $sanction, $noticeIssued);
        }

        return back()->with(
            'status',
            "Violation confirmed. {$sanction->sanction_label} was recorded by the SPMU Head."
        );
    }

    /**
     * Send one official borrower notice for a confirmed administrative sanction.
     * SYSTEM and EMAIL are required channels because this is an official
     * accountability decision, while the generated PDF remains the full notice.
     */
    private function notifyAdministrativeSanction(
        NotificationService $notifications,
        Sanction $sanction,
        bool $noticeIssued = true
    ): void {
        $alreadyNotified = DB::table('notification_events')
            ->where('event_code', 'ADMINISTRATIVE_SANCTION_RECORDED')
            ->where('source_type', $sanction->getMorphClass())
            ->where('source_id', $sanction->getKey())
            ->exists();

        if ($alreadyNotified) {
            return;
        }

        $sanction->loadMissing(['borrower', 'violation']);

        if (! $sanction->borrower) {
            return;
        }

        $reasons = collect(
            is_array($sanction->violation?->details_json['reasons'] ?? null)
                ? $sanction->violation->details_json['reasons']
                : []
        )
            ->map(fn ($reason) => str((string) $reason)->replace('_', ' ')->title()->toString())
            ->filter()
            ->unique()
            ->values();

        $reasonText = $reasons->isNotEmpty()
            ? $reasons->implode(', ')
            : 'Confirmed borrowing accountability offense';

        $offenseLabel = match ((int) $sanction->offense_no) {
            1 => '1st Offense',
            2 => '2nd Offense',
            3 => '3rd Offense',
            default => $sanction->offense_no.'th Offense',
        };

        $effectiveUntil = strtoupper((string) $sanction->sanction_code) === 'BORROWING_SUSPENSION'
            && $sanction->effective_to
                ? ' Effective until: '.$sanction->effective_to->copy()->timezone('Asia/Manila')->format('d F Y').'.'
                : '';

        $message = 'Administrative sanction recorded. '
            .'Reason: '.$reasonText.'. '
            .'Offense: '.$offenseLabel.'. '
            .'Sanction: '.$sanction->sanction_label.'.'
            .$effectiveUntil
            .($noticeIssued
                ? ' View the complete notice under My Obligations.'
                : ' This sanction has been recorded on your account; no printed notice is issued for this sanction type.');

        $notifications->send(
            'ADMINISTRATIVE_SANCTION_RECORDED',
            collect([$sanction->borrower]),
            $message,
            $sanction,
            ['SYSTEM', 'EMAIL'],
            ['SYSTEM', 'EMAIL']
        );
    }

    /**
     * Borrower-facing context for a property accountability case. Keeps the
     * in-system notification useful without forcing the borrower to open the
     * Billing Statement just to learn which property/finding is involved.
     */
    private function incidentBorrowerContext(Incident $incident): string
    {
        $incident->loadMissing('lines.custodyLine.requestItem.inventoryItem');

        $items = $incident->lines
            ->map(function ($line) use ($incident): string {
                $requestItem = $line->custodyLine?->requestItem;
                $inventoryItem = $requestItem?->inventoryItem;
                $name = trim((string) ($requestItem?->description_snapshot ?: $inventoryItem?->unique_description ?: 'Property item'));
                $finding = str((string) ($line->observed_condition ?: $incident->incident_type ?: 'accountability finding'))
                    ->replace('_', ' ')
                    ->title()
                    ->toString();
                $quantity = max(0, (int) $line->quantity);

                return $name.' — '.$finding.' ('.$quantity.')';
            })
            ->filter()
            ->values();

        if ($items->isNotEmpty()) {
            return 'Affected property: '.$items->implode('; ').'.';
        }

        return 'Finding: '.str((string) $incident->incident_type)
            ->replace('_', ' ')
            ->title()
            ->toString().'.';
    }

    /** Borrower-facing summary for a finalized late return. */
    private function lateReturnBorrowerContext(OverdueCase $overdue): string
    {
        $expected = $overdue->grace_expires_at
            ? $overdue->grace_expires_at->copy()->timezone('Asia/Manila')->format('d M Y')
            : 'Not recorded';
        $actual = $overdue->actual_return_date
            ? $overdue->actual_return_date->copy()->timezone('Asia/Manila')->format('d M Y')
            : 'Not recorded';
        $days = max(0, (int) $overdue->late_days);

        return 'Expected return: '.$expected.'; actual return: '.$actual.'; final late days: '.$days.'.';
    }

    /**
     * Borrower-facing context for payment notifications, preserving the
     * distinction between property accountability and late-return billing.
     */
    private function billingBorrowerContext(BillingStatement $billing): string
    {
        $billing->loadMissing([
            'lines.incident.lines.custodyLine.requestItem.inventoryItem',
            'lines.penalty.overdueCase',
        ]);

        $incident = $billing->lines->first(fn ($line) => filled($line->incident_id))?->incident;
        if ($incident) {
            return $this->incidentBorrowerContext($incident);
        }

        $lateCase = $billing->lines
            ->first(fn ($line) => $line->penalty?->overdueCase)?->penalty?->overdueCase;

        if ($lateCase) {
            return $this->lateReturnBorrowerContext($lateCase);
        }

        return 'Accountability billing reference: '.$billing->billing_no.'.';
    }

    /**
     * Settle a Billing Statement once confirmed payments cover the full amount.
     * Financial settlement and administrative sanctions remain separate.
     */
    private function settleBillingIfFullyPaid(
        BillingStatement $billing,
        int $actorUserId,
        AuditService $audit
    ): bool {
        $confirmedAmount = (float) $billing->payments()
            ->where('status', 'VERIFIED')
            ->sum('amount');

        if ($confirmedAmount + 0.0001 < (float) $billing->total_amount) {
            return false;
        }

        $billing->update(['status' => 'SETTLED']);

        $incidentIds = $billing->lines()->whereNotNull('incident_id')->pluck('incident_id');

        /*
         * RSLDDP monetary leg: settlement advances the case to "For
         * Resolution" only. It never auto-resolves here - Head/Admin still
         * verifies the accomplished RSLDDP and explicitly resolves the case
         * (resolveRslddpSettlement()), unlike the legacy path below.
         */
        if ($billing->source === 'ACCOUNTING_OFFICE') {
            Incident::query()
                ->whereKey($incidentIds)
                ->where('status', 'RSLDDP_PAYMENT_REQUIRED')
                ->update(['status' => 'RSLDDP_FOR_RESOLUTION']);

            return true;
        }

        $propertyIncidents = Incident::query()
            ->whereKey($incidentIds)
            ->where('status', 'BILLING_PENDING')
            ->get();

        foreach ($propertyIncidents as $propertyIncident) {
            $propertyIncident->update(['status' => 'RESOLVED']);

            $audit->record(
                'PROPERTY_ACCOUNTABILITY_CASE_RESOLVED',
                $propertyIncident,
                reason: 'Billing fully settled through a confirmed CSPC Cashier payment.',
                before: ['status' => 'BILLING_PENDING'],
                after: [
                    'status' => 'RESOLVED',
                    'resolution_outcome' => 'BILLING_SETTLED',
                    'billing_statement_id' => $billing->id,
                    'settled_by_user_id' => $actorUserId,
                ]
            );
        }

        BorrowerRestriction::query()
            ->where('billing_statement_id', $billing->id)
            ->where('status', 'ACTIVE')
            ->update([
                'status' => 'LIFTED',
                'effective_to' => now(),
                'lifted_by_user_id' => $actorUserId,
            ]);

        $penaltyIds = $billing->lines()->whereNotNull('penalty_id')->pluck('penalty_id');
        Penalty::query()->whereKey($penaltyIds)->update(['status' => 'SETTLED']);
        OverdueCase::query()
            ->whereHas('penalties', fn ($query) => $query->whereIn('penalties.id', $penaltyIds))
            ->update(['status' => 'RESOLVED']);

        $custodyIds = Incident::query()->whereKey($incidentIds)->pluck('custody_transaction_id')
            ->merge(Penalty::query()->whereKey($penaltyIds)->pluck('custody_transaction_id'))
            ->unique();

        foreach ($custodyIds as $custodyId) {
            $this->attemptCloseCustody((int) $custodyId);
        }

        return true;
    }

    /**
     * Billing settlement closes the property case automatically after the AO
     * records a verified Cashier receipt. Notify the borrower about that case
     * closure separately from the payment-confirmation notice so the record
     * sequence remains explicit and auditable.
     */
    private function notifyResolvedPropertyBilling(
        BillingStatement $billing,
        NotificationService $notifications,
        string $resolutionSource
    ): void {
        $incidentIds = $billing->lines()
            ->whereNotNull('incident_id')
            ->pluck('incident_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($incidentIds->isEmpty()) {
            return;
        }

        $incidents = Incident::query()
            ->with(['borrower', 'custody.request', 'lines.custodyLine.requestItem.inventoryItem.unit'])
            ->whereKey($incidentIds)
            ->where('status', 'RESOLVED')
            ->get();

        foreach ($incidents as $incident) {
            $alreadyNotified = DB::table('notification_events')
                ->where('event_code', 'PROPERTY_ACCOUNTABILITY_CASE_RESOLVED')
                ->where('source_type', $incident->getMorphClass())
                ->where('source_id', $incident->getKey())
                ->exists();

            if ($alreadyNotified || ! $incident->borrower) {
                continue;
            }

            $resolutionText = $billing->status === 'WAIVED'
                ? "Billing Statement {$billing->billing_no} was formally waived by the SPMU Head/Admin"
                : "Billing Statement {$billing->billing_no} was fully settled through a Cashier payment recorded and confirmed by the SPMU Action Officer";

            $notifications->send(
                'PROPERTY_ACCOUNTABILITY_CASE_RESOLVED',
                collect([$incident->borrower]),
                "Property accountability case {$incident->incident_no} has been resolved after {$resolutionText}. {$this->incidentBorrowerContext($incident)} The linked property/billing restriction has been lifted. Any separate administrative sanction or other active obligation/restriction remains subject to its own status. Resolution source: {$resolutionSource}.",
                $incident,
                ['SYSTEM', 'EMAIL']
            );
        }
    }

    private function attemptCloseCustody(int $custodyId): void
    {
        $custody = CustodyTransaction::query()->find($custodyId);

        if ($custody) {
            app(CustodyService::class)->reconcileTransactionStatus($custody);
        }
    }

    private function isDuplicateBillingAttempt(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'billing_lines_source_key_unique')
            || str_contains($message, 'billing_lines.source_key')
            || str_contains($message, 'billing_statements_billing_no_unique')
            || str_contains($message, 'billing_statements.billing_no');
    }

    private function authorizeSpmu(Request $request): void
    {
        abort_unless($request->user()->hasRole(UserRole::Spmu), 403);
    }

    private function spmuUsers(): Collection
    {
        return User::query()
            ->where('account_status', 'ACTIVE')
            ->whereHas('roles', fn ($query) => $query
                ->where('role_code', UserRole::Spmu->value)
                ->whereNull('user_roles.revoked_at'))
            ->get();
    }
}
