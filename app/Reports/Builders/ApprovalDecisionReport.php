<?php

namespace App\Reports\Builders;

use App\Enums\ApprovalStage;
use App\Models\BorrowingRequest;
use App\Reports\OperationalStatus;
use App\Reports\ReportBuilder;
use App\Reports\ReportCatalogue;
use App\Reports\ReportDataset;
use App\Reports\ReportFilters;
use App\Support\OrganizationalStructure;
use Illuminate\Support\Collection;

/**
 * Approval & Decision Report.
 *
 * Approval history is read from approval_steps, which is the authoritative
 * record of who decided what and when. The request's current status column is
 * never used to infer a decision: a request returned for revision and then
 * resubmitted still carries its earlier decision in the steps, and only the
 * steps can show that.
 *
 * The two-step SPMU workflow, unchanged by this report:
 *
 *   sequence 1 — Action Officer verification. Created at submission only for
 *                off-campus requests, which are the ones carrying Gate Pass
 *                and off-campus eligibility checks.
 *   sequence 2 — SPMU Head review and decision. An on-campus request opens
 *                this step directly at submission and has no sequence-1 step,
 *                so its verification is reported as "Not required".
 */
class ApprovalDecisionReport implements ReportBuilder
{
    public function build(ReportFilters $filters): ReportDataset
    {
        $requests = BorrowingRequest::query()
            ->with([
                'borrower',
                'currentVersion.approvalSteps.approver',
            ])
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->latest('created_at')
            ->get();

        $division = $filters->get('division');
        $unit = $filters->get('unit');
        $verification = $filters->get('verification');
        $decision = $filters->get('decision');

        $rows = $requests
            ->map(function (BorrowingRequest $request): array {
                $version = $request->currentVersion;
                $steps = $version?->approvalSteps ?? collect();

                $verificationStep = $steps
                    ->where('sequence_no', 1)
                    ->first(fn ($step): bool => $step->stage_code === ApprovalStage::Spmu);

                $decisionStep = $steps
                    ->where('sequence_no', 2)
                    ->first(fn ($step): bool => $step->stage_code === ApprovalStage::Spmu);

                /*
                 * Only off-campus requests have a verification step at all.
                 * Reporting a missing step as "Pending" for an on-campus
                 * request would invent a queue that does not exist.
                 */
                $verificationState = match (true) {
                    $verificationStep === null => (bool) $version?->off_campus
                        ? 'PENDING'
                        : 'NOT_REQUIRED',
                    in_array($verificationStep->decision, ['PENDING', 'RECEIVED'], true) => 'PENDING',
                    default => (string) $verificationStep->decision,
                };

                $decisionState = match (true) {
                    $decisionStep === null => 'PENDING',
                    in_array($decisionStep->decision, ['PENDING', 'RECEIVED'], true) => 'PENDING',
                    default => (string) $decisionStep->decision,
                };

                $turnaround = $this->turnaroundSeconds($decisionStep);

                /*
                 * The final status is where the request actually stands
                 * now, which is not the same as the decision: an approved
                 * request may since have been released, returned or closed.
                 */
                [$finalStatusCode, $finalStatusLabel] = OperationalStatus::forRequest($request);

                return [
                    '_verification' => $verificationState,
                    '_decision' => $decisionState,
                    '_division_code' => (string) ($version?->division_code ?? ''),
                    '_office_unit' => (string) ($version?->office_unit ?? ''),
                    '_link' => route('requests.show', $request),
                    '_tone_verification' => match ($verificationState) {
                        'VERIFIED' => 'positive',
                        'RETURNED_FOR_REVISION' => 'attention',
                        'PENDING' => 'progress',
                        default => 'neutral',
                    },
                    '_tone_decision' => match ($decisionState) {
                        'APPROVED' => 'positive',
                        'REJECTED' => 'critical',
                        'RETURNED_FOR_REVISION' => 'attention',
                        default => 'progress',
                    },
                    '_tone_final_status' => BorrowingActivityReport::statusTone($finalStatusCode),

                    'request_no' => (string) $request->request_no,
                    'borrower' => (string) ($request->borrower?->full_name ?? ''),
                    'division' => $version?->division_code
                        ? OrganizationalStructure::label($version->division_code)
                        : '',
                    'office_unit' => (string) ($version?->office_unit ?? ''),
                    'version_no' => (string) ($version?->version_no ?? ''),
                    'submitted_at' => $this->dateTime($version?->submitted_at ?: $request->created_at),
                    'verification' => $this->verificationLabel($verificationState),
                    'verified_by' => (string) ($verificationStep?->approver?->full_name ?? ''),
                    'verified_at' => $this->dateTime($verificationStep?->decided_at),
                    'decision' => $this->decisionLabel($decisionState),
                    'decided_by' => (string) ($decisionStep?->approver?->full_name ?? ''),
                    'decided_at' => $this->dateTime($decisionStep?->decided_at),
                    'turnaround' => $turnaround === null ? '' : $this->duration($turnaround),
                    'final_status' => OperationalStatus::label($finalStatusCode, $finalStatusLabel),
                    'remarks' => (string) ($decisionStep?->remarks ?: $verificationStep?->remarks ?: ''),
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
                $verification !== null,
                fn (Collection $rows): Collection => $rows->filter(
                    fn (array $row): bool => $row['_verification'] === $verification
                )
            )
            ->when(
                $decision !== null,
                fn (Collection $rows): Collection => $rows->filter(
                    fn (array $row): bool => $row['_decision'] === $decision
                )
            )
            ->values();

        return new ReportDataset(
            reportKey: 'approval',
            label: ReportCatalogue::definition('approval')['label'],
            columns: [
                ['key' => 'request_no', 'label' => 'Request No.'],
                ['key' => 'borrower', 'label' => 'Borrower'],
                ['key' => 'division', 'label' => 'Division'],
                ['key' => 'office_unit', 'label' => 'Office / Unit'],
                ['key' => 'version_no', 'label' => 'Version', 'align' => 'numeric'],
                ['key' => 'submitted_at', 'label' => 'Submitted'],
                ['key' => 'verification', 'label' => 'AO Verification', 'badge' => true],
                ['key' => 'verified_by', 'label' => 'Verified By'],
                ['key' => 'verified_at', 'label' => 'Verified At'],
                ['key' => 'decision', 'label' => 'Admin Decision', 'badge' => true],
                ['key' => 'decided_by', 'label' => 'Decided By'],
                ['key' => 'decided_at', 'label' => 'Decided At'],
                ['key' => 'turnaround', 'label' => 'Review Turnaround'],
                ['key' => 'final_status', 'label' => 'Final Request Status', 'badge' => true],
                ['key' => 'remarks', 'label' => 'Decision Remarks'],
            ],
            rows: $rows,
            summary: [
                'Requests in period' => $rows->count(),
                'Awaiting decision' => $rows->where('_decision', 'PENDING')->count(),
                'Approved' => $rows->where('_decision', 'APPROVED')->count(),
                'Returned for correction' => $rows->where('_decision', 'RETURNED_FOR_REVISION')->count(),
                'Denied' => $rows->where('_decision', 'REJECTED')->count(),
                'Awaiting AO verification' => $rows->where('_verification', 'PENDING')->count(),
            ],
        );
    }

    /**
     * Received-to-decision time for a completed decision step.
     *
     * This is the same measurement the SPMU Review Turnaround report made, so
     * that report's detail survives inside this one.
     */
    private function turnaroundSeconds(mixed $step): ?int
    {
        if (! $step || ! $step->received_at || ! $step->decided_at) {
            return null;
        }

        return max(0, (int) $step->received_at->diffInSeconds($step->decided_at, false));
    }

    private function duration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0m';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return trim(($hours > 0 ? $hours.'h ' : '').$minutes.'m');
    }

    private function verificationLabel(string $state): string
    {
        return match ($state) {
            'NOT_REQUIRED' => 'Not required (on-campus)',
            'PENDING' => 'Awaiting verification',
            'VERIFIED' => 'Verified',
            'RETURNED_FOR_REVISION' => 'Returned for correction',
            default => str($state)->replace('_', ' ')->title()->toString(),
        };
    }

    private function decisionLabel(string $state): string
    {
        return match ($state) {
            'PENDING' => 'Awaiting decision',
            'APPROVED' => 'Approved',
            'RETURNED_FOR_REVISION' => 'Returned for correction',
            'REJECTED' => 'Denied',
            default => str($state)->replace('_', ' ')->title()->toString(),
        };
    }

    private function dateTime(mixed $value): string
    {
        return $value ? $value->format('d M Y, g:i A') : '';
    }
}
