<?php

namespace App\Reports\Builders;

use App\Models\BorrowerViolation;
use App\Models\CustodyTransaction;
use App\Models\Incident;
use App\Reports\ReportBuilder;
use App\Reports\ReportCatalogue;
use App\Reports\ReportDataset;
use App\Reports\ReportFilters;
use App\Support\OrganizationalStructure;
use Illuminate\Support\Collection;

/**
 * Return & Accountability Report.
 *
 * Consolidates what were three separate reports — Custody/Return's return
 * half, Overdue, and Accountability/Incident — into one detailed record per
 * custody transaction.
 *
 * One row per custody transaction is the rule that keeps the consolidation
 * honest. A transaction that is both overdue and carrying an open incident
 * previously appeared in two reports and would double-count here; instead it
 * is one row whose return status and accountability columns both say so.
 *
 * Return state is derived from the custody lines and return transactions,
 * which are authoritative once custody exists. Nothing is inferred from the
 * borrowing request's approval status.
 */
class ReturnAccountabilityReport implements ReportBuilder
{
    public function build(ReportFilters $filters): ReportDataset
    {
        $from = $filters->from;
        $to = $filters->to;

        /*
         * A transaction belongs in the period if its custody lifecycle
         * touched it, or if a return, overdue case or incident was recorded
         * against it inside the period. Accountability opened late on an
         * older borrowing must still be reportable.
         */
        $custodies = CustodyTransaction::query()
            ->with([
                'borrower',
                'request.currentVersion',
                'lines',
                'returns',
                'overdueCase',
            ])
            ->where(function ($query) use ($from, $to): void {
                $query->whereBetween('created_at', [$from, $to])
                    ->orWhereBetween('released_at', [$from, $to])
                    ->orWhereBetween('closed_at', [$from, $to])
                    ->orWhereHas('returns', fn ($returns) => $returns->whereBetween('received_at', [$from, $to]))
                    ->orWhereHas('overdueCase', fn ($cases) => $cases->whereBetween('created_at', [$from, $to]));
            })
            ->latest('created_at')
            ->get();

        /*
         * Incidents and confirmed violations are keyed by custody id in one
         * query each rather than per row, so the report stays off the N+1
         * path however many transactions the period holds.
         */
        $custodyIds = $custodies->pluck('id');

        $incidents = Incident::query()
            ->whereIn('custody_transaction_id', $custodyIds)
            ->get()
            ->groupBy('custody_transaction_id');

        $violations = BorrowerViolation::query()
            ->where('status', 'CONFIRMED')
            ->whereIn('custody_transaction_id', $custodyIds)
            ->get()
            ->groupBy('custody_transaction_id');

        $incidentOnlyCustodies = Incident::query()
            ->whereBetween('reported_at', [$from, $to])
            ->whereNotIn('custody_transaction_id', $custodyIds)
            ->pluck('custody_transaction_id')
            ->unique();

        if ($incidentOnlyCustodies->isNotEmpty()) {
            $extra = CustodyTransaction::query()
                ->with(['borrower', 'request.currentVersion', 'lines', 'returns', 'overdueCase'])
                ->whereIn('id', $incidentOnlyCustodies)
                ->get();

            $custodies = $custodies->concat($extra);

            $incidents = Incident::query()
                ->whereIn('custody_transaction_id', $custodies->pluck('id'))
                ->get()
                ->groupBy('custody_transaction_id');
        }

        $division = $filters->get('division');
        $unit = $filters->get('unit');
        $returnStatus = $filters->get('return_status');
        $openAccountability = $filters->get('open_accountability');

        $rows = $custodies
            ->unique('id')
            ->map(function (CustodyTransaction $custody) use ($incidents, $violations): array {
                $version = $custody->request?->currentVersion;

                $released = (float) $custody->lines->sum(
                    fn ($line): float => (float) $line->actual_released_quantity
                );

                $returned = (float) $custody->lines->sum(
                    fn ($line): float => (float) $line->returned_quantity
                );

                $outstanding = max(0.0, $released - $returned);

                $due = $version?->return_date
                    ?: ($version?->return_due_at ?: $custody->due_at);

                $lastReturnAt = $custody->returns
                    ->filter(fn ($return) => $return->received_at !== null)
                    ->max('received_at');

                $custodyIncidents = $incidents->get($custody->id, collect());
                $custodyViolations = $violations->get($custody->id, collect());

                $openIncidents = $custodyIncidents->filter(
                    fn ($incident): bool => $incident->status !== 'RESOLVED'
                );

                $accountabilityOpen = $openIncidents->isNotEmpty()
                    || in_array($custody->status, ['INCIDENT_OPEN', 'OBLIGATION_OPEN'], true);

                $returnState = $this->returnState($custody, $outstanding, $due, $lastReturnAt);

                return [
                    '_return_state' => $returnState,
                    '_accountability_open' => $accountabilityOpen,
                    '_division_code' => (string) ($version?->division_code ?? ''),
                    '_office_unit' => (string) ($version?->office_unit ?? ''),
                    '_link' => route('custody.show', $custody),
                    '_tone_return_status' => match ($returnState) {
                        'RETURNED_ON_TIME' => 'positive',
                        'RETURNED_LATE' => 'attention',
                        'CURRENTLY_OVERDUE' => 'critical',
                        default => 'progress',
                    },
                    '_tone_accountability' => $accountabilityOpen ? 'critical' : 'neutral',

                    'custody_no' => (string) $custody->custody_no,
                    'request_no' => (string) ($custody->request?->request_no ?? ''),
                    'borrower' => (string) ($custody->borrower?->full_name ?? ''),
                    'division' => $version?->division_code
                        ? OrganizationalStructure::label($version->division_code)
                        : '',
                    'office_unit' => (string) ($version?->office_unit ?? ''),
                    'released_at' => $this->dateTime($custody->released_at),
                    'due_at' => $this->date($due),
                    'last_return_at' => $this->dateTime($lastReturnAt),
                    'return_status' => $this->returnLabel($returnState),
                    'released_quantity' => $this->number($released),
                    'returned_quantity' => $this->number($returned),
                    'outstanding_quantity' => $this->number($outstanding),
                    'overdue_started_at' => $this->dateTime($custody->overdueCase?->overdue_started_at),
                    'overdue_status' => (string) ($custody->overdueCase?->status ?? ''),
                    'incidents' => (string) $custodyIncidents->count(),
                    'open_incidents' => (string) $openIncidents->count(),
                    'confirmed_violations' => (string) $custodyViolations->count(),
                    'accountability' => $accountabilityOpen ? 'Open Accountability' : 'Closed',
                    'remarks' => $openIncidents
                        ->map(fn ($incident): string => trim(
                            (string) $incident->incident_type
                            .($incident->remarks ? ': '.$incident->remarks : '')
                        ))
                        ->filter()
                        ->implode('; '),
                ];
            })
            ->when(
                $division !== null,
                fn (Collection $rows): Collection => $rows->filter(
                    fn (array $row): bool => $row['_division_code'] === $division
                )
            )
            ->when(
                $unit !== null,
                fn (Collection $rows): Collection => $rows->filter(
                    fn (array $row): bool => strcasecmp($row['_office_unit'], (string) $unit) === 0
                )
            )
            ->when(
                $returnStatus !== null,
                fn (Collection $rows): Collection => $rows->filter(
                    fn (array $row): bool => $row['_return_state'] === $returnStatus
                )
            )
            ->when(
                $openAccountability !== null,
                fn (Collection $rows): Collection => $rows->filter(
                    fn (array $row): bool => $row['_accountability_open'] === ($openAccountability === 'OPEN')
                )
            )
            ->values();

        return new ReportDataset(
            reportKey: 'returns',
            label: ReportCatalogue::definition('returns')['label'],
            columns: [
                ['key' => 'custody_no', 'label' => 'Custody No.'],
                ['key' => 'request_no', 'label' => 'Request No.'],
                ['key' => 'borrower', 'label' => 'Borrower'],
                ['key' => 'division', 'label' => 'Division'],
                ['key' => 'office_unit', 'label' => 'Office / Unit'],
                ['key' => 'released_at', 'label' => 'Released'],
                ['key' => 'due_at', 'label' => 'Expected Return'],
                ['key' => 'last_return_at', 'label' => 'Returned'],
                ['key' => 'return_status', 'label' => 'Return Status', 'badge' => true],
                ['key' => 'released_quantity', 'label' => 'Released Qty', 'align' => 'numeric'],
                ['key' => 'returned_quantity', 'label' => 'Returned Qty', 'align' => 'numeric'],
                ['key' => 'outstanding_quantity', 'label' => 'Outstanding Qty', 'align' => 'numeric'],
                ['key' => 'overdue_started_at', 'label' => 'Overdue Since'],
                ['key' => 'overdue_status', 'label' => 'Overdue Case'],
                ['key' => 'incidents', 'label' => 'Incidents', 'align' => 'numeric'],
                ['key' => 'open_incidents', 'label' => 'Open Incidents', 'align' => 'numeric'],
                ['key' => 'confirmed_violations', 'label' => 'Confirmed Violations', 'align' => 'numeric'],
                ['key' => 'accountability', 'label' => 'Accountability Status', 'badge' => true],
                ['key' => 'remarks', 'label' => 'Remarks'],
            ],
            rows: $rows,
            summary: [
                'Transactions' => $rows->count(),
                'Returned on time' => $rows->where('_return_state', 'RETURNED_ON_TIME')->count(),
                'Returned late' => $rows->where('_return_state', 'RETURNED_LATE')->count(),
                'Currently overdue' => $rows->where('_return_state', 'CURRENTLY_OVERDUE')->count(),
                'Still on custody' => $rows->where('_return_state', 'ON_CUSTODY')->count(),
                'Open accountability' => $rows->where('_accountability_open', true)->count(),
            ],
        );
    }

    /**
     * Return state for one transaction.
     *
     * Fully returned decides on-time versus late by comparing the last
     * physical receipt against the expected return date. Anything still held
     * is overdue only once that date has actually passed.
     */
    private function returnState(
        CustodyTransaction $custody,
        float $outstanding,
        mixed $due,
        mixed $lastReturnAt
    ): string {
        $fullyReturned = $outstanding <= 0.0
            && ($lastReturnAt !== null || $custody->closed_at !== null);

        if ($fullyReturned) {
            if ($due && $lastReturnAt && $lastReturnAt->greaterThan($due)) {
                return 'RETURNED_LATE';
            }

            return 'RETURNED_ON_TIME';
        }

        if ($custody->status === 'OVERDUE' || ($due && now()->greaterThan($due))) {
            return 'CURRENTLY_OVERDUE';
        }

        return 'ON_CUSTODY';
    }

    private function returnLabel(string $state): string
    {
        return match ($state) {
            'RETURNED_ON_TIME' => 'Returned on time',
            'RETURNED_LATE' => 'Returned late',
            'CURRENTLY_OVERDUE' => 'Currently overdue',
            'ON_CUSTODY' => 'Still on custody',
            default => str($state)->replace('_', ' ')->title()->toString(),
        };
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }

    private function date(mixed $value): string
    {
        return $value ? $value->format('d M Y') : '';
    }

    private function dateTime(mixed $value): string
    {
        return $value ? $value->format('d M Y, g:i A') : '';
    }
}
