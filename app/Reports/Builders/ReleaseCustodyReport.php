<?php

namespace App\Reports\Builders;

use App\Models\CustodyTransaction;
use App\Reports\ReportBuilder;
use App\Reports\ReportCatalogue;
use App\Reports\ReportDataset;
use App\Reports\ReportFilters;
use App\Support\OrganizationalStructure;
use Illuminate\Support\Collection;

/**
 * Release & Custody Report.
 *
 * Approval is not release. A borrowing request that the SPMU Head approved
 * but that has no custody transaction has not entered the physical release
 * process and never appears here; the report is built from custody records,
 * which are created when release preparation begins. Whether the items
 * actually left the store is a separate fact, carried by released_at and by
 * the custody status column.
 *
 * A transaction appears when it was created, physically released, or closed
 * inside the reporting period. Division and unit come from the originating
 * request version snapshot, so a custody record is attributed to the unit
 * that filed the request.
 */
class ReleaseCustodyReport implements ReportBuilder
{
    public function build(ReportFilters $filters): ReportDataset
    {
        /*
         * currentVersion and lines.requestItem are eager loaded because the
         * row loop reads the version snapshot for every record and the
         * equipment filter reads each line's inventory item.
         */
        $custodies = CustodyTransaction::query()
            ->with([
                'borrower',
                'request.currentVersion',
                'lines.requestItem',
                'releasedBy',
            ])
            ->where(function ($query) use ($filters): void {
                $query->whereBetween('created_at', [$filters->from, $filters->to])
                    ->orWhereBetween('released_at', [$filters->from, $filters->to])
                    ->orWhereBetween('closed_at', [$filters->from, $filters->to]);
            })
            ->latest('created_at')
            ->get();

        $division = $filters->get('division');
        $unit = $filters->get('unit');
        $custodyStatus = $filters->get('custody_status');
        $equipment = $filters->get('equipment');

        $rows = $custodies
            ->map(function (CustodyTransaction $custody): array {
                $version = $custody->request?->currentVersion;

                $status = $custody->closed_at !== null || $custody->status === 'CLOSED'
                    ? 'CLOSED'
                    : (string) $custody->status;

                return [
                    '_status' => $status,
                    '_division_code' => (string) ($version?->division_code ?? ''),
                    '_office_unit' => (string) ($version?->office_unit ?? ''),
                    '_item_ids' => $custody->lines
                        ->map(fn ($line) => $line->requestItem?->inventory_item_id)
                        ->filter()
                        ->map(fn ($id): string => (string) $id)
                        ->unique()
                        ->values()
                        ->all(),
                    '_link' => route('custody.show', $custody),
                    '_tone_status' => match ($status) {
                        'CLOSED', 'ACTIVE' => 'positive',
                        'OVERDUE', 'INCIDENT_OPEN' => 'critical',
                        'RETURN_PROCESSING', 'PARTIALLY_RETURNED', 'OBLIGATION_OPEN' => 'attention',
                        default => 'progress',
                    },

                    'custody_no' => (string) $custody->custody_no,
                    'request_no' => (string) ($custody->request?->request_no ?? ''),
                    'borrower' => (string) ($custody->borrower?->full_name ?? ''),
                    'division' => $version?->division_code
                        ? OrganizationalStructure::label($version->division_code)
                        : '',
                    'office_unit' => (string) ($version?->office_unit ?? ''),
                    'equipment' => $custody->lines
                        ->map(fn ($line) => $line->requestItem?->description_snapshot)
                        ->filter()
                        ->unique()
                        ->implode('; '),
                    'prepared_at' => $this->dateTime($custody->prepared_at),
                    'scheduled_release_at' => $this->dateTime($custody->scheduled_release_at),
                    'released_at' => $this->dateTime($custody->released_at),
                    'released_by' => (string) ($custody->releasedBy?->full_name ?? ''),
                    'released_quantity' => $this->number(
                        (float) $custody->lines->sum(
                            fn ($line): float => (float) $line->actual_released_quantity
                        )
                    ),
                    'outstanding_quantity' => $this->number(
                        max(0.0, (float) $custody->lines->sum(
                            fn ($line): float => (float) $line->actual_released_quantity
                                - (float) $line->returned_quantity
                        ))
                    ),
                    'due_at' => $this->date(
                        $version?->return_date ?: ($version?->return_due_at ?: $custody->due_at)
                    ),
                    'closed_at' => $this->dateTime($custody->closed_at),
                    'status' => $this->label($status),
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
                $custodyStatus !== null,
                fn (Collection $rows): Collection => $rows->filter(
                    fn (array $row): bool => $row['_status'] === $custodyStatus
                )
            )
            ->when(
                $equipment !== null,
                fn (Collection $rows): Collection => $rows->filter(
                    fn (array $row): bool => in_array((string) $equipment, $row['_item_ids'], true)
                )
            )
            ->values();

        return new ReportDataset(
            reportKey: 'custody',
            label: ReportCatalogue::definition('custody')['label'],
            columns: [
                ['key' => 'custody_no', 'label' => 'Custody No.'],
                ['key' => 'request_no', 'label' => 'Request No.'],
                ['key' => 'borrower', 'label' => 'Borrower'],
                ['key' => 'division', 'label' => 'Division'],
                ['key' => 'office_unit', 'label' => 'Office / Unit'],
                ['key' => 'equipment', 'label' => 'Equipment'],
                ['key' => 'prepared_at', 'label' => 'Preparation Confirmed'],
                ['key' => 'scheduled_release_at', 'label' => 'Pickup Scheduled'],
                ['key' => 'released_at', 'label' => 'Physically Released'],
                ['key' => 'released_by', 'label' => 'Released By'],
                ['key' => 'released_quantity', 'label' => 'Released Qty', 'align' => 'numeric'],
                ['key' => 'outstanding_quantity', 'label' => 'Outstanding Qty', 'align' => 'numeric'],
                ['key' => 'due_at', 'label' => 'Expected Return'],
                ['key' => 'closed_at', 'label' => 'Closed'],
                ['key' => 'status', 'label' => 'Custody Status', 'badge' => true],
            ],
            rows: $rows,
            summary: [
                'Custody records' => $rows->count(),
                'Physically released' => $rows->filter(
                    fn (array $row): bool => $row['released_at'] !== ''
                )->count(),
                'Closed' => $rows->filter(
                    fn (array $row): bool => $row['_status'] === 'CLOSED'
                )->count(),
                'Still active / processing' => $rows->filter(
                    fn (array $row): bool => $row['_status'] !== 'CLOSED'
                )->count(),
            ],
        );
    }

    private function label(string $status): string
    {
        return match ($status) {
            'PREPARING_RELEASE' => 'Preparing Release',
            'ACTIVE' => 'Released / On Custody',
            'RETURN_PROCESSING' => 'Return Processing',
            'PARTIALLY_RETURNED' => 'Partially Returned',
            'OVERDUE' => 'Overdue',
            'INCIDENT_OPEN' => 'Incident Open',
            'OBLIGATION_OPEN' => 'Obligation Open',
            'CLOSED' => 'Completed',
            default => str($status)->replace('_', ' ')->title()->toString(),
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
