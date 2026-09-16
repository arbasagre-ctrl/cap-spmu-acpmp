<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Enums\UserRole;
use App\Models\BillingStatement;
use App\Models\BorrowerViolation;
use App\Models\BorrowerRestriction;
use App\Models\CustodyTransaction;
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
        $restrictionQuery = BorrowerRestriction::with(['custody.request'])->latest('effective_from');
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
     */
    /**
     * The Action Officer confirms a detected late return.
     *
     * The officer is confirming that the recorded physical return date is
     * correct and that the system's late classification follows from it. They
     * are not choosing the number of late days.
     */
    public function confirmLateReturn(
        Request $request,
        OverdueCase $overdue,
        LateReturnService $lateReturns
    ): RedirectResponse {
        $this->authorizeSpmu($request);

        abort_unless(
            $request->user()?->access_classification === AccessClassification::SpmuOfficer,
            403
        );

        $lateReturns->confirm($overdue, $request->user());

        return back()->with(
            'status',
            'Late-return assessment confirmed and forwarded to the SPMU Head for approval.'
        );
    }

    /**
     * The SPMU Head sends an assessment back to the Action Officer.
     *
     * The case is preserved with its history; only the stage moves back.
     */
    public function returnLateReturnForCorrection(
        Request $request,
        OverdueCase $overdue,
        LateReturnService $lateReturns
    ): RedirectResponse {
        $this->authorizeSpmu($request);

        abort_unless(
            $request->user()?->access_classification === AccessClassification::SpmuHead,
            403
        );

        $data = $request->validate([
            'remarks' => ['required', 'string', 'max:1000'],
        ]);

        $lateReturns->returnForCorrection($overdue, $request->user(), $data['remarks']);

        return back()->with('status', 'The late-return assessment was returned to the Action Officer for correction.');
    }

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
         * A Late Return Fee Form is only ever produced from an assessment the
         * Action Officer has already confirmed.
         */
        if ($overdue->status === LateReturnService::STATUS_FOR_AO_CONFIRMATION) {
            return back()->withErrors([
                'overdue' => 'The Action Officer has not confirmed this late-return assessment yet.',
            ]);
        }

        if ($overdue->status !== LateReturnService::STATUS_FOR_HEAD_APPROVAL) {
            return back()->withErrors([
                'overdue' => 'This late-return case is not ready for a new Billing Statement.',
            ]);
        }

        if ($overdue->actual_return_date === null || $overdue->ao_confirmed_at === null) {
            return back()->withErrors([
                'overdue' => 'A confirmed physical return date is required before the Late Return Fee Form can be generated.',
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
                    ['SYSTEM', 'EMAIL'],
                    ['SYSTEM', 'EMAIL']
                );

                $notifications->send(
                    'LATE_RETURN_BILLING_STATEMENT_ISSUED',
                    collect([$billing->borrower]),
                    "Billing Statement {$billing->billing_no} has been issued for the late return under {$overdue->custody?->custody_no}. {$this->lateReturnBorrowerContext($overdue)} Review/download it in My Obligations, pay through the CSPC Cashier, then present the official receipt to the SPMU Action Officer for recording and confirmation.",
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
        SignatureService $signatures
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
                $notifications
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
            'resolution_remarks' => ['required', 'string', 'max:2000'],
            'count_as_offense' => [$requiresOffenseDecision ? 'required' : 'nullable', 'boolean'],
        ], [
            'count_as_offense.required' => 'Choose whether this eligible incident should count as an administrative offense.',
        ]);

        $countAsOffense = $request->boolean('count_as_offense');
        $recordedSanction = null;
        $headSignature = null;
        $issuedComplianceDocument = null;
        $issuedSanctionDocument = null;

        if (DB::table('billing_lines')->where('incident_id', $incident->id)->exists()) {
            return back()->withErrors([
                'incident' => 'This property case already has a Billing Statement. Continue through billing settlement or an authorized billing waiver instead of recording another Head decision.',
            ]);
        }

        $outcomeLabel = match ($data['resolution_outcome']) {
            'NO_BORROWER_CHARGE' => 'No borrower liability / no charge',
            'COMPLIANCE_REQUIRED' => 'Repair / replacement / compliance required',
            'BILLING_REQUIRED' => 'Billing / payment required',
            'ADMINISTRATIVELY_CLEARED' => 'Administratively cleared',
        };

        $isInterimDecision = in_array($data['resolution_outcome'], ['COMPLIANCE_REQUIRED', 'BILLING_REQUIRED'], true);

        DB::transaction(function () use ($incident, $request, $data, $outcomeLabel, $isInterimDecision, $audit, $notifications, $policy, $documents, $signatures, $countAsOffense, &$recordedSanction, &$headSignature, &$issuedComplianceDocument, &$issuedSanctionDocument): void {
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
                $issuedSanctionDocument = $documents->administrativeSanctionNotice(
                    $recordedSanction->fresh(),
                    $headSignature
                );
            }

            if ($isInterimDecision) {
                $nextStatus = $data['resolution_outcome'] === 'BILLING_REQUIRED'
                    ? 'FOR_BILLING'
                    : 'COMPLIANCE_REQUIRED';

                $incident->update([
                    'status' => $nextStatus,
                    'remarks' => trim($existingRemarks.($existingRemarks !== '' ? "\n" : '').$decisionNote),
                ]);

                if ($nextStatus === 'COMPLIANCE_REQUIRED') {
                    $issuedComplianceDocument = $documents->accountabilityComplianceNotice(
                        $incident->fresh(),
                        $request->user(),
                        $data['resolution_remarks'],
                        $headSignature
                    );
                }

                $audit->record(
                    'PROPERTY_ACCOUNTABILITY_HEAD_DECISION_RECORDED',
                    $incident,
                    reason: $data['resolution_remarks'],
                    before: ['status' => $previousStatus],
                    after: [
                        'status' => $nextStatus,
                        'resolution_outcome' => $data['resolution_outcome'],
                        'administrative_offense_confirmed' => (bool) $recordedSanction,
                        'sanction_id' => $recordedSanction?->id,
                        'head_signature_snapshot_id' => $headSignature?->id,
                        'compliance_notice_document_id' => $issuedComplianceDocument?->id,
                        'sanction_notice_document_id' => $issuedSanctionDocument?->id,
                    ]
                );

                $incident->loadMissing('borrower');
                if ($incident->borrower) {
                    $incidentContext = $this->incidentBorrowerContext($incident);
                    $borrowerMessage = $nextStatus === 'FOR_BILLING'
                        ? "Property accountability case {$incident->incident_no} was reviewed by the SPMU Head/Admin and requires billing/payment processing. {$incidentContext} The Billing Statement will appear in My Obligations after the approved assessment is generated and issued. After paying at the CSPC Cashier, present the official receipt to the SPMU Action Officer for recording. Your linked borrowing restriction remains active until settlement or formal waiver."
                        : "Property accountability case {$incident->incident_no} requires repair, replacement, or other compliance. {$incidentContext} Review the Accountability / Compliance Notice in My Obligations, complete the required action, then present the repaired/replaced property or required compliance to the SPMU Action Officer for physical verification. The linked borrowing restriction remains active until that verification is completed.";

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
                ? 'Head decision recorded. The case is now for Billing Statement preparation/issuance by the SPMU Head/Admin; Cashier receipt recording will be handled by the Action Officer after payment.'
                : 'Head decision recorded. Required property compliance remains open and the linked restriction stays active until the SPMU Action Officer verifies completion.').$sanctionSuffix);
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
        NotificationService $notifications
    ): RedirectResponse {
        abort_unless(
            $request->user()?->access_classification === AccessClassification::SpmuOfficer,
            403,
            'Only the SPMU Action Officer may verify completed property compliance.'
        );

        $data = $request->validate([
            'resolution_outcome' => ['required', 'in:COMPLIANCE_COMPLETED'],
            'resolution_remarks' => ['required', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($incident, $request, $data, $audit): void {
            $incident = Incident::query()->lockForUpdate()->findOrFail($incident->id);

            if ($incident->status !== 'COMPLIANCE_REQUIRED') {
                throw ValidationException::withMessages([
                    'incident' => 'This property case is not awaiting Action Officer compliance verification.',
                ]);
            }

            $previousStatus = $incident->status;
            $existingRemarks = trim((string) $incident->remarks);
            $verificationNote = 'SPMU Action Officer compliance verification: '.$data['resolution_remarks'];

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
                reason: $data['resolution_remarks'],
                before: ['status' => $previousStatus],
                after: [
                    'status' => 'RESOLVED',
                    'resolution_outcome' => 'COMPLIANCE_COMPLETED',
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
                "The SPMU Action Officer physically verified the required repair, replacement, or compliance for property accountability case {$incident->incident_no}. {$this->incidentBorrowerContext($incident)} The property case is now resolved and its linked property restriction has been lifted. Any separate administrative sanction or other active obligation/restriction remains subject to its own status.",
                $incident,
                ['SYSTEM', 'EMAIL']
            );
        }

        return back()->with(
            'status',
            'Property compliance verified by the SPMU Action Officer. The property case was resolved and its linked property restriction was lifted; any separate sanction remains unchanged.'
        );
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
            $documents->administrativeSanctionNotice($sanction);
            $this->notifyAdministrativeSanction($notifications, $sanction);
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
        Sanction $sanction
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
            .' View the complete notice under My Obligations.';

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
