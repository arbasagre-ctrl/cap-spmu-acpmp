<?php

namespace App\Reports\Builders;

use App\Enums\ApprovalStage;
use App\Models\BorrowingRequest;
use App\Reports\ReportBuilder;
use App\Reports\ReportCatalogue;
use App\Reports\ReportDataset;
use App\Reports\ReportFilters;
use App\Support\OrganizationalStructure;
use Illuminate\Support\Collection;

/**
 * Off-Campus / Gate Pass Report.
 *
 * States the existing off-campus workflow; it does not alter it. The routing
 * being reported is:
 *
 *   borrower submission
 *     -> Action Officer verification (approval step sequence 1, created at
 *        submission only for off-campus requests)
 *     -> SPMU Head review and decision (sequence 2)
 *     -> on approval, the Gate Pass and the other required documents become
 *        available
 *     -> physical release follows the normal custody workflow
 *
 * A Permission to Conduct letter is additionally required only where the
 * Student Activity rule applies, which is the request version's
 * represents_student_activity flag. The report reads that flag; it never
 * decides on its own that a PTC was needed.
 *
 * Gate pass state comes from the gate_passes record (PENDING,
 * READY_FOR_PRINTING, VERIFIED). A request with no gate pass row has not
 * reached the point where one is issued, and is reported as such.
 */
class OffCampusGatePassReport implements ReportBuilder
{
    public function build(ReportFilters $filters): ReportDataset
    {
        $requests = BorrowingRequest::query()
            ->with([
                'borrower',
                'currentVersion.approvalSteps',
                'currentVersion.supportingDocuments',
                'custody.gatePass',
            ])
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->latest('created_at')
            ->get();

        $division = $filters->get('division');
        $unit = $filters->get('unit');
        $gatePassStatus = $filters->get('gate_pass_status');
        $studentActivity = $filters->get('student_activity');
        $offCampus = $filters->get('off_campus');

        $rows = $requests
            ->map(function (BorrowingRequest $request): array {
                $version = $request->currentVersion;
                $custody = $request->custody;
                $gatePass = $custody?->gatePass;
                $steps = $version?->approvalSteps ?? collect();

                $isOffCampus = (bool) $version?->off_campus;
                $isStudentActivity = (bool) $version?->represents_student_activity;

                $verificationStep = $steps
                    ->where('sequence_no', 1)
                    ->first(fn ($step): bool => $step->stage_code === ApprovalStage::Spmu);

                $decisionStep = $steps
                    ->where('sequence_no', 2)
                    ->first(fn ($step): bool => $step->stage_code === ApprovalStage::Spmu);

                $verification = match (true) {
                    ! $isOffCampus => 'Not required (on-campus)',
                    $verificationStep === null => 'Awaiting verification',
                    in_array($verificationStep->decision, ['PENDING', 'RECEIVED'], true) => 'Awaiting verification',
                    $verificationStep->decision === 'VERIFIED' => 'Verified',
                    $verificationStep->decision === 'RETURNED_FOR_REVISION' => 'Returned for correction',
                    default => (string) $verificationStep->decision,
                };

                $decision = match (true) {
                    $decisionStep === null => 'Awaiting decision',
                    in_array($decisionStep->decision, ['PENDING', 'RECEIVED'], true) => 'Awaiting decision',
                    $decisionStep->decision === 'APPROVED' => 'Approved',
                    $decisionStep->decision === 'RETURNED_FOR_REVISION' => 'Returned for correction',
                    $decisionStep->decision === 'REJECTED' => 'Denied',
                    default => (string) $decisionStep->decision,
                };

                $gatePassState = $gatePass?->status ?? 'NOT_ISSUED';

                /*
                 * The PTC is only expected when the Student Activity rule
                 * applies. Anything else would report a missing document that
                 * the workflow never asked for.
                 */
                $currentDocuments = $version?->supportingDocuments?->where('is_current', true);

                $requestLetter = $currentDocuments?->firstWhere(
                    'document_type',
                    \App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER
                );

                $ptcDocument = $isStudentActivity
                    ? $version?->supportingDocuments
                        ?->where('is_current', true)
                        ->firstWhere(
                            'document_type',
                            \App\Models\RequestSupportingDocument::TYPE_PERMISSION_TO_CONDUCT
                        )
                    : null;

                return [
                    '_off_campus' => $isOffCampus,
                    '_student_activity' => $isStudentActivity,
                    '_gate_pass_status' => $gatePassState,
                    '_division_code' => (string) ($version?->division_code ?? ''),
                    '_office_unit' => (string) ($version?->office_unit ?? ''),
                    '_link' => route('requests.show', $request),
                    '_tone_gate_pass_status' => match ($gatePassState) {
                        'VERIFIED' => 'positive',
                        'READY_FOR_PRINTING' => 'progress',
                        'NOT_ISSUED' => 'neutral',
                        default => 'attention',
                    },

                    'request_no' => (string) $request->request_no,
                    'custody_no' => (string) ($custody?->custody_no ?? ''),
                    'borrower' => (string) ($request->borrower?->full_name ?? ''),
                    'division' => $version?->division_code
                        ? OrganizationalStructure::label($version->division_code)
                        : '',
                    'office_unit' => (string) ($version?->office_unit ?? ''),
                    'purpose_event' => (string) ($version?->purpose_event ?? ''),
                    'off_campus' => $isOffCampus ? 'Yes' : 'No',
                    'location' => (string) ($version?->location ?? ''),
                    'request_letter' => $requestLetter ? 'On file' : 'Not on file',
                    'student_activity' => $isStudentActivity ? 'Yes' : 'No',
                    'ptc_required' => $isStudentActivity ? 'Yes' : 'No',
                    'ptc_on_file' => $isStudentActivity ? ($ptcDocument ? 'Yes' : 'No') : 'Not required',
                    'verification' => $verification,
                    'decision' => $decision,
                    'gate_pass_status' => $this->gatePassLabel($gatePassState),
                    'gate_pass_verified_at' => $this->dateTime($gatePass?->verified_at),
                    'released_at' => $this->dateTime($custody?->released_at),
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
                $gatePassStatus !== null,
                fn (Collection $rows): Collection => $rows->filter(
                    fn (array $row): bool => $row['_gate_pass_status'] === $gatePassStatus
                )
            )
            ->when(
                $studentActivity !== null,
                fn (Collection $rows): Collection => $rows->filter(
                    fn (array $row): bool => $row['_student_activity'] === ($studentActivity === 'YES')
                )
            )
            ->when(
                $offCampus !== null,
                fn (Collection $rows): Collection => $rows->filter(
                    fn (array $row): bool => $row['_off_campus'] === ($offCampus === 'YES')
                )
            )
            ->values();

        return new ReportDataset(
            reportKey: 'gate-pass',
            label: ReportCatalogue::definition('gate-pass')['label'],
            columns: [
                ['key' => 'request_no', 'label' => 'Request No.'],
                ['key' => 'custody_no', 'label' => 'Custody No.'],
                ['key' => 'borrower', 'label' => 'Borrower'],
                ['key' => 'division', 'label' => 'Division'],
                ['key' => 'office_unit', 'label' => 'Office / Unit'],
                ['key' => 'purpose_event', 'label' => 'Activity / Purpose'],
                ['key' => 'off_campus', 'label' => 'Off-Campus'],
                ['key' => 'location', 'label' => 'Destination / Location'],
                ['key' => 'request_letter', 'label' => 'Borrowing Request Letter'],
                ['key' => 'student_activity', 'label' => 'Student Activity'],
                ['key' => 'ptc_required', 'label' => 'PTC Required'],
                ['key' => 'ptc_on_file', 'label' => 'PTC Submitted'],
                ['key' => 'verification', 'label' => 'AO Verification'],
                ['key' => 'decision', 'label' => 'Admin Decision'],
                ['key' => 'gate_pass_status', 'label' => 'Gate Pass Status', 'badge' => true],
                ['key' => 'gate_pass_verified_at', 'label' => 'Gate Pass Verified'],
                ['key' => 'released_at', 'label' => 'Physically Released'],
            ],
            rows: $rows,
            summary: [
                'Requests in period' => $rows->count(),
                'Off-campus requests' => $rows->where('_off_campus', true)->count(),
                'Student activities' => $rows->where('_student_activity', true)->count(),
                'Gate pass issued' => $rows->filter(
                    fn (array $row): bool => $row['_gate_pass_status'] !== 'NOT_ISSUED'
                )->count(),
                'Gate pass verified' => $rows->where('_gate_pass_status', 'VERIFIED')->count(),
            ],
        );
    }

    private function gatePassLabel(string $status): string
    {
        return match ($status) {
            'NOT_ISSUED' => 'Not issued',
            'PENDING' => 'Pending',
            'READY_FOR_PRINTING' => 'Ready for printing',
            'VERIFIED' => 'Verified',
            default => str($status)->replace('_', ' ')->title()->toString(),
        };
    }

    private function dateTime(mixed $value): string
    {
        return $value ? $value->format('d M Y, g:i A') : '';
    }
}
