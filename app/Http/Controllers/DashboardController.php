<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\InventoryItem;
use App\Models\LaundryJob;
use App\Models\NotificationDelivery;
use App\Models\TemporaryDelegation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $user = $request->user()->load('roles');
        $workspace = strtoupper((string) $request->session()->get('active_workspace'));
        $allowed = $user->allowedWorkspaces();

        if (! in_array($workspace, $allowed, true)) {
            $workspace = $user->primaryWorkspace() ?? $allowed[0] ?? null;
            abort_unless($workspace, 403, 'No system role is assigned to this account.');
            $request->session()->put('active_workspace', $workspace);
        }

        $classification = $user->access_classification;
        $statistics = [];
        $queue = collect();
        $nextCustodies = collect();
        $latestRequest = null;
        $dashboardMode = $workspace;

        if ($workspace === 'BORROWER') {
            $dashboardMode = 'BORROWER';

            $openRequests = BorrowingRequest::query()
                ->where('borrower_user_id', $user->id)
                ->whereNotIn('status', [
                    RequestStatus::Cancelled,
                    RequestStatus::Rejected,
                    RequestStatus::Expired,
                ])
                ->whereDoesntHave('custody', fn ($query) => $query->whereNotNull('released_at'))
                ->count();

            $activeBorrowings = CustodyTransaction::query()
                ->where('borrower_user_id', $user->id)
                ->where('status', '!=', 'CLOSED')
                ->whereNotNull('released_at')
                ->count();

            $dueForReturn = CustodyTransaction::query()
                ->where('borrower_user_id', $user->id)
                ->where('status', '!=', 'CLOSED')
                ->whereNotNull('released_at')
                ->whereNotNull('due_at')
                ->where('due_at', '<=', now()->addDay()->endOfDay())
                ->count();

            $requestActions = BorrowingRequest::query()
                ->where('borrower_user_id', $user->id)
                ->whereIn('status', [RequestStatus::Draft, RequestStatus::ReturnedForRevision])
                ->count();

            $pickupActions = CustodyTransaction::query()
                ->where('borrower_user_id', $user->id)
                ->whereNull('released_at')
                ->whereNotNull('scheduled_release_at')
                ->whereNull('pickup_expired_at')
                ->count();

            $laundryActions = LaundryJob::query()
                ->whereHas('custody', fn ($query) => $query->where('borrower_user_id', $user->id))
                ->where('status', 'FOR_LAUNDRY')
                ->count();

            $statistics = [
                'Open Requests' => $openRequests,
                'Active Borrowings' => $activeBorrowings,
                'Due for Return' => $dueForReturn,
                'Needs My Action' => $requestActions + $pickupActions + $laundryActions,
            ];

            $liveBorrowerRequest = static fn ($query) => $query
                ->whereNotIn('status', [
                    RequestStatus::Cancelled->value,
                    RequestStatus::Rejected->value,
                    RequestStatus::Expired->value,
                ])
                ->where(function ($query) {
                    $query->whereDoesntHave('custody')
                        ->orWhereHas('custody', fn ($custody) => $custody->where('status', '!=', 'CLOSED'));
                });

            $queue = BorrowingRequest::query()
                ->with(['currentVersion', 'custody.laundryJob'])
                ->where('borrower_user_id', $user->id)
                ->where($liveBorrowerRequest)
                ->latest()
                ->limit(6)
                ->get();

            // The dashboard tracker is intentionally live-only. Completed,
            // cancelled, rejected, and expired requests remain available in
            // My Requests for history, but they no longer occupy the dashboard.
            $latestRequest = BorrowingRequest::query()
                ->with([
                    'currentVersion.approvalSteps',
                    'statusHistory',
                    'custody.returns',
                    'custody.laundryJob',
                ])
                ->where('borrower_user_id', $user->id)
                ->where($liveBorrowerRequest)
                ->latest()
                ->first();

            $nextCustodies = CustodyTransaction::query()
                ->with(['request'])
                ->where('borrower_user_id', $user->id)
                ->where('status', '!=', 'CLOSED')
                ->orderByRaw('CASE WHEN scheduled_release_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('scheduled_release_at')
                ->orderBy('due_at')
                ->limit(5)
                ->get();
        } elseif ($workspace === 'SPMU' && $classification === AccessClassification::SpmuOfficer) {
            $dashboardMode = 'SPMU_OFFICER';

            $forPickupScheduling = CustodyTransaction::query()
                ->where('status', 'PREPARING_RELEASE')
                ->whereNull('scheduled_release_at')
                ->count();

            $readyForRelease = CustodyTransaction::query()
                ->where('status', 'PREPARING_RELEASE')
                ->whereNotNull('scheduled_release_at')
                ->whereNull('pickup_expired_at')
                ->count();

            $forReturnCheck = CustodyTransaction::query()
                ->whereIn('status', ['ACTIVE', 'RETURN_PROCESSING', 'OVERDUE'])
                ->count();

            $laundryVerification = LaundryJob::query()
                ->where('status', 'READY_FOR_SPMU_RETURN')
                ->count();

            $statistics = [
                'For Pickup Scheduling' => $forPickupScheduling,
                'Ready for Release' => $readyForRelease,
                'For Return Check' => $forReturnCheck,
                'Laundry Final Acceptance' => $laundryVerification,
            ];

            $queue = CustodyTransaction::query()
                ->with(['borrower', 'request.currentVersion'])
                ->where('status', 'PREPARING_RELEASE')
                ->orderByRaw('CASE WHEN scheduled_release_at IS NULL THEN 0 ELSE 1 END')
                ->orderBy('scheduled_release_at')
                ->limit(6)
                ->get();

            $nextCustodies = CustodyTransaction::query()
                ->with(['borrower', 'request'])
                ->whereNotIn('status', ['CLOSED'])
                ->orderBy('due_at')
                ->limit(5)
                ->get();
        } elseif ($workspace === 'SPMU' && $classification === AccessClassification::SpmuHead) {
            $dashboardMode = 'SPMU_HEAD';

            $forApproval = BorrowingRequest::query()
                ->where('status', RequestStatus::UnderSpmu)
                ->whereHas('currentVersion.approvalSteps', fn ($step) => $step
                    ->where('stage_code', 'SPMU')
                    ->where('sequence_no', 1)
                    ->whereIn('decision', ['PENDING', 'RECEIVED']))
                ->count();

            $approvedToday = BorrowingRequest::query()
                ->whereDate('final_approved_at', today())
                ->count();

            $activeBorrowings = CustodyTransaction::query()
                ->whereNotIn('status', ['CLOSED', 'PREPARING_RELEASE'])
                ->count();

            $issues = CustodyTransaction::query()
                ->whereIn('status', ['OVERDUE', 'INCIDENT_OPEN', 'OBLIGATION_OPEN', 'RETURN_PROCESSING'])
                ->count();

            $statistics = [
                'For Approval' => $forApproval,
                'Approved Today' => $approvedToday,
                'Active Borrowings' => $activeBorrowings,
                'Overdue / Issues' => $issues,
            ];

            $queue = BorrowingRequest::query()
                ->with(['borrower', 'currentVersion'])
                ->where('status', RequestStatus::UnderSpmu)
                ->whereHas('currentVersion.approvalSteps', fn ($step) => $step
                    ->where('stage_code', 'SPMU')
                    ->where('sequence_no', 1)
                    ->whereIn('decision', ['PENDING', 'RECEIVED']))
                ->oldest()
                ->limit(6)
                ->get();

            $nextCustodies = CustodyTransaction::query()
                ->with(['borrower', 'request'])
                ->whereNotIn('status', ['CLOSED'])
                ->orderBy('due_at')
                ->limit(5)
                ->get();
        } elseif ($workspace === 'LAUNDRY') {
            $dashboardMode = 'LAUNDRY';

            $statistics = [
                'Awaiting Drop-off' => LaundryJob::query()->where('status', 'FOR_LAUNDRY')->count(),
                'In Process' => LaundryJob::query()->where('status', 'IN_PROCESS')->count(),
                'For SPMU Return' => LaundryJob::query()->where('status', 'READY_FOR_SPMU_RETURN')->count(),
                'Final Form Upload' => LaundryJob::query()->whereIn('status', ['AWAITING_FINAL_FORM_UPLOAD', 'FORM_REPLACEMENT_REQUIRED'])->count(),
            ];

            $queue = LaundryJob::query()
                ->with([
                    'custody.borrower',
                    'custody.request',
                    'lines.custodyLine.requestItem.inventoryItem.unit',
                ])
                ->whereIn('status', [
                    'FOR_LAUNDRY',
                    'IN_PROCESS',
                    'READY_FOR_SPMU_RETURN',
                    'AWAITING_FINAL_FORM_UPLOAD',
                    'FORM_REPLACEMENT_REQUIRED',
                ])
                ->orderByRaw("CASE
                    WHEN status = 'FOR_LAUNDRY' THEN 1
                    WHEN status = 'IN_PROCESS' THEN 2
                    WHEN status = 'READY_FOR_SPMU_RETURN' THEN 3
                    WHEN status IN ('AWAITING_FINAL_FORM_UPLOAD', 'FORM_REPLACEMENT_REQUIRED') THEN 4
                    ELSE 5 END")
                ->oldest('updated_at')
                ->limit(8)
                ->get();
        } elseif ($workspace === 'ICTU') {
            $dashboardMode = 'ICTU';

            $statistics = [
                'Active Accounts' => User::query()->where('account_status', 'ACTIVE')->count(),
                'Failed Notifications' => NotificationDelivery::query()->where('delivery_status', 'FAILED')->count(),
                'Active Delegations' => TemporaryDelegation::query()
                    ->where('status', 'ACTIVE')
                    ->whereNull('revoked_at')
                    ->where('effective_from', '<=', now())
                    ->where('effective_to', '>=', now())
                    ->count(),
                'Inactive Accounts' => User::query()->where('account_status', '!=', 'ACTIVE')->count(),
            ];

            $queue = User::query()
                ->with('organizationalUnit')
                ->latest()
                ->limit(6)
                ->get();
        }

        return view('dashboard', compact(
            'statistics',
            'user',
            'workspace',
            'dashboardMode',
            'queue',
            'nextCustodies',
            'latestRequest'
        ));
    }
}
