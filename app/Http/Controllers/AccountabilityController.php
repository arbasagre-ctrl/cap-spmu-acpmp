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
use App\Services\CustodyService;
use App\Services\DocumentService;
use App\Services\NotificationService;
use App\Services\ProtectedFileService;
use App\Services\PolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccountabilityController extends Controller
{
    public function index(Request $request, PolicyService $policy): View
    {
        $incidentQuery = Incident::with(['borrower', 'evidenceFile', 'custody.request', 'custody.lines.requestItem', 'lines'])->latest('reported_at');
        $billingQuery = BillingStatement::with(['borrower', 'lines', 'payments', 'documents'])->latest('issued_at');
        $restrictionQuery = BorrowerRestriction::latest('effective_from');
        $overdueQuery = OverdueCase::with(['borrower', 'custody.lines', 'penalties'])->latest('overdue_started_at');
        $violationQuery = BorrowerViolation::with(['borrower', 'custody.request', 'academicPeriod', 'sanction'])
            ->latest('detected_at');
        $sanctionQuery = Sanction::with(['borrower', 'academicPeriod', 'violation', 'confirmedBy'])
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
        ]);
    }

    /**
     * Financial late-return assessment. This is separate from sanctions.
     */
    public function billOverdue(
        Request $request,
        OverdueCase $overdue,
        DocumentService $documents,
        AuditService $audit
    ): RedirectResponse {
        $this->authorizeSpmu($request);

        $data = $request->validate([
            'basis' => ['required', 'string', 'max:2000'],
            'due_at' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $overdue->loadMissing('custody.lines');

        if ($overdue->status === 'OVERDUE') {
            return back()->withErrors([
                'overdue' => 'The item is still overdue. Record the physical return first so the final late-return fee can be determined.',
            ]);
        }

        if ($overdue->status !== 'RETURNED_PENDING_SETTLEMENT') {
            return back()->withErrors([
                'overdue' => 'This late-return case is not ready for a new Billing Statement.',
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

        $billing = DB::transaction(function () use ($overdue, $request, $data, $documents, $audit): BillingStatement {
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
                'line_type' => 'LATE_RETURN_FEE',
                'description' => 'Date-based late-return fee',
                'basis' => $data['basis'],
                'amount' => $penalty->amount,
            ]);

            BorrowerRestriction::query()
                ->where('borrower_user_id', $overdue->borrower_user_id)
                ->where('restriction_type', 'OVERDUE_RETURN')
                ->where('status', 'ACTIVE')
                ->update([
                    'penalty_id' => $penalty->id,
                    'billing_statement_id' => $billing->id,
                    'reason' => 'Outstanding late-return billing '.$billing->billing_no.'.',
                ]);

            $overdue->update(['status' => 'BILLED']);
            $documents->billingStatement($billing);

            $audit->record(
                'LATE_RETURN_FEE_BILLED',
                $billing,
                reason: $data['basis'],
                after: [
                    'amount' => $penalty->amount,
                    'rate' => $penalty->rate_snapshot,
                    'sanction_created' => false,
                ]
            );

            return $billing;
        }, 3);

        return back()->with('status', "Billing Statement {$billing->billing_no} generated. Print and wet-sign it before the borrower proceeds to the CSPC Cashier.");
    }

    public function billIncident(
        Request $request,
        Incident $incident,
        DocumentService $documents,
        AuditService $audit
    ): RedirectResponse {
        $this->authorizeSpmu($request);

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

            $documents->billingStatement($billing);
            $audit->record(
                'ACCOUNTABILITY_BILLING_STATEMENT_ISSUED',
                $billing,
                reason: $data['basis'],
                after: [
                    'amount' => $data['amount'],
                    'source' => $incident->incident_no,
                ]
            );

            return $billing;
        }, 3);

        return back()->with('status', "Billing Statement {$billing->billing_no} generated. Print and wet-sign it before cashier payment.");
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

            $settled = $this->settleBillingIfFullyPaid($billing, $request->user()->id);
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
            $message = $settled
                ? "Payment for {$billing->billing_no} was confirmed by SPMU. The billing is now settled."
                : "Payment for {$billing->billing_no} was confirmed by SPMU. Remaining balance: PHP "
                    .number_format($remainingBalance, 2).'.';

            $notifications->send(
                'PAYMENT_VERIFIED',
                collect([$billing->borrower]),
                $message,
                $billing
            );
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

            $this->settleBillingIfFullyPaid($billing, $request->user()->id);

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
                    "CSPC Cashier receipt {$payment->official_receipt_no} for {$billing->billing_no} was confirmed by SPMU.",
                    $billing
                );
            }
        }, 3);

        return back()->with('status', $data['decision'] === 'VERIFIED'
            ? 'Legacy Cashier payment record confirmed.'
            : 'Legacy payment record returned for correction.');
    }

    public function waive(
        Request $request,
        BillingStatement $billing,
        AuditService $audit
    ): RedirectResponse {
        abort_unless(
            $request->user()->access_classification === AccessClassification::SpmuHead,
            403,
            'Only the SPMU Head may authorize a billing waiver.'
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

            BorrowerRestriction::query()
                ->where('billing_statement_id', $billing->id)
                ->where('status', 'ACTIVE')
                ->update([
                    'status' => 'LIFTED',
                    'effective_to' => now(),
                    'lifted_by_user_id' => $request->user()->id,
                ]);

            $incidentIds = $billing->lines()->whereNotNull('incident_id')->pluck('incident_id');
            $penaltyIds = $billing->lines()->whereNotNull('penalty_id')->pluck('penalty_id');
            Incident::query()->whereKey($incidentIds)->update(['status' => 'RESOLVED']);
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

        return back()->with('status', 'Authorized waiver recorded and related financial restriction re-evaluated.');
    }

    /**
     * Final Head-level resolution for a property accountability case that does
     * not require an open Billing Statement. Financial cases must continue
     * through billing settlement or an authorized billing waiver instead.
     */
    public function resolveIncident(
        Request $request,
        Incident $incident,
        AuditService $audit,
        NotificationService $notifications,
        PolicyService $policy
    ): RedirectResponse {
        abort_unless(
            $request->user()->access_classification === AccessClassification::SpmuHead,
            403,
            'Only the SPMU Head may record the final resolution of a property accountability case.'
        );

        $data = $request->validate([
            'resolution_outcome' => ['required', 'in:NO_BORROWER_CHARGE,COMPLIANCE_REQUIRED,BILLING_REQUIRED,COMPLIANCE_COMPLETED,ADMINISTRATIVELY_CLEARED'],
            'resolution_remarks' => ['required', 'string', 'max:2000'],
            'count_as_offense' => ['nullable', 'boolean'],
        ]);

        $countAsOffense = $request->boolean('count_as_offense');
        $recordedSanction = null;

        if (DB::table('billing_lines')->where('incident_id', $incident->id)->exists()) {
            return back()->withErrors([
                'incident' => 'This property case already has a Billing Statement. Continue through billing settlement or an authorized billing waiver instead of recording another Head decision.',
            ]);
        }

        $outcomeLabel = match ($data['resolution_outcome']) {
            'NO_BORROWER_CHARGE' => 'No borrower liability / no charge',
            'COMPLIANCE_REQUIRED' => 'Repair / replacement / compliance required',
            'BILLING_REQUIRED' => 'Billing / payment required',
            'COMPLIANCE_COMPLETED' => 'Required compliance completed',
            'ADMINISTRATIVELY_CLEARED' => 'Administratively cleared',
        };

        $isInterimDecision = in_array($data['resolution_outcome'], ['COMPLIANCE_REQUIRED', 'BILLING_REQUIRED'], true);

        DB::transaction(function () use ($incident, $request, $data, $outcomeLabel, $isInterimDecision, $audit, $notifications, $policy, $countAsOffense, &$recordedSanction): void {
            $incident = Incident::query()->lockForUpdate()->findOrFail($incident->id);

            if (in_array($incident->status, ['RESOLVED', 'CLOSED', 'VOID_CORRECTION'], true)) {
                return;
            }

            $previousStatus = $incident->status;
            $existingRemarks = trim((string) $incident->remarks);
            $decisionNote = 'SPMU Head decision: '.$outcomeLabel.'. '.$data['resolution_remarks'];

            if ($countAsOffense) {
                $recordedSanction = $policy->confirmIncidentOffense(
                    $incident,
                    $request->user(),
                    $data['resolution_remarks']
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
                    ]
                );

                $incident->loadMissing('borrower');
                if ($incident->borrower) {
                    $borrowerMessage = $nextStatus === 'FOR_BILLING'
                        ? "Property accountability case {$incident->incident_no} was reviewed by the SPMU Head and requires billing/payment processing. Your linked borrowing restriction remains active until the obligation is settled or formally waived."
                        : "Property accountability case {$incident->incident_no} was reviewed by the SPMU Head and requires repair, replacement, or other compliance. Coordinate with SPMU. Your linked borrowing restriction remains active until compliance is verified.";

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
                ]
            );

            $incident->loadMissing('borrower');
            if ($incident->borrower) {
                $notifications->send(
                    'PROPERTY_ACCOUNTABILITY_CASE_RESOLVED',
                    collect([$incident->borrower]),
                    "Property accountability case {$incident->incident_no} has been resolved by SPMU. The restriction linked to this case has been lifted. Any other active obligation or restriction on your account still applies.",
                    $incident,
                    ['SYSTEM', 'EMAIL']
                );
            }
        }, 3);

        $sanctionSuffix = $recordedSanction
            ? ' Administrative offense recorded: '.$recordedSanction->offense_no.' offense — '.$recordedSanction->sanction_label.'.'
            : '';

        if ($isInterimDecision) {
            return back()->with('status', ($data['resolution_outcome'] === 'BILLING_REQUIRED'
                ? 'Head decision recorded. The case is now for billing/payment processing and the linked restriction remains active.'
                : 'Head decision recorded. Required compliance remains open and the linked restriction stays active until SPMU verifies completion.').$sanctionSuffix);
        }

        return back()->with('status', 'Property accountability case resolved. Its linked borrowing restriction was lifted.'.$sanctionSuffix);
    }

    public function reviewViolation(
        Request $request,
        BorrowerViolation $violation,
        PolicyService $policy
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

        return back()->with(
            'status',
            "Violation confirmed. {$sanction->sanction_label} was recorded by the SPMU Head."
        );
    }

    /**
     * Settle a Billing Statement once confirmed payments cover the full amount.
     * Financial settlement and administrative sanctions remain separate.
     */
    private function settleBillingIfFullyPaid(BillingStatement $billing, int $actorUserId): bool
    {
        $confirmedAmount = (float) $billing->payments()
            ->where('status', 'VERIFIED')
            ->sum('amount');

        if ($confirmedAmount + 0.0001 < (float) $billing->total_amount) {
            return false;
        }

        $billing->update(['status' => 'SETTLED']);

        BorrowerRestriction::query()
            ->where('billing_statement_id', $billing->id)
            ->where('status', 'ACTIVE')
            ->update([
                'status' => 'LIFTED',
                'effective_to' => now(),
                'lifted_by_user_id' => $actorUserId,
            ]);

        $incidentIds = $billing->lines()->whereNotNull('incident_id')->pluck('incident_id');
        Incident::query()
            ->whereKey($incidentIds)
            ->where('status', 'BILLING_PENDING')
            ->update(['status' => 'RESOLVED']);

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

    private function attemptCloseCustody(int $custodyId): void
    {
        $custody = CustodyTransaction::query()->find($custodyId);

        if ($custody) {
            app(CustodyService::class)->reconcileTransactionStatus($custody);
        }
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
