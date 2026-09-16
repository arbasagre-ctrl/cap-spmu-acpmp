<?php

namespace App\Reports\Builders;

use App\Models\BorrowerViolation;
use App\Models\CustodyTransaction;
use App\Models\Incident;
use App\Reports\ReportBuilder;
use App\Reports\ReportCatalogue;
use App\Reports\ReportDataset;
use App\Reports\ReportFilters;
use App\Services\ReturnMetricsService;
use App\Support\OrganizationalStructure;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Return & Accountability Report.
 *
 * Return classification is delegated to ReturnMetricsService, the same source
 * Analytics uses. A completed-return filter therefore means a physical return
 * completed inside the selected reporting period, not merely a custody row
 * that happened to be created or closed around that period.
 */
class ReturnAccountabilityReport implements ReportBuilder
{
    public function __construct(private readonly ReturnMetricsService $returnMetrics) {}

    public function build(ReportFilters $filters): ReportDataset
    {
        $from = $filters->from;
        $to = $filters->to;
        $division = $filters->get('division');
        $unit = $filters->get('unit');
        $returnStatus = $filters->get('return_status');
        $openAccountability = $filters->get('open_accountability');
        $borrower = $filters->get('borrower');

        $completedFilter = in_array(
            $returnStatus,
            ['COMPLETED', ReturnMetricsService::RETURNED_ON_TIME, ReturnMetricsService::RETURNED_LATE],
            true
        );

        /*
         * Current overdue / on-custody are intentionally present-tense and
         * must not disappear just because the original request predates the
         * chosen reporting period. Completed-return filters, by contrast,
         * follow the physical completion event inside the period.
         */
        $query = CustodyTransaction::query()
            ->with([
                'borrower',
                'request.currentVersion',
                'requestVersion',
                'lines.requestItem.inventoryItem',
                'returns',
                'laundryJob',
                'overdueCase',
                'incidents',
            ])
            ->when($borrower !== null, fn ($q) => $q->where('borrower_user_id', (int) $borrower));

        if ($completedFilter) {
            $query->whereNotNull('released_at')
                ->where(function ($events) use ($from, $to): void {
                    $events->whereBetween('closed_at', [$from, $to])
                        ->orWhereHas(
                            'returns',
                            fn ($returns) => $returns->whereBetween('received_at', [$from, $to])
                        )
                        ->orWhereHas(
                            'laundryJob',
                            fn ($laundry) => $laundry->whereBetween('worker_received_at', [$from, $to])
                        );
                });
        } elseif (in_array($returnStatus, [ReturnMetricsService::CURRENTLY_OVERDUE, ReturnMetricsService::ON_CUSTODY], true)) {
            $query->whereNotNull('released_at');
        } elseif ($openAccountability === 'OPEN') {
            /* Event-period filtering is applied after the open-case ids are built. */
        } else {
            $query->where(function ($activity) use ($from, $to): void {
                $activity->whereBetween('created_at', [$from, $to])
                    ->orWhereBetween('released_at', [$from, $to])
                    ->orWhereBetween('closed_at', [$from, $to])
                    ->orWhereHas('returns', fn ($returns) => $returns->whereBetween('received_at', [$from, $to]))
                    ->orWhereHas('laundryJob', fn ($laundry) => $laundry->whereBetween('worker_received_at', [$from, $to]))
                    ->orWhereHas('overdueCase', fn ($cases) => $cases->whereBetween('created_at', [$from, $to]))
                    ->orWhereHas('incidents', fn ($incidents) => $incidents->whereBetween('reported_at', [$from, $to]));
            });
        }

        $custodies = $query->latest('created_at')->get();

        /*
         * Accountability opened in this reporting period. This is the same
         * one-case-per-custody population used by the Analytics KPI.
         */
        $incidentInPeriod = Incident::query()
            ->whereBetween('reported_at', [$from, $to])
            ->whereNotIn('status', ['RESOLVED', 'CLOSED', 'VOID_CORRECTION'])
            ->pluck('custody_transaction_id');

        $lateInPeriod = DB::table('overdue_cases')
            ->whereNotNull('actual_return_date')
            ->whereBetween('actual_return_date', [
                Carbon::parse($from)->toDateString(),
                Carbon::parse($to)->toDateString(),
            ])
            ->where('status', '!=', 'RESOLVED')
            ->pluck('custody_transaction_id');

        $billingInPeriod = DB::table('billing_statements')
            ->join('billing_lines', 'billing_lines.billing_statement_id', '=', 'billing_statements.id')
            ->join('penalties', 'penalties.id', '=', 'billing_lines.penalty_id')
            ->whereBetween('billing_statements.issued_at', [$from, $to])
            ->whereNotIn('billing_statements.status', ['SETTLED', 'WAIVED', 'VOID'])
            ->pluck('penalties.custody_transaction_id');

        $accountabilityInPeriodIds = $incidentInPeriod
            ->concat($lateInPeriod)
            ->concat($billingInPeriod)
            ->filter()
            ->unique()
            ->values();

        if ($openAccountability === 'OPEN') {
            $missing = $accountabilityInPeriodIds->diff($custodies->pluck('id'));

            if ($missing->isNotEmpty()) {
                $custodies = $custodies->concat(
                    CustodyTransaction::query()
                        ->with([
                            'borrower',
                            'request.currentVersion',
                            'requestVersion',
                            'lines.requestItem.inventoryItem',
                            'returns',
                            'laundryJob',
                            'overdueCase',
                            'incidents',
                        ])
                        ->when($borrower !== null, fn ($q) => $q->where('borrower_user_id', (int) $borrower))
                        ->whereIn('id', $missing)
                        ->get()
                );
            }
        }

        $custodyIds = $custodies->pluck('id')->unique();

        $violations = BorrowerViolation::query()
            ->where('status', 'CONFIRMED')
            ->whereIn('custody_transaction_id', $custodyIds)
            ->get()
            ->groupBy('custody_transaction_id');

        /* Current open billing is part of the live accountability state. */
        $openBillingIds = DB::table('billing_statements')
            ->join('billing_lines', 'billing_lines.billing_statement_id', '=', 'billing_statements.id')
            ->join('penalties', 'penalties.id', '=', 'billing_lines.penalty_id')
            ->whereNotIn('billing_statements.status', ['SETTLED', 'WAIVED', 'VOID'])
            ->whereIn('penalties.custody_transaction_id', $custodyIds)
            ->pluck('penalties.custody_transaction_id')
            ->filter()
            ->unique();

        $rows = $custodies
            ->unique('id')
            ->map(function (CustodyTransaction $custody) use (
                $violations,
                $openBillingIds,
                $accountabilityInPeriodIds
            ): array {
                /* Immutable custody snapshot first; legacy rows fall back. */
                $version = $custody->requestVersion ?: $custody->request?->currentVersion;
                $quantities = $this->returnMetrics->quantities($custody);
                $returnedAt = $this->returnMetrics->completionMoment($custody);
                $due = $this->returnMetrics->expectedReturnDate($custody);
                $returnState = $this->returnMetrics->state($custody);

                $custodyIncidents = $custody->incidents;
                $openIncidents = $custodyIncidents->filter(
                    fn ($incident): bool => ! in_array(
                        (string) $incident->status,
                        ['RESOLVED', 'CLOSED', 'VOID_CORRECTION'],
                        true
                    )
                );

                $overdueOpen = $custody->overdueCase
                    && (string) $custody->overdueCase->status !== 'RESOLVED';

                $accountabilityOpen = $openIncidents->isNotEmpty()
                    || $overdueOpen
                    || $openBillingIds->contains($custody->id)
                    || in_array((string) $custody->status, ['INCIDENT_OPEN', 'OBLIGATION_OPEN'], true);

                $custodyViolations = $violations->get($custody->id, collect());

                return [
                    '_return_state' => $returnState,
                    '_borrower_user_id' => (int) $custody->borrower_user_id,
                    '_completed_in_period' => false,
                    '_accountability_open' => $accountabilityOpen,
                    '_accountability_in_period' => $accountabilityInPeriodIds->contains($custody->id),
                    '_division_code' => (string) ($version?->division_code ?? ''),
                    '_office_unit' => (string) ($version?->office_unit ?? ''),
                    '_link' => route('custody.show', $custody),
                    '_returned_at_raw' => $returnedAt,
                    '_tone_return_status' => match ($returnState) {
                        ReturnMetricsService::RETURNED_ON_TIME => 'positive',
                        ReturnMetricsService::RETURNED_LATE => 'attention',
                        ReturnMetricsService::CURRENTLY_OVERDUE => 'critical',
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
                    'last_return_at' => $this->dateTime($returnedAt),
                    'return_status' => $this->returnLabel($returnState),
                    'released_quantity' => $this->number($quantities['released']),
                    'returned_quantity' => $this->number($quantities['returned']),
                    'outstanding_quantity' => $this->number($quantities['outstanding']),
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
            ->map(function (array $row) use ($from, $to): array {
                $returnedAt = $row['_returned_at_raw'];
                $row['_completed_in_period'] = $returnedAt !== null
                    && Carbon::parse($returnedAt)->startOfDay()->betweenIncluded(
                        Carbon::parse($from)->startOfDay(),
                        Carbon::parse($to)->endOfDay()
                    );

                return $row;
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
                fn (Collection $rows): Collection => $rows->filter(function (array $row) use ($returnStatus): bool {
                    if ($returnStatus === 'COMPLETED') {
                        return $row['_completed_in_period']
                            && in_array(
                                $row['_return_state'],
                                [ReturnMetricsService::RETURNED_ON_TIME, ReturnMetricsService::RETURNED_LATE],
                                true
                            );
                    }

                    if (in_array(
                        $returnStatus,
                        [ReturnMetricsService::RETURNED_ON_TIME, ReturnMetricsService::RETURNED_LATE],
                        true
                    )) {
                        return $row['_completed_in_period'] && $row['_return_state'] === $returnStatus;
                    }

                    return $row['_return_state'] === $returnStatus;
                })
            )
            ->when(
                $openAccountability !== null,
                fn (Collection $rows): Collection => $rows->filter(
                    fn (array $row): bool => $openAccountability === 'OPEN'
                        ? ($row['_accountability_open'] && $row['_accountability_in_period'])
                        : ! $row['_accountability_open']
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
                'Returned on time' => $rows->where('_return_state', ReturnMetricsService::RETURNED_ON_TIME)->count(),
                'Returned late' => $rows->where('_return_state', ReturnMetricsService::RETURNED_LATE)->count(),
                'Currently overdue' => $rows->where('_return_state', ReturnMetricsService::CURRENTLY_OVERDUE)->count(),
                'Still on custody' => $rows->where('_return_state', ReturnMetricsService::ON_CUSTODY)->count(),
                'Open accountability' => $rows->where('_accountability_open', true)->count(),
            ],
        );
    }

    private function returnLabel(string $state): string
    {
        return match ($state) {
            ReturnMetricsService::RETURNED_ON_TIME => 'Returned on time',
            ReturnMetricsService::RETURNED_LATE => 'Returned late',
            ReturnMetricsService::CURRENTLY_OVERDUE => 'Currently overdue',
            ReturnMetricsService::ON_CUSTODY => 'Still on custody',
            default => str($state)->replace('_', ' ')->title()->toString(),
        };
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }

    private function date(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('d M Y') : '';
    }

    private function dateTime(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('d M Y, g:i A') : '';
    }
}
