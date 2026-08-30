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

        $requests = BorrowingRequest::query()
            ->with([
                'borrower.organizationalUnit',
                'currentVersion.items.inventoryItem.unit',
                'currentVersion.supportingDocuments.file',
                'currentVersion.approvalSteps.approver',
            ])
            ->where('status', RequestStatus::UnderSpmu)
            ->whereHas('currentVersion.approvalSteps', function ($step): void {
                $step->where('stage_code', 'SPMU')
                    ->where('sequence_no', 1)
                    ->whereIn('decision', ['PENDING', 'RECEIVED']);
            })
            /*
             * First-ready, first-reviewed queue.
             *
             * Priority follows the latest request version's submission time,
             * not the original request creation time. If a request was returned
             * for revision, its new resubmission timestamp becomes its queue
             * position when it comes back for SPMU review.
             */
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

        return view('approvals.index', [
            'stage' => 'SPMU',
            'mode' => 'HEAD_DECISION',
            'requests' => $requests,
            'canVerify' => false,
            'canDecide' => true,
        ]);
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
            'APPROVED' => 'Request E-signed, verified, and approved by the authorized SPMU signatory. The approved quantity is allocated/held for pickup, and the Action Officer may now schedule pickup and process release.',
            'RETURNED_FOR_REVISION' => 'Request returned for revision. No inventory allocation was created.',
            default => 'Request rejected. No inventory allocation was created.',
        };

        return redirect()->route('approvals.index')->with('status', $message);
    }
}
