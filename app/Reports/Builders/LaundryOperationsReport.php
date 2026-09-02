<?php

namespace App\Reports\Builders;

use App\Models\LaundryJob;
use App\Reports\ReportBuilder;
use App\Reports\ReportCatalogue;
use App\Reports\ReportDataset;
use App\Reports\ReportFilters;
use App\Support\OrganizationalStructure;
use Illuminate\Support\Collection;

/**
 * Laundry Operations Report.
 *
 * Reads laundry_jobs and laundry_job_lines, which are the authoritative
 * record of linen handling, and reports the status wording the Laundry
 * screens already use (LaundryJob::displayStatusLabel). No laundry business
 * rule is defined here; the report only states what the workflow recorded.
 *
 * The established process this report describes:
 *
 *   physical linen release
 *     -> Laundry personnel issued-by handling
 *     -> borrower custody
 *     -> return to the Laundry Area
 *     -> Laundry received-by and condition handling
 *     -> completion
 *     -> availability restored through the inventory workflow
 *
 * Completion is what returns linen to available stock, which is why a job
 * that has been received but not completed still shows outstanding quantity.
 */
class LaundryOperationsReport implements ReportBuilder
{
    public function build(ReportFilters $filters): ReportDataset
    {
        $from = $filters->from;
        $to = $filters->to;

        $jobs = LaundryJob::query()
            ->with([
                'custody.request.currentVersion',
                'custody.borrower',
                'lines.custodyLine.requestItem.inventoryItem',
                'formVerifier',
            ])
            ->where(function ($query) use ($from, $to): void {
                $query->whereBetween('created_at', [$from, $to])
                    ->orWhereBetween('worker_received_at', [$from, $to])
                    ->orWhereBetween('worker_completed_at', [$from, $to])
                    ->orWhereBetween('completed_at', [$from, $to]);
            })
            ->latest('created_at')
            ->get();

        $linen = $filters->get('linen');
        $laundryStatus = $filters->get('laundry_status');

        $rows = $jobs
            ->map(function (LaundryJob $job): array {
                $custody = $job->custody;
                $version = $custody?->request?->currentVersion;

                $issued = (float) $job->lines->sum(
                    fn ($line): float => (float) $line->issued_quantity
                );

                $received = (float) $job->lines->sum(
                    fn ($line): float => (float) $line->received_quantity
                );

                $completed = (float) $job->lines->sum(
                    fn ($line): float => (float) $line->completed_quantity
                );

                $items = $job->lines
                    ->map(fn ($line) => $line->custodyLine?->requestItem?->inventoryItem?->unique_description)
                    ->filter()
                    ->unique()
                    ->values();

                $itemIds = $job->lines
                    ->map(fn ($line) => $line->custodyLine?->requestItem?->inventory_item_id)
                    ->filter()
                    ->map(fn ($id): string => (string) $id)
                    ->unique()
                    ->values()
                    ->all();

                return [
                    '_status' => (string) $job->status,
                    '_item_ids' => $itemIds,
                    '_link' => $custody ? route('custody.show', $custody) : null,
                    '_tone_laundry_status' => match ((string) $job->status) {
                        'LAUNDRY_COMPLETED' => 'positive',
                        'TURNED_OVER_TO_LAUNDRY' => 'progress',
                        default => 'attention',
                    },

                    'custody_no' => (string) ($custody?->custody_no ?? ''),
                    'request_no' => (string) ($custody?->request?->request_no ?? ''),
                    'borrower' => (string) ($custody?->borrower?->full_name ?? ''),
                    'division' => $version?->division_code
                        ? OrganizationalStructure::label($version->division_code)
                        : '',
                    'office_unit' => (string) ($version?->office_unit ?? ''),
                    'linen_items' => $items->implode('; '),
                    'issued_at' => $this->dateTime($custody?->released_at),
                    'issued_quantity' => $this->number($issued),
                    'received_quantity' => $this->number($received),
                    'completed_quantity' => $this->number($completed),
                    'outstanding_quantity' => $this->number(max(0.0, $received - $completed)),
                    'laundry_status' => $job->displayStatusLabel(),
                    'worker_name' => (string) ($job->worker_name ?? ''),
                    'received_at' => $this->dateTime($job->worker_received_at),
                    'completed_at' => $this->dateTime(
                        $job->worker_completed_at ?: $job->completed_at
                    ),
                    'form_verified' => $job->hasVerifiedAccomplishedForm() ? 'Yes' : 'No',
                    'form_verified_by' => (string) ($job->formVerifier?->full_name ?? ''),
                    /*
                     * Linen returns to available stock at completion, not
                     * at physical receipt, so this column is deliberately
                     * blank until the job completes.
                     */
                    'available_again_at' => $job->status === 'LAUNDRY_COMPLETED'
                        ? $this->dateTime($job->worker_completed_at ?: $job->completed_at)
                        : '',
                ];
            })
            ->when(
                $linen !== null,
                fn (Collection $rows): Collection => $rows->filter(
                    fn (array $row): bool => in_array((string) $linen, $row['_item_ids'], true)
                )
            )
            ->when(
                $laundryStatus !== null,
                fn (Collection $rows): Collection => $rows->filter(
                    fn (array $row): bool => $row['_status'] === $laundryStatus
                )
            )
            ->values();

        return new ReportDataset(
            reportKey: 'laundry',
            label: ReportCatalogue::definition('laundry')['label'],
            columns: [
                ['key' => 'custody_no', 'label' => 'Custody No.'],
                ['key' => 'request_no', 'label' => 'Request No.'],
                ['key' => 'borrower', 'label' => 'Borrower'],
                ['key' => 'division', 'label' => 'Division'],
                ['key' => 'office_unit', 'label' => 'Office / Unit'],
                ['key' => 'linen_items', 'label' => 'Linen'],
                ['key' => 'issued_at', 'label' => 'Issue Date'],
                ['key' => 'issued_quantity', 'label' => 'Issued Qty', 'align' => 'numeric'],
                ['key' => 'received_quantity', 'label' => 'Received Qty', 'align' => 'numeric'],
                ['key' => 'completed_quantity', 'label' => 'Completed Qty', 'align' => 'numeric'],
                ['key' => 'outstanding_quantity', 'label' => 'Outstanding Qty', 'align' => 'numeric'],
                ['key' => 'laundry_status', 'label' => 'Laundry Status', 'badge' => true],
                ['key' => 'worker_name', 'label' => 'Laundry Personnel'],
                ['key' => 'received_at', 'label' => 'Received by Laundry'],
                ['key' => 'completed_at', 'label' => 'Completed'],
                ['key' => 'form_verified', 'label' => 'Form Verified'],
                ['key' => 'form_verified_by', 'label' => 'Verified By'],
                ['key' => 'available_again_at', 'label' => 'Available Again'],
            ],
            rows: $rows,
            summary: [
                'Laundry jobs' => $rows->count(),
                'Awaiting laundry return' => $rows->where('_status', 'FOR_LAUNDRY')->count(),
                'Internal laundry pending' => $rows->where('_status', 'TURNED_OVER_TO_LAUNDRY')->count(),
                'Completed' => $rows->where('_status', 'LAUNDRY_COMPLETED')->count(),
                'Outstanding linen' => (int) $rows->sum(
                    fn (array $row): float => (float) $row['outstanding_quantity']
                ),
            ],
        );
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }

    private function dateTime(mixed $value): string
    {
        return $value ? $value->format('d M Y, g:i A') : '';
    }
}
