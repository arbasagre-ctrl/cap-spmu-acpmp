<?php

namespace App\Reports\Builders;

use App\Models\BillingStatement;
use App\Reports\ReportBuilder;
use App\Reports\ReportCatalogue;
use App\Reports\ReportDataset;
use App\Reports\ReportFilters;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Billing Settlement Report.
 *
 * One row per Billing Statement, read through the current one-step Cashier
 * Payment flow: AccountabilityController::confirmPayment() creates a Payment
 * already VERIFIED in the same action that records it, so "amount paid" here
 * sums VERIFIED payments only - the exact balance calculation
 * confirmPayment()/verifyPayment() themselves use. A PENDING_VERIFICATION
 * payment is historical data from the retired two-step workflow (see
 * AccountabilityController::verifyPayment()'s own guard); it is still shown
 * on the statement but never counted as paid, and this report never re-opens
 * or replays that retired verification step.
 */
class BillingSettlementReport implements ReportBuilder
{
    public function build(ReportFilters $filters): ReportDataset
    {
        $from = $filters->from;
        $to = $filters->to;
        $borrower = $filters->get('borrower');
        $billingStatus = $filters->get('billing_status');
        $paymentStatus = $filters->get('payment_status');

        $statements = BillingStatement::query()
            ->with([
                'borrower',
                'lines.incident.lines.custodyLine.requestItem.inventoryItem',
                'lines.penalty.custody.lines.requestItem.inventoryItem',
                'payments.verifiedBy',
            ])
            ->whereBetween('issued_at', [$from, $to])
            ->when($borrower !== null, fn ($q) => $q->where('borrower_user_id', (int) $borrower))
            ->get();

        $rows = $statements->map(function (BillingStatement $billing): array {
            $line = $billing->lines->first();
            $incident = $line?->incident;
            $penalty = $line?->penalty;

            $affectedProperty = $incident
                ? (string) ($incident->lines->first()?->custodyLine?->requestItem?->inventoryItem?->unique_description ?? '')
                : (string) ($penalty?->custody?->lines
                    ->map(fn ($custodyLine) => $custodyLine->requestItem?->inventoryItem?->unique_description)
                    ->filter()
                    ->unique()
                    ->implode(', ') ?? '');

            $basis = $incident
                ? $this->titleCase((string) $incident->incident_type)
                : ($penalty ? $this->titleCase((string) $penalty->penalty_type) : $this->titleCase((string) ($line?->line_type ?? '')));

            $verifiedPayments = $billing->payments->where('status', 'VERIFIED');
            $amountAssessed = (float) $billing->total_amount;
            $amountPaid = (float) $verifiedPayments->sum('amount');
            $remaining = max(0.0, $amountAssessed - $amountPaid);

            $latestPayment = $billing->payments->sortByDesc(
                fn ($payment) => $payment->verified_at ?? $payment->submitted_at
            )->first();

            $paymentStatusCode = match (true) {
                $billing->status === 'WAIVED' => 'WAIVED',
                $billing->status === 'VOID' => 'VOID',
                $amountPaid <= 0.0 => 'UNPAID',
                $remaining <= 0.0001 => 'PAID',
                default => 'PARTIALLY_PAID',
            };

            return [
                '_borrower_id' => (int) $billing->borrower_user_id,
                '_billing_status_code' => (string) $billing->status,
                '_payment_status_code' => $paymentStatusCode,

                'billing_no' => (string) $billing->billing_no,
                'case_reference' => (string) ($incident->incident_no ?? ''),
                'borrower' => (string) ($billing->borrower?->full_name ?? ''),
                'affected_property' => $affectedProperty,
                'basis' => $basis,
                'amount_assessed' => $this->money($amountAssessed),
                'amount_paid' => $this->money($amountPaid),
                'remaining_balance' => $this->money($remaining),
                'official_receipt_no' => $billing->payments
                    ->pluck('official_receipt_no')
                    ->filter()
                    ->unique()
                    ->implode('; '),
                'payment_date' => $this->date($latestPayment?->receipt_date),
                'verified_by' => (string) ($latestPayment?->verifiedBy?->full_name ?? ''),
                'billing_status' => $this->titleCase((string) $billing->status),
                'payment_status' => $this->paymentStatusLabel($paymentStatusCode),
                'issued_at' => $this->date($billing->issued_at),
            ];
        });

        $filtered = $rows
            ->when(
                $borrower !== null,
                fn (Collection $rows): Collection => $rows->filter(fn (array $row): bool => $row['_borrower_id'] === (int) $borrower)
            )
            ->when(
                $billingStatus !== null,
                fn (Collection $rows): Collection => $rows->filter(fn (array $row): bool => $row['_billing_status_code'] === $billingStatus)
            )
            ->when(
                $paymentStatus !== null,
                fn (Collection $rows): Collection => $rows->filter(fn (array $row): bool => $row['_payment_status_code'] === $paymentStatus)
            )
            ->values()
            ->map(fn (array $row): array => collect($row)->except([
                '_borrower_id',
                '_billing_status_code',
                '_payment_status_code',
            ])->all());

        return new ReportDataset(
            reportKey: 'billing-settlement',
            label: ReportCatalogue::definition('billing-settlement')['label'],
            columns: [
                ['key' => 'billing_no', 'label' => 'Billing No.'],
                ['key' => 'case_reference', 'label' => 'Case / Reference No.'],
                ['key' => 'borrower', 'label' => 'Borrower'],
                ['key' => 'affected_property', 'label' => 'Affected Property'],
                ['key' => 'basis', 'label' => 'Basis'],
                ['key' => 'amount_assessed', 'label' => 'Amount Assessed', 'align' => 'numeric'],
                ['key' => 'amount_paid', 'label' => 'Amount Paid', 'align' => 'numeric'],
                ['key' => 'remaining_balance', 'label' => 'Remaining Balance', 'align' => 'numeric'],
                ['key' => 'official_receipt_no', 'label' => 'Official Receipt No.'],
                ['key' => 'payment_date', 'label' => 'Payment Date'],
                ['key' => 'verified_by', 'label' => 'Verified / Recorded By'],
                ['key' => 'billing_status', 'label' => 'Billing Status', 'badge' => true],
                ['key' => 'payment_status', 'label' => 'Payment Status', 'badge' => true],
                ['key' => 'issued_at', 'label' => 'Issued'],
            ],
            rows: $filtered,
            summary: [
                'Statements' => $filtered->count(),
                'Paid' => $filtered->where('payment_status', 'Paid')->count(),
                'Partially paid' => $filtered->where('payment_status', 'Partially Paid')->count(),
                'Unpaid' => $filtered->where('payment_status', 'Unpaid')->count(),
            ],
        );
    }

    private function paymentStatusLabel(string $code): string
    {
        return match ($code) {
            'PAID' => 'Paid',
            'PARTIALLY_PAID' => 'Partially Paid',
            'WAIVED' => 'Waived',
            'VOID' => 'Void',
            default => 'Unpaid',
        };
    }

    private function titleCase(string $value): string
    {
        return str($value)->replace('_', ' ')->title()->toString();
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function date(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('d M Y') : '';
    }
}
