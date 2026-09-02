<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use App\Models\RequestVersion;
use App\Services\RequestWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ApprovalController extends Controller
{
    public function verificationIndex(Request $request): View
    {
        abort_unless(
            $request->user()->access_classification === AccessClassification::SpmuOfficer,
            403,
            'Only the SPMU Action Officer may access the verification queue.'
        );

        return view('approvals.index', [
            'stage' => 'SPMU',
            'mode' => 'ACTION_OFFICER_VERIFICATION',
            'requests' => $this->pendingRequestsForSequence(1),
            'canVerify' => true,
            'canDecide' => false,
        ]);
    }

    public function index(Request $request): View
    {
        abort_unless($request->user()->primaryWorkspace() === 'SPMU', 403);

        $isHead = $request->user()->access_classification === AccessClassification::SpmuHead;
        $isDelegatedOfficer = $request->user()->access_classification === AccessClassification::SpmuOfficer
            && $request->user()->activeDelegationFor('SPMU') !== null;

        abort_unless(
            $isHead || $isDelegatedOfficer,
            403,
            'Only the SPMU Head or a formally delegated Action Officer may access the approval queue.'
        );

        return view('approvals.index', [
            'stage' => 'SPMU',
            'mode' => 'HEAD_DECISION',
            'requests' => $this->pendingRequestsForSequence(2),
            'canVerify' => false,
            'canDecide' => true,
        ]);
    }

    public function verify(
        Request $request,
        BorrowingRequest $borrowingRequest,
        RequestWorkflowService $workflow
    ): RedirectResponse {
        abort_unless(
            $request->user()->access_classification === AccessClassification::SpmuOfficer,
            403,
            'Only the SPMU Action Officer may verify a submitted request.'
        );

        $rules = [
            'decision' => ['required', Rule::in(['VERIFIED', 'RETURNED_FOR_REVISION'])],
            'remarks' => [
                Rule::requiredIf(
                    fn (): bool => strtoupper((string) $request->input('decision'))
                        === 'RETURNED_FOR_REVISION'
                ),
                'nullable',
                'string',
                'max:2000',
            ],
        ];

        if (strtoupper((string) $request->input('decision')) === 'VERIFIED') {
            $rules = array_merge($rules, [
                'details_complete' => ['required', 'accepted'],
                'documents_complete' => ['required', 'accepted'],
                'availability_verified' => ['required', 'accepted'],
                'confirm_e_signature' => ['required', 'accepted'],
            ]);
        }

        $data = $request->validate($rules, [
            'details_complete.accepted' => 'Confirm that the request details match the signed letter.',
            'documents_complete.accepted' => 'Confirm that the required supporting documents are complete.',
            'availability_verified.accepted' => 'Confirm that the request and requested inventory were verified.',
            'confirm_e_signature.accepted' => 'Confirm that you want to apply your registered E-signature to this verification.',
            'remarks.required' => 'Correction instructions are required when returning the request.',
        ]);

        $workflow->verifyByActionOfficer(
            $borrowingRequest,
            $request->user(),
            $data['decision'],
            $data['remarks'] ?? null,
            $request->boolean('confirm_e_signature')
        );

        return redirect()->route('verifications.index')->with(
            'status',
            $data['decision'] === 'VERIFIED'
                ? 'Request marked VERIFIED and routed to the SPMU Head for a separate final decision. Verification did not approve or reserve inventory.'
                : 'Incomplete request returned to the borrower for correction. No inventory reservation was created.'
        );
    }

    public function decide(
        Request $request,
        BorrowingRequest $borrowingRequest,
        RequestWorkflowService $workflow
    ): RedirectResponse {
        $isHead = $request->user()->access_classification === AccessClassification::SpmuHead;
        $isDelegatedOfficer = $request->user()->access_classification === AccessClassification::SpmuOfficer
            && $request->user()->activeDelegationFor('SPMU') !== null;

        abort_unless(
            $isHead || $isDelegatedOfficer,
            403,
            'Only the SPMU Head or a formally delegated Action Officer may record the SPMU decision.'
        );

        $rules = [
            'decision' => [
                'required',
                Rule::in(['APPROVED', 'REJECTED', 'RETURNED_FOR_REVISION']),
            ],
            'remarks' => [
                Rule::requiredIf(
                    fn (): bool => in_array(
                        strtoupper((string) $request->input('decision')),
                        ['REJECTED', 'RETURNED_FOR_REVISION'],
                        true
                    )
                ),
                'nullable',
                'string',
                'max:2000',
            ],
        ];

        if (strtoupper((string) $request->input('decision')) === 'APPROVED') {
            $rules = array_merge($rules, [
                'details_complete' => ['required', 'accepted'],
                'documents_complete' => ['required', 'accepted'],
                'availability_verified' => ['required', 'accepted'],
                'confirm_e_signature' => ['required', 'accepted'],
            ]);
        }

        $data = $request->validate($rules, [
            'details_complete.accepted' => 'Confirm that the request details match the signed letter.',
            'documents_complete.accepted' => 'Confirm that the required signatures and documents are complete.',
            'availability_verified.accepted' => 'Confirm that inventory availability is verified.',
            'confirm_e_signature.accepted' => 'Confirm that you want to apply your registered E-signature to this approval.',
            'remarks.required' => 'Remarks are required when returning or rejecting the request.',
        ]);

        $workflow->decide(
            $borrowingRequest,
            $request->user(),
            $data['decision'],
            $data['remarks'] ?? null,
            $request->boolean('confirm_e_signature')
        );

        $message = match ($data['decision']) {
            'APPROVED' => 'Verified request E-signed and approved by the authorized SPMU Head signatory. The approved quantity is reserved, the Borrower Slip and applicable Gate Pass were generated, and the Action Officer may proceed with pickup preparation.',
            'RETURNED_FOR_REVISION' => 'Request returned for revision. No inventory allocation was created.',
            default => 'Request rejected. No inventory allocation was created.',
        };

        return redirect()->route('approvals.index')->with('status', $message);
    }

    private function pendingRequestsForSequence(int $sequence): \Illuminate\Support\Collection
    {
        return BorrowingRequest::query()
            ->with([
                'borrower.organizationalUnit',
                'currentVersion.items.inventoryItem.unit',
                'currentVersion.supportingDocuments.file',
                'currentVersion.approvalSteps.approver',
            ])
            ->where('status', RequestStatus::UnderSpmu)
            ->whereHas('currentVersion.approvalSteps', function ($step) use ($sequence): void {
                $step->where('stage_code', 'SPMU')
                    ->where('sequence_no', $sequence)
                    ->whereIn('decision', ['PENDING', 'RECEIVED']);
            })
            ->orderBy(
                RequestVersion::query()
                    ->selectRaw('COALESCE(submitted_at, updated_at)')
                    ->whereColumn('request_versions.request_id', 'borrowing_requests.id')
                    ->orderByDesc('version_no')
                    ->limit(1),
                'asc'
            )
            ->orderBy('borrowing_requests.id')
            ->get();
    }
}
