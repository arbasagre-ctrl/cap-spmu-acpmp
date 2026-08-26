<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Enums\UserRole;
use App\Models\BillingStatement;
use App\Models\BorrowerViolation;
use App\Models\BorrowerRestriction;
use App\Models\CustodyTransaction;
use App\Models\Incident;
use App\Models\LaundryRecord;
use App\Models\OverdueCase;
use App\Models\Payment;
use App\Models\Penalty;
use App\Models\Sanction;
use App\Models\User;
use App\Services\AuditService;
use App\Services\DocumentService;
use App\Services\NotificationService;
use App\Services\ProtectedFileService;
use App\Services\PolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AccountabilityController extends Controller
{
    public function index(Request $request): View
    {
        $incidentQuery = Incident::with(['borrower', 'custody.request', 'lines'])->latest('reported_at');
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

        return view('accountability.index', [
            'incidents' => $incidentQuery->get(),
            'billings' => $billingQuery->get(),
            'restrictions' => $restrictionQuery->get(),
            'overdueCases' => $overdueQuery->get(),
            'violations' => $violationQuery->get(),
            'sanctions' => $sanctionQuery->get(),
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

        if (! $overdue->custody->lines->every(
            fn ($line) => (float) $line->returned_quantity >= (float) $line->actual_released_quantity
        )) {
            return back()->withErrors([
                'overdue' => 'All issued quantity must first receive a physical return/accountability disposition so the final date-based late duration is known.',
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
     * SPMU receives the CSPC Cashier paid receipt, scans/uploads it, and records
     * its structured payment details. Borrowers do not upload payment evidence.
     */
    public function recordPayment(
        Request $request,
        BillingStatement $billing,
        ProtectedFileService $files,
        AuditService $audit,
        NotificationService $notifications
    ): RedirectResponse {
        $this->authorizeSpmu($request);
        abort_if(in_array($billing->status, ['SETTLED', 'WAIVED', 'VOID'], true), 403);

        $data = $request->validate([
            'evidence' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:5120'],
            'official_receipt_no' => ['required', 'string', 'max:255'],
            'receipt_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $file = $files->storeUpload(
            $data['evidence'],
            'payment-evidence',
            'CSPC_CASHIER_PAID_RECEIPT'
        );

        $payment = Payment::query()->create([
            'billing_statement_id' => $billing->id,
            'evidence_file_id' => $file->id,
            'recorded_by_user_id' => $request->user()->id,
            'official_receipt_no' => $data['official_receipt_no'],
            'receipt_date' => $data['receipt_date'],
            'amount' => $data['amount'],
            'status' => 'PENDING_VERIFICATION',
            'submitted_at' => now(),
            'verification_remarks' => $data['remarks'] ?? null,
        ]);

        $billing->update(['status' => 'RECEIPT_SUBMITTED']);

        $audit->record(
            'CASHIER_RECEIPT_UPLOADED_BY_SPMU',
            $payment,
            reason: $data['remarks'] ?? null,
            after: [
                'evidence_file_id' => $file->id,
                'receipt_no' => $data['official_receipt_no'],
                'receipt_date' => $data['receipt_date'],
                'amount' => $data['amount'],
            ]
        );

        $notifications->send(
            'PAYMENT_RECEIPT_PENDING_VERIFICATION',
            $this->spmuUsers(),
            "CSPC Cashier paid receipt for {$billing->billing_no} was uploaded by SPMU and is pending verification.",
            $billing
        );

        return back()->with('status', 'Paid CSPC Cashier receipt uploaded. Verify it before marking the billing as paid.');
    }

    public function verifyPayment(
        Request $request,
        Payment $payment,
        AuditService $audit,
        NotificationService $notifications
    ): RedirectResponse {
        $this->authorizeSpmu($request);

        $data = $request->validate([
            'decision' => ['required', 'in:VERIFIED,REJECTED'],
            'remarks' => ['required', 'string', 'max:1000'],
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
                    'rejection_reason' => $data['remarks'],
                    'verification_remarks' => $data['remarks'],
                ]);

                $billing->update(['status' => 'ISSUED']);
                $audit->record('PAYMENT_RECEIPT_REJECTED', $payment, reason: $data['remarks']);
                $notifications->send(
                    'PAYMENT_RECEIPT_REJECTED',
                    collect([$billing->borrower]),
                    "The uploaded paid receipt for {$billing->billing_no} requires correction: {$data['remarks']}",
                    $billing
                );

                return;
            }

            $payment->update([
                'verified_by_user_id' => $request->user()->id,
                'status' => 'VERIFIED',
                'verified_at' => now(),
                'verification_remarks' => $data['remarks'],
                'rejection_reason' => null,
            ]);

            $verifiedAmount = (float) $billing->payments()
                ->where('status', 'VERIFIED')
                ->sum('amount');

            if ($verifiedAmount + 0.0001 >= (float) $billing->total_amount) {
                $billing->update(['status' => 'SETTLED']);

                BorrowerRestriction::query()
                    ->where('billing_statement_id', $billing->id)
                    ->where('status', 'ACTIVE')
                    ->update([
                        'status' => 'LIFTED',
                        'effective_to' => now(),
                        'lifted_by_user_id' => $request->user()->id,
                    ]);

                $incidentIds = $billing->lines()->whereNotNull('incident_id')->pluck('incident_id');
                Incident::query()->whereKey($incidentIds)->where('status', 'BILLING_PENDING')->update(['status' => 'RESOLVED']);

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
            }

            $audit->record(
                'CASHIER_PAYMENT_VERIFIED',
                $payment,
                reason: $data['remarks'],
                after: [
                    'billing_status' => $billing->fresh()->status,
                    'receipt_no' => $payment->official_receipt_no,
                    'amount' => $payment->amount,
                ]
            );

            $notifications->send(
                'PAYMENT_VERIFIED',
                collect([$billing->borrower]),
                "CSPC Cashier receipt {$payment->official_receipt_no} for {$billing->billing_no} was verified by SPMU. Payment status: {$billing->fresh()->status}.",
                $billing
            );
        }, 3);

        return back()->with('status', $data['decision'] === 'VERIFIED'
            ? 'Paid receipt verified. The payment record is now read-only.'
            : 'Paid receipt returned for correction.');
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

    private function attemptCloseCustody(int $custodyId): void
    {
        $custody = CustodyTransaction::query()->with('lines')->find($custodyId);

        if (
            ! $custody
            || ! $custody->lines->every(
                fn ($line) => (float) $line->returned_quantity >= (float) $line->actual_released_quantity
            )
        ) {
            return;
        }

        $openIncident = Incident::query()
            ->where('custody_transaction_id', $custodyId)
            ->whereNotIn('status', ['RESOLVED', 'CLOSED', 'VOID_CORRECTION'])
            ->exists();

        $openLaundry = LaundryRecord::query()
            ->whereHas('returnLine.custodyLine', fn ($query) => $query->where('custody_transaction_id', $custodyId))
            ->whereNotIn('status', ['VERIFIED', 'VOID_CORRECTION'])
            ->exists();

        $openOverdue = OverdueCase::query()
            ->where('custody_transaction_id', $custodyId)
            ->where('status', '!=', 'RESOLVED')
            ->exists();

        $openGatePass = $custody->gatePass()->whereNotIn('status', ['VERIFIED', 'VOID'])->exists();

        if (! $openIncident && ! $openLaundry && ! $openOverdue && ! $openGatePass) {
            $custody->update([
                'status' => 'CLOSED',
                'closed_at' => now(),
            ]);
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
