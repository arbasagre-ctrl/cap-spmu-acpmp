<?php

namespace App\Reports\Builders;

use App\Models\BorrowerRestriction;
use App\Models\BorrowerViolation;
use App\Models\Incident;
use App\Models\OverdueCase;
use App\Reports\ReportBuilder;
use App\Reports\ReportCatalogue;
use App\Reports\ReportDataset;
use App\Reports\ReportFilters;
use App\Support\OrganizationalStructure;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Accountability Cases Report.
 *
 * One row per accountability case, drawn from the two case types the system
 * actually tracks: a property Incident (damaged/missing/lost/stolen/destroyed
 * property, reported by SPMU) and a Late Return (an OverdueCase whose
 * physical return has already been confirmed late). These are the same two
 * case populations Return & Accountability's "Open Accountability" filter and
 * the Analytics accountability KPI already read from - this report simply
 * gives each case its own row with the fields a case file needs, instead of
 * folding them into the one-row-per-custody Return & Accountability report.
 */
class AccountabilityCasesReport implements ReportBuilder
{
    public function build(ReportFilters $filters): ReportDataset
    {
        $from = $filters->from;
        $to = $filters->to;
        $division = $filters->get('division');
        $unit = $filters->get('unit');
        $borrower = $filters->get('borrower');
        $finding = $filters->get('accountability_finding');
        $status = $filters->get('accountability_status');

        $incidents = Incident::query()
            ->with([
                'borrower',
                'custody.requestVersion',
                'custody.request.currentVersion',
                'lines.custodyLine.requestItem.inventoryItem',
            ])
            ->whereBetween('reported_at', [$from, $to])
            ->when($borrower !== null, fn ($q) => $q->where('borrower_user_id', (int) $borrower))
            ->get();

        $overdueCases = OverdueCase::query()
            ->with([
                'borrower',
                'custody.requestVersion',
                'custody.request.currentVersion',
            ])
            ->whereNotNull('actual_return_date')
            ->whereBetween('actual_return_date', [$from->toDateString(), $to->toDateString()])
            ->when($borrower !== null, fn ($q) => $q->where('borrower_user_id', (int) $borrower))
            ->get();

        $custodyIds = $incidents->pluck('custody_transaction_id')
            ->concat($overdueCases->pluck('custody_transaction_id'))
            ->filter()
            ->unique();

        $violationsByCustody = BorrowerViolation::query()
            ->where('status', 'CONFIRMED')
            ->whereIn('custody_transaction_id', $custodyIds)
            ->with('sanction')
            ->get()
            ->groupBy('custody_transaction_id');

        $restrictionsByIncident = BorrowerRestriction::query()
            ->whereIn('incident_id', $incidents->pluck('id'))
            ->get()
            ->groupBy('incident_id');

        $restrictionsByCustody = BorrowerRestriction::query()
            ->whereIn('custody_transaction_id', $custodyIds)
            ->get()
            ->groupBy('custody_transaction_id');

        $rows = collect();

        foreach ($incidents as $incident) {
            $custody = $incident->custody;
            $version = $custody?->requestVersion ?: $custody?->request?->currentVersion;
            $lines = $incident->lines;
            $item = $lines->first()?->custodyLine?->requestItem?->inventoryItem;
            $violation = $violationsByCustody->get($incident->custody_transaction_id, collect())->first();
            $restriction = $restrictionsByIncident->get($incident->id, collect())
                ->sortByDesc('id')
                ->first();

            $rows->push($this->row(
                caseReference: (string) $incident->incident_no,
                custodyNo: (string) ($custody?->custody_no ?? ''),
                borrowerName: (string) ($incident->borrower?->full_name ?? ''),
                division: $version?->division_code,
                officeUnit: (string) ($version?->office_unit ?? ''),
                caseType: 'Property Accountability',
                finding: $this->titleCase((string) $incident->incident_type),
                affectedItem: (string) ($item?->unique_description ?? ''),
                affectedQuantity: $lines->isNotEmpty() ? (string) $lines->sum('quantity') : '',
                offenseSanction: $this->offenseSanction($violation),
                restrictionStatus: $restriction ? $this->titleCase((string) $restriction->status) : '',
                finalOutcome: $this->titleCase((string) $incident->status),
                dateReported: $incident->reported_at,
                findingCode: (string) $incident->incident_type,
                statusCode: (string) $incident->status,
                caseTypeCode: 'PROPERTY',
            ));
        }

        foreach ($overdueCases as $overdue) {
            $custody = $overdue->custody;
            $version = $custody?->requestVersion ?: $custody?->request?->currentVersion;
            $violation = $violationsByCustody->get($overdue->custody_transaction_id, collect())->first();
            $restriction = $restrictionsByCustody->get($overdue->custody_transaction_id, collect())
                ->sortByDesc('id')
                ->first();

            $rows->push($this->row(
                caseReference: (string) ($custody?->custody_no ?? ''),
                custodyNo: (string) ($custody?->custody_no ?? ''),
                borrowerName: (string) ($overdue->borrower?->full_name ?? ''),
                division: $version?->division_code,
                officeUnit: (string) ($version?->office_unit ?? ''),
                caseType: 'Late Return',
                finding: 'Late Return',
                affectedItem: '',
                affectedQuantity: '',
                offenseSanction: $overdue->offense_level
                    ? trim('Offense '.$overdue->offense_level.($overdue->sanction_type ? ' - '.$this->titleCase((string) $overdue->sanction_type) : ''))
                    : $this->offenseSanction($violation),
                restrictionStatus: $restriction ? $this->titleCase((string) $restriction->status) : '',
                finalOutcome: $this->lateReturnOutcomeLabel((string) $overdue->status),
                dateReported: $overdue->actual_return_date ?? $overdue->overdue_started_at,
                findingCode: 'LATE_RETURN',
                statusCode: (string) $overdue->status,
                caseTypeCode: 'LATE_RETURN',
            ));
        }

        $filtered = $rows
            ->when(
                $division !== null,
                fn (Collection $rows): Collection => $rows->filter(fn (array $row): bool => $row['_division_code'] === $division)
            )
            ->when(
                $unit !== null,
                fn (Collection $rows): Collection => $rows->filter(fn (array $row): bool => strcasecmp($row['office_unit'], (string) $unit) === 0)
            )
            ->when(
                $finding !== null,
                fn (Collection $rows): Collection => $rows->filter(fn (array $row): bool => $finding === 'LATE_RETURN'
                    ? $row['_case_type_code'] === 'LATE_RETURN'
                    : $row['_finding_code'] === $finding)
            )
            ->when(
                $status !== null,
                fn (Collection $rows): Collection => $rows->filter(fn (array $row): bool => $row['_status_code'] === $status)
            )
            ->sortByDesc('_date_reported_raw')
            ->values()
            ->map(fn (array $row): array => collect($row)->except([
                '_division_code',
                '_finding_code',
                '_status_code',
                '_case_type_code',
                '_date_reported_raw',
            ])->all());

        return new ReportDataset(
            reportKey: 'accountability-cases',
            label: ReportCatalogue::definition('accountability-cases')['label'],
            columns: [
                ['key' => 'case_reference', 'label' => 'Case / Reference No.'],
                ['key' => 'custody_no', 'label' => 'Custody No.'],
                ['key' => 'borrower', 'label' => 'Borrower'],
                ['key' => 'division', 'label' => 'Organizational Classification'],
                ['key' => 'office_unit', 'label' => 'Office / College / Unit'],
                ['key' => 'case_type', 'label' => 'Case Type', 'badge' => true],
                ['key' => 'finding', 'label' => 'Finding'],
                ['key' => 'affected_item', 'label' => 'Affected Item'],
                ['key' => 'affected_quantity', 'label' => 'Qty', 'align' => 'numeric'],
                ['key' => 'offense_sanction', 'label' => 'Offense / Sanction'],
                ['key' => 'restriction_status', 'label' => 'Restriction Status', 'badge' => true],
                ['key' => 'final_outcome', 'label' => 'Accountability Status', 'badge' => true],
                ['key' => 'date_reported', 'label' => 'Date Reported'],
            ],
            rows: $filtered,
            summary: [
                'Cases' => $filtered->count(),
                'Property accountability' => $filtered->where('case_type', 'Property Accountability')->count(),
                'Late return' => $filtered->where('case_type', 'Late Return')->count(),
                'With active restriction' => $filtered->where('restriction_status', 'Active')->count(),
            ],
        );
    }

    /** @return array<string, mixed> */
    private function row(
        string $caseReference,
        string $custodyNo,
        string $borrowerName,
        ?string $division,
        string $officeUnit,
        string $caseType,
        string $finding,
        string $affectedItem,
        string $affectedQuantity,
        string $offenseSanction,
        string $restrictionStatus,
        string $finalOutcome,
        mixed $dateReported,
        string $findingCode,
        string $statusCode,
        string $caseTypeCode,
    ): array {
        return [
            '_division_code' => (string) ($division ?? ''),
            '_finding_code' => $findingCode,
            '_status_code' => $statusCode,
            '_case_type_code' => $caseTypeCode,
            '_date_reported_raw' => $dateReported,

            'case_reference' => $caseReference,
            'custody_no' => $custodyNo,
            'borrower' => $borrowerName,
            'division' => $division ? OrganizationalStructure::label($division) : '',
            'office_unit' => $officeUnit,
            'case_type' => $caseType,
            'finding' => $finding,
            'affected_item' => $affectedItem,
            'affected_quantity' => $affectedQuantity,
            'offense_sanction' => $offenseSanction,
            'restriction_status' => $restrictionStatus,
            'final_outcome' => $finalOutcome,
            'date_reported' => $this->date($dateReported),
        ];
    }

    private function offenseSanction(?BorrowerViolation $violation): string
    {
        if (! $violation) {
            return '';
        }

        $sanction = $violation->sanction;

        if (! $sanction) {
            return 'Offense on record';
        }

        return trim(
            (string) ($sanction->sanction_label ?: 'Sanction on record')
            .($sanction->offense_no ? ' (Offense '.$sanction->offense_no.')' : '')
        );
    }

    private function lateReturnOutcomeLabel(string $status): string
    {
        return match ($status) {
            'OVERDUE' => 'Overdue',
            'RETURNED_PENDING_SETTLEMENT' => 'Pending AO Confirmation',
            'FOR_HEAD_APPROVAL' => 'For Head/Admin Decision',
            'BILLED' => 'Awaiting Payment',
            'RESOLVED' => 'Resolved',
            default => $this->titleCase($status),
        };
    }

    private function titleCase(string $value): string
    {
        return str($value)->replace('_', ' ')->title()->toString();
    }

    private function date(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('d M Y') : '';
    }
}
