<?php

namespace App\Reports\Builders;

use App\Models\BorrowingRequest;
use App\Reports\OperationalStatus;
use App\Reports\ReportBuilder;
use App\Reports\ReportCatalogue;
use App\Reports\ReportDataset;
use App\Reports\ReportFilters;
use App\Support\OrganizationalStructure;
use Illuminate\Support\Collection;

/**
 * Borrowing Activity Report.
 *
 * Request-level activity for the period, reported under the authoritative
 * operational status (see OperationalStatus: custody wins once it exists).
 *
 * Borrower affiliation is read from the request version snapshot
 * (division_code / office_unit) captured when the request was filed, never
 * inferred from the borrower's current profile — a borrower who transfers
 * units must not retroactively move their past requests.
 */
class BorrowingActivityReport implements ReportBuilder
{
    public function build(ReportFilters $filters): ReportDataset
    {
        /*
         * Eager loading is what keeps this report off the N+1 path: the row
         * loop below touches borrower, currentVersion and custody for every
         * record.
         */
        $requests = BorrowingRequest::query()
            ->with(['borrower', 'currentVersion', 'custody'])
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->latest('created_at')
            ->get();

        $division = $filters->get('division');
        $unit = $filters->get('unit');
        $status = $filters->get('status');

        $rows = $requests
            ->map(function (BorrowingRequest $request): array {
                [$statusCode, $statusLabel] = OperationalStatus::forRequest($request);
                $version = $request->currentVersion;

                return [
                    '_status_code' => $statusCode,
                    '_division_code' => (string) ($version?->division_code ?? ''),
                    '_office_unit' => (string) ($version?->office_unit ?? ''),
                    '_link' => route('requests.show', $request),
                    '_tone_status' => self::statusTone($statusCode),

                    'request_no' => (string) $request->request_no,
                    'borrower' => (string) ($request->borrower?->full_name ?? ''),
                    'division' => $version?->division_code
                        ? OrganizationalStructure::label($version->division_code)
                        : '',
                    'office_unit' => (string) ($version?->office_unit ?? ''),
                    'purpose_event' => (string) ($version?->purpose_event ?? ''),
                    'schedule_date' => $this->date(
                        $version?->schedule_date ?: $version?->needed_from
                    ),
                    'return_date' => $this->date(
                        $version?->return_date ?: $version?->return_due_at
                    ),
                    'status' => OperationalStatus::label($statusCode, $statusLabel),
                    'created_at' => $this->dateTime($request->created_at),
                    'approved_at' => $this->dateTime($request->final_approved_at),
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
                $status !== null,
                fn (Collection $rows): Collection => $rows->filter(
                    fn (array $row): bool => $row['_status_code'] === $status
                )
            )
            ->values();

        return new ReportDataset(
            reportKey: 'borrowing',
            label: ReportCatalogue::definition('borrowing')['label'],
            columns: [
                ['key' => 'request_no', 'label' => 'Request No.'],
                ['key' => 'borrower', 'label' => 'Borrower'],
                ['key' => 'division', 'label' => 'Division'],
                ['key' => 'office_unit', 'label' => 'Office / Unit'],
                ['key' => 'purpose_event', 'label' => 'Event / Purpose'],
                ['key' => 'schedule_date', 'label' => 'Schedule Date'],
                ['key' => 'return_date', 'label' => 'Expected Return'],
                ['key' => 'status', 'label' => 'Status', 'badge' => true],
                ['key' => 'created_at', 'label' => 'Created'],
                ['key' => 'approved_at', 'label' => 'SPMU Approved'],
            ],
            rows: $rows,
            summary: $this->summarize($rows),
        );
    }

    /**
     * Counts derived from the same rows the table shows, so a summary figure
     * can never disagree with the records underneath it.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function summarize(Collection $rows): array
    {
        $counts = $rows->countBy(fn (array $row): string => (string) $row['_status_code']);

        $summary = [
            'Total requests' => $rows->count(),
            'Under SPMU Review' => (int) ($counts['UNDER_SPMU'] ?? 0),
            'Ready for Release' => (int) ($counts['APPROVED_READY_FOR_RELEASE'] ?? 0)
                + (int) ($counts['PREPARING_RELEASE'] ?? 0),
            'Pickup Scheduled' => (int) ($counts['PICKUP_SCHEDULED'] ?? 0),
            'Released / On Custody' => (int) ($counts['ACTIVE'] ?? 0),
            'Return Processing' => (int) ($counts['RETURN_PROCESSING'] ?? 0),
            'Overdue' => (int) ($counts['OVERDUE'] ?? 0),
            'Incident Open' => (int) ($counts['INCIDENT_OPEN'] ?? 0),
            'Obligation Open' => (int) ($counts['OBLIGATION_OPEN'] ?? 0),
            'Completed' => (int) ($counts['COMPLETED'] ?? 0),
            'Returned for Revision' => (int) ($counts['RETURNED_FOR_REVISION'] ?? 0),
            'Rejected' => (int) ($counts['REJECTED'] ?? 0),
            'Cancelled' => (int) ($counts['CANCELLED'] ?? 0),
            'Expired' => (int) ($counts['EXPIRED'] ?? 0),
            'Draft' => (int) ($counts['DRAFT'] ?? 0),
        ];

        return array_filter(
            $summary,
            fn (int $count, string $label): bool => $label === 'Total requests' || $count > 0,
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * Badge tone for a reported operational status.
     *
     * Tone is restraint only: every badge still reads as its own words,
     * so the report does not depend on colour to be understood.
     */
    public static function statusTone(string $status): string
    {
        return match ($status) {
            'COMPLETED', 'ACTIVE', 'APPROVED_READY_FOR_RELEASE' => 'positive',
            'OVERDUE', 'REJECTED', 'INCIDENT_OPEN' => 'critical',
            'RETURNED_FOR_REVISION', 'OBLIGATION_OPEN', 'RETURN_PROCESSING' => 'attention',
            'UNDER_SPMU', 'PREPARING_RELEASE', 'PICKUP_SCHEDULED' => 'progress',
            default => 'neutral',
        };
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
